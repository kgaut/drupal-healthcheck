<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\healthcheck\Health\FrozenClock;
use Drupal\Tests\healthcheck\Health\RecordingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Le socle des tests du point de santé `/health`.
 *
 * Chaque requête passe par le noyau HTTP (`http_kernel`, intergiciels
 * compris : cache de pages, session), jamais par un appel direct au
 * contrôleur. Chaque requête faite à la base par la vérification est
 * comptée dans le journal des requêtes de la VRAIE connexion : c'est le
 * témoin qui dit combien de vérifications ont eu lieu. L'horloge est
 * simulée (`datetime.time`), le journal de Drupal enregistré.
 */
abstract class HealthKernelTestBase extends KernelTestBase {

  /**
   * La clé du journal des requêtes de la base.
   */
  private const QUERY_LOG = 'healthcheck';

  /**
   * L'horloge du test, partagée par le vérificateur et les caches.
   */
  protected FrozenClock $clock;

  /**
   * Le journal de Drupal, tous canaux et tous niveaux.
   */
  protected RecordingLogger $journal;

  /**
   * Le nombre de `SELECT 1` passés sur la vraie connexion depuis le début.
   */
  protected int $databaseChecks = 0;

  /**
   * Le nombre de requêtes faites, pour varier l'adresse du client.
   */
  private int $requests = 0;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Même horloge à chaque reconstruction du conteneur.
    $this->clock ??= new FrozenClock();
    $container->set('datetime.time', $this->clock);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Le routage entrant consulte les alias, comme sur les sites.
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'healthcheck']);
    $this->container->get('router.builder')->rebuild();
    $this->journal = new RecordingLogger();
    $this->container->get('logger.factory')->addLogger($this->journal);
  }

  /**
   * Une requête traitée par le noyau HTTP, comme `index.php` la traiterait.
   *
   * `DrupalKernel::handle()` finit par `Response::prepare()` (une réponse
   * à HEAD y perd son corps) : on fait de même.
   *
   * @param string $path
   *   Le chemin demandé.
   * @param string $method
   *   La méthode HTTP.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface|null $session
   *   La session à attacher, le cas échéant.
   * @param array<string, mixed> $server
   *   Des variables de serveur en plus (`SCRIPT_NAME`…), qui priment sur
   *   celles posées ici.
   */
  protected function request(string $path, string $method = 'GET', ?SessionInterface $session = NULL, array $server = []): Response {
    $this->requests++;
    $request = Request::create($path, $method, [], [], [], $server + [
      'REMOTE_ADDR' => '198.51.100.' . ($this->requests % 250 + 1),
      'REQUEST_TIME' => $this->clock->now,
    ]);
    if ($session !== NULL) {
      $request->setSession($session);
    }
    // Le cache de pages retient l'identifiant de cache de la PREMIÈRE
    // requête pour toute la vie de l'objet (`PageCache::getCacheId()`) : un
    // serveur a un processus par requête, ce test un seul processus pour
    // toutes. Sans cet oubli, une seconde adresse serait lue sous la clé de
    // la première.
    if ($this->container->has('http_middleware.page_cache')) {
      $middleware = $this->container->get('http_middleware.page_cache');
      (new \ReflectionProperty($middleware, 'cid'))->setValue($middleware, NULL);
    }

    Database::startLog(self::QUERY_LOG);
    /** @var \Symfony\Component\HttpKernel\HttpKernelInterface $kernel */
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);
    $this->databaseChecks += count(array_filter(
      Database::getLog(self::QUERY_LOG),
      static fn(array $query): bool => trim((string) $query['query']) === 'SELECT 1',
    ));

    $response->prepare($request);

    return $response;
  }

  /**
   * Règle `healthcheck.settings`, comme le ferait un site.
   *
   * @param string $key
   *   La clé, par exemple `checks.search_api.critical`.
   * @param mixed $value
   *   La valeur.
   */
  protected function configure(string $key, mixed $value): void {
    $this->config('healthcheck.settings')->set($key, $value)->save();
  }

  /**
   * Vérifie que le corps ne trahit aucun des détails donnés.
   *
   * @param list<string> $details
   *   Hôtes, chemins, messages : ce qui ne doit aller qu'au journal.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   La réponse.
   */
  protected function assertNoDetail(array $details, Response $response): void {
    foreach ($details as $detail) {
      $this->assertStringNotContainsString($detail, (string) $response->getContent());
    }
  }

  /**
   * Avance l'horloge du test.
   */
  protected function advance(int $seconds): void {
    $this->clock->now += $seconds;
  }

  /**
   * Vérifie un 200 ou un 503 du point de santé, corps exact compris.
   *
   * @param int $status
   *   Le statut attendu.
   * @param string $body
   *   Le corps attendu, octet pour octet.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   La réponse.
   * @param string $message
   *   Le contexte, pour le message d'échec.
   */
  protected function assertHealth(int $status, string $body, Response $response, string $message = ''): void {
    $this->assertSame($status, $response->getStatusCode(), $message . ' ' . $response->getContent());
    $this->assertSame($body, $response->getContent(), $message);
    $this->assertSame('application/json', $response->headers->get('Content-Type'), $message);
  }

}
