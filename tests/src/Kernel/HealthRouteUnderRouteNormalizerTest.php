<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Le point de santé répond sans redirection sous un préfixe de langue.
 *
 * Sur un site multilingue à préfixe de langue (langue par défaut comprise),
 * `GET /health` rendait `301` vers `/fr/health` : c'est le normalisateur
 * d'adresses du module `redirect` (`RouteNormalizerRequestSubscriber`,
 * `route_normalizer_enabled`, vrai dans la config livrée du module). Il
 * régénère l'adresse de la route courante, préfixe de langue compris, et
 * redirige dès qu'elle diffère de l'adresse demandée. Une sonde qui ne suit
 * pas les redirections lisait 301.
 *
 * Le normalisateur ne lit qu'une chose de la route : l'attribut de requête
 * `_disable_route_normalizer`. Ce test monte `redirect` avec sa config
 * livrée, `language` avec deux langues et un préfixe pour chacune, et joue
 * les requêtes par le noyau HTTP, script `index.php` (sans quoi
 * `RedirectChecker::canRedirect()` refuse de rediriger, et le test serait
 * vert quoi que porte la route). Un TÉMOIN, la route du module de test, sans
 * ce défaut, est redirigé : il prouve que le normalisateur est vivant ici.
 * Contre-épreuve : retirer `_disable_route_normalizer: TRUE` de
 * `healthcheck.routing.yml` rend ce test rouge (`301`, `Location` vers
 * `/en/health`).
 */
#[Group('healthcheck')]
#[RunTestsInSeparateProcesses]
class HealthRouteUnderRouteNormalizerTest extends HealthKernelTestBase {

  /**
   * Le corps d'un site sans Redis dont la base répond.
   */
  private const OK = '{"status":"ok","checks":{"database":"ok","redis":"not_configured","search_api":"not_configured","files":"ok"}}';

  /**
   * Les variables de serveur d'une requête servie par `index.php`.
   *
   * `RedirectChecker::canRedirect()` refuse toute requête dont le script
   * n'est pas `index.php` : sans elles, le normalisateur ne ferait rien.
   */
  private const INDEX_PHP = ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => '/index.php'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'field',
    'filter',
    'link',
    'views',
    'redirect',
    'language',
    'healthcheck',
    'healthcheck_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Le module `redirect` cherche une redirection enregistrée avant de
    // normaliser : sa table doit exister.
    $this->installEntitySchema('redirect');
    $this->installConfig(['redirect', 'language']);
    // Deux langues, un préfixe chacune, celle par défaut (`en` ici)
    // comprise : c'est ce préfixe qui fait différer l'adresse régénérée de
    // `/health`.
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => 'en', 'fr' => 'fr'])->save();
    // Le fournisseur de services de `language` n'enregistre le processeur de
    // chemin et l'abonné de négociation qu'à la compilation d'un conteneur
    // qui voit deux langues.
    $this->container->get('kernel')->rebuildContainer();
    $this->container->get('router.builder')->rebuild();
    $this->container->get('logger.factory')->addLogger($this->journal);
  }

  /**
   * Témoin : une route sans le défaut est redirigée vers l'adresse préfixée.
   *
   * C'est la preuve que le normalisateur est vivant dans ce test ; sans lui,
   * un 200 sur `/health` ne prouverait rien.
   */
  public function testUneRouteSansLeDefautEstRedirigee(): void {
    $response = $this->request('/healthcheck-test/cacheable', 'GET', NULL, self::INDEX_PHP);

    $this->assertSame(301, $response->getStatusCode(), 'Témoin : le normalisateur de redirect doit rediriger une route ordinaire.');
    $this->assertSame('http://localhost/en/healthcheck-test/cacheable', $response->headers->get('Location'));
    $this->assertSame('1', $response->headers->get('X-Drupal-Route-Normalizer'));
  }

  /**
   * La route répond 200 à `/health` sans préfixe, et aux formes préfixées.
   *
   * Aucun `Location`, aucune marque du normalisateur : la sonde lit le
   * verdict à l'adresse qu'on lui donne. `/en/health` et `/fr/health`
   * répondent de même.
   */
  public function testLaRouteRepondSansPrefixeDeLangueNiRedirection(): void {
    $response = $this->request('/health', 'GET', NULL, self::INDEX_PHP);

    $this->assertHealth(200, self::OK, $response, '/health');
    $this->assertFalse($response->headers->has('Location'), 'Aucune redirection : ' . (string) $response->headers->get('Location'));
    $this->assertFalse($response->headers->has('X-Drupal-Route-Normalizer'));
    $this->assertSame(1, $this->databaseChecks, 'La base est vérifiée, une fois.');

    $this->advance(30);
    $this->assertHealth(200, self::OK, $this->request('/en/health', 'GET', NULL, self::INDEX_PHP), '/en/health');
    $this->advance(30);
    $this->assertHealth(200, self::OK, $this->request('/fr/health', 'GET', NULL, self::INDEX_PHP), '/fr/health');
    $this->assertSame(3, $this->databaseChecks, 'Chaque forme, à sa fenêtre, vérifie.');
  }

}
