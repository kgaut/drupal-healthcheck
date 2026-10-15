<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\PageCache\ChainRequestPolicy;
use Drupal\Core\PageCache\RequestPolicy\NoSessionOpen;
use Drupal\Tests\healthcheck\Health\WebOrUnsafeMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Le point de santé `/health` sur un site sans Redis ni Search API.
 *
 * Le cache de pages et le cache dynamique sont actifs, et leur politique de
 * requête est rendue à celle d'un serveur web : sous PHPUnit (ligne de
 * commande), le cœur les éteint, et un test « la route n'est pas mise en
 * cache » serait vert quoi que fasse la route. Un témoin prouve qu'ils
 * stockent bien une réponse cacheable.
 */
#[Group('healthcheck')]
#[RunTestsInSeparateProcesses]
class HealthRouteTest extends HealthKernelTestBase {

  /**
   * Le corps d'un site sans Redis ni Search API dont la base répond.
   */
  private const OK = '{"status":"ok","checks":{"database":"ok","redis":"not_configured","search_api":"not_configured","files":"ok"}}';

  /**
   * Le corps du même site, base en panne.
   */
  private const DATABASE_FAILED = '{"status":"failed","checks":{"database":"failed","redis":"not_configured","search_api":"not_configured","files":"ok"}}';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'page_cache',
    'dynamic_page_cache',
    'healthcheck',
    'healthcheck_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Les politiques de requête des deux caches, sans le refus « ligne de
    // commande » : ce que voit une requête servie par PHP-FPM.
    $container->getDefinition('page_cache_request_policy')
      ->setClass(ChainRequestPolicy::class)
      ->setArguments([])
      ->addMethodCall('addPolicy', [new Definition(WebOrUnsafeMethod::class)])
      ->addMethodCall('addPolicy', [new Definition(NoSessionOpen::class, [new Reference('session_configuration')])]);
    $container->getDefinition('dynamic_page_cache_request_policy')
      ->setClass(ChainRequestPolicy::class)
      ->setArguments([])
      ->addMethodCall('addPolicy', [new Definition(WebOrUnsafeMethod::class)]);
  }

  /**
   * Un anonyme reçoit 200 et le verdict en JSON, que nul ne doit garder.
   *
   * La base est vérifiée par une vraie requête ; Redis et Search API, que le
   * site n'a pas, disent `not_configured`, jamais `ok`. `Cache-Control:
   * no-store` : ni `public` ni `max-age` positif. HEAD rend le même statut,
   * sans corps.
   */
  public function testUnAnonymeRecoitLeVerdictEnJson(): void {
    $response = $this->request('/health');

    $this->assertHealth(200, self::OK, $response);
    $this->assertSame(1, $this->databaseChecks, 'La base est vérifiée par une requête réelle, une fois.');
    $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
    $this->assertContains('no-store', $directives);
    $this->assertNotContains('public', $directives);
    $this->assertSame([], preg_grep('/^(s-)?max-age=[1-9]/', $directives), 'Aucun max-age positif.');

    $head = $this->request('/health', 'HEAD');
    $this->assertSame(200, $head->getStatusCode());
    $this->assertSame('', $head->getContent(), 'HEAD : le statut, sans corps.');
    $this->assertContains('no-store', array_map('trim', explode(',', (string) $head->headers->get('Cache-Control'))));
  }

  /**
   * POST est refusé en 405, sans vérification.
   */
  public function testUnPostEstRefuse(): void {
    $response = $this->request('/health', 'POST');

    $this->assertSame(405, $response->getStatusCode());
    $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
    $this->assertSame(0, $this->databaseChecks, 'Un refus ne vérifie rien.');
  }

  /**
   * Un appel réussi n'ouvre aucune session et n'écrit rien au journal.
   *
   * Deux appels : le premier vérifie, le second lit le verdict gardé.
   */
  public function testUnAppelReussiSansSessionNiJournal(): void {
    $session = new Session(new MockArraySessionStorage());

    $this->assertHealth(200, self::OK, $this->request('/health', 'GET', $session));
    $this->advance(5);
    $this->assertHealth(200, self::OK, $this->request('/health', 'GET', $session));

    $this->assertFalse($session->isStarted(), 'Aucune session ouverte pour l’anonyme.');
    $this->assertSame([], $this->journal->messages(), 'Aucune entrée de journal pour un appel réussi.');
  }

  /**
   * En maintenance, l'anonyme reçoit le 503 du cœur, sans vérification.
   *
   * La route ne s'exempte pas (`_maintenance_access`) : c'est le
   * comportement voulu, la sonde lit « indisponible ».
   */
  public function testEnMaintenanceUnAnonymeRecoit503(): void {
    $this->container->get('state')->set('system.maintenance_mode', TRUE);

    $response = $this->request('/health');

    $this->assertSame(503, $response->getStatusCode());
    $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    $this->assertStringNotContainsString('"status":"ok"', (string) $response->getContent());
    $this->assertSame(0, $this->databaseChecks, 'Le cœur répond avant la route.');
  }

  /**
   * Le verdict est gardé 30 s depuis la vérification, sans jamais glisser.
   *
   * Une lecture du verdict gardé ne le renouvelle pas : à t0 + 30, on
   * vérifie à nouveau, même si t0 + 29 l'a lu. Une adresse variée (`?…`)
   * partage le verdict : une rafale à adresses aléatoires ne force rien.
   */
  public function testLeVerdictEstGardeTrenteSecondesSansGlisser(): void {
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0');
    $this->assertSame(1, $this->databaseChecks, 't0 : vérifie.');

    $this->advance(10);
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 + 10');
    $this->assertHealth(200, self::OK, $this->request('/health?rafale=1'), 't0 + 10, adresse variée');
    $this->advance(19);
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 + 29');
    $this->assertSame(1, $this->databaseChecks, 'Dans la fenêtre : aucune nouvelle vérification.');

    $this->advance(1);
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 + 30');
    $this->assertSame(2, $this->databaseChecks, 't0 + 30 : la fenêtre part de t0, pas de la dernière lecture.');

    $this->advance(29);
    $this->request('/health');
    $this->assertSame(2, $this->databaseChecks, 't0 + 59 : verdict de t0 + 30.');
    $this->advance(1);
    $this->request('/health');
    $this->assertSame(3, $this->databaseChecks, 't0 + 60 : vérifie.');
  }

  /**
   * La durée de la fenêtre se règle par site.
   */
  public function testLaFenetreSeRegleParSite(): void {
    $this->configure('window', 10);

    $this->request('/health');
    $this->advance(9);
    $this->request('/health');
    $this->assertSame(1, $this->databaseChecks, 't0 + 9 : dans la fenêtre de 10 s.');
    $this->advance(1);
    $this->request('/health');
    $this->assertSame(2, $this->databaseChecks, 't0 + 10 : vérifie.');
  }

  /**
   * Ni le cache de pages ni le cache dynamique ne servent la route.
   *
   * Un cache de pages ignore `max-age` : s'il gardait la réponse, il la
   * servirait au-delà de la fenêtre. Le témoin prouve que les deux caches
   * stockent une réponse cacheable dans ce test ; la route, elle, est
   * recalculée à t0 + 30 (même adresse, cache de pages) et à t0 + 60
   * (autre adresse, cache dynamique).
   */
  public function testLesCachesDePagesNeServentJamaisLaRoute(): void {
    $witness = '/healthcheck-test/cacheable';
    $this->assertSame('MISS', $this->request($witness)->headers->get('X-Drupal-Cache'), 'Témoin, premier appel.');
    $this->assertSame('HIT', $this->request($witness)->headers->get('X-Drupal-Cache'), 'Témoin : le cache de pages est vivant.');
    $variant = $this->request($witness . '?variante=1');
    $this->assertSame('MISS', $variant->headers->get('X-Drupal-Cache'), 'Témoin, autre adresse.');
    $this->assertSame('HIT', $variant->headers->get('X-Drupal-Dynamic-Cache'), 'Témoin : le cache dynamique est vivant.');

    $responses = [$this->request('/health')];
    $this->advance(30);
    $responses[] = $this->request('/health');
    $this->assertSame(2, $this->databaseChecks, 'Même adresse, fenêtre échue : vérifiée à nouveau.');
    $this->advance(30);
    $responses[] = $this->request('/health?variante=1');
    $this->assertSame(3, $this->databaseChecks, 'Autre adresse, fenêtre échue : vérifiée à nouveau.');

    foreach ($responses as $i => $response) {
      $this->assertHealth(200, self::OK, $response, "Appel $i.");
      $this->assertNotSame('HIT', $response->headers->get('X-Drupal-Cache'), "Appel $i : cache de pages.");
      $this->assertNotSame('HIT', $response->headers->get('X-Drupal-Dynamic-Cache'), "Appel $i : cache dynamique.");
    }
  }

  /**
   * Une base qui ne répond plus : 503, sans détail, et un verdict gardé.
   *
   * Le `503` est gardé la même fenêtre qu'un `200` : une rafale pendant la
   * panne ne relance pas une vérification par requête, et le retour se voit
   * au plus 30 s après la dernière vérification.
   */
  public function testUneBaseEnPanneRend503(): void {
    $this->container->get('state')->set('healthcheck_test.database_down', TRUE);

    $response = $this->request('/health');
    $this->assertHealth(503, self::DATABASE_FAILED, $response, 't0, base en panne');
    $this->assertNoDetail(['db.interne', '3306', 'gone away', 'DatabaseExceptionWrapper'], $response);
    $messages = $this->journal->messages();
    $this->assertCount(1, $messages, 'Une vérification échouée se journalise, une fois.');
    $this->assertStringContainsString('« database »', $messages[0]);
    $context = $this->journal->records[0]['context'];
    $this->assertSame('healthcheck.check_failed', $context['event'], 'Un champ event plat, pour filtrer.');
    $this->assertSame('database', $context['check']);
    $this->assertStringContainsString('gone away', $context['reason']);
    $this->assertStringContainsString('gone away', $messages[0], 'La cause va au journal.');

    $this->container->get('state')->set('healthcheck_test.database_down', FALSE);
    $this->advance(10);
    $this->assertHealth(503, self::DATABASE_FAILED, $this->request('/health'), 't0 + 10 : verdict gardé');
    $this->assertSame(0, $this->databaseChecks, 'Aucune requête dans la fenêtre.');

    $this->advance(20);
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 + 30 : la base est revenue');
    $this->assertSame(1, $this->databaseChecks);
    $this->assertCount(1, $this->journal->messages(), 'Le retour ne se journalise pas.');
  }

  /**
   * Une connexion Redis déclarée sans le module `redis` : `failed`.
   *
   * Jamais `not_configured` : le site croit utiliser Redis sans pouvoir
   * l'atteindre.
   */
  public function testUneConnexionRedisDeclareeSansLeModuleEchoue(): void {
    $this->setSetting('redis.connection', ['host' => 'redis-de-test', 'port' => 6380]);

    $response = $this->request('/health');

    $this->assertHealth(503, '{"status":"failed","checks":{"database":"ok","redis":"failed","search_api":"not_configured","files":"ok"}}', $response);
    $this->assertNoDetail(['redis-de-test', '6380'], $response);
    $this->assertCount(1, $this->journal->messages());
  }

  /**
   * Aucune vérification active : 503, et une entrée au journal.
   *
   * Un point de santé qui ne vérifie rien ne prouve rien.
   */
  public function testAucuneVerificationActiveRend503(): void {
    $this->configure('checks', [
      'database' => ['enabled' => FALSE],
      'redis' => ['enabled' => FALSE],
      'search_api' => ['enabled' => FALSE],
      'files' => ['enabled' => FALSE],
    ]);

    $this->assertHealth(503, '{"status":"failed","checks":{}}', $this->request('/health'));
    $this->assertStringContainsString('aucune vérification active', $this->journal->messages()[0]);
    $this->assertSame('healthcheck.no_active_check', $this->journal->records[0]['context']['event']);
  }

  /**
   * Une vérification désactivée par site disparaît du corps.
   *
   * Un verdict gardé sous l'ancienne liste ne vaut plus : la liste change,
   * tout est revérifié.
   */
  public function testUneVerificationDesactiveeDisparaitDuCorps(): void {
    $this->assertHealth(200, self::OK, $this->request('/health'));

    $this->configure('checks', ['files' => ['enabled' => FALSE], 'search_api' => ['enabled' => FALSE]]);
    $this->advance(1);

    $this->assertHealth(200, '{"status":"ok","checks":{"database":"ok","redis":"not_configured"}}', $this->request('/health'));
    $this->assertSame(2, $this->databaseChecks, 'Liste changée : revérifiée dans la fenêtre.');
  }

}
