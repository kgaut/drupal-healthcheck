<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Kernel;

use Drupal\Tests\healthcheck\Health\FakeRedis;
use Drupal\Tests\healthcheck\Health\SwitchableMemoryBackend;
use Drupal\redis\ClientFactory;
use Drupal\healthcheck\HealthChecker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Le point de santé `/health` sur un site à Redis.
 *
 * Le module `redis` est actif et sa VRAIE fabrique (`redis.factory`) sert
 * le client : seul le transport est un double (`FakeRedis`), posé comme
 * mandataire par `ClientFactory::setClient()`. La connexion du site est
 * déclarée comme un `settings.php` la déclare, par `redis.connection`.
 */
#[Group('healthcheck')]
#[RunTestsInSeparateProcesses]
class HealthRedisTest extends HealthKernelTestBase {

  /**
   * Le corps quand la base et Redis répondent.
   */
  private const OK = '{"status":"ok","checks":{"database":"ok","redis":"ok","search_api":"not_configured","files":"ok"}}';

  /**
   * Le corps quand Redis ne répond plus.
   */
  private const REDIS_FAILED = '{"status":"failed","checks":{"database":"ok","redis":"failed","search_api":"not_configured","files":"ok"}}';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'redis',
    'healthcheck',
  ];

  /**
   * Le transport Redis du test.
   */
  private FakeRedis $redis;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ClientFactory::reset();
    $this->redis = new FakeRedis();
    ClientFactory::setClient($this->redis);
    $this->setSetting('redis.connection', [
      'host' => 'redis-de-test',
      'port' => 6380,
      'interface' => 'PhpRedis',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    ClientFactory::reset();
    parent::tearDown();
  }

  /**
   * Redis répond : `ok`, par la connexion que le site déclare.
   *
   * Les formes de `PONG` de chaque client sont reconnues ; toute autre
   * réponse est un échec.
   */
  public function testRedisQuiRepondRendOk(): void {
    $this->assertHealth(200, self::OK, $this->request('/health'));
    $this->assertSame([['host' => 'redis-de-test', 'port' => 6380]], $this->redis->connections, 'La fabrique du module, avec la connexion du site.');
    $this->assertSame(1, $this->redis->pings);
    $this->assertSame(1, $this->databaseChecks);

    $pong = new class() implements \Stringable {

      /**
       * Comme un statut Predis.
       */
      public function __toString(): string {
        return 'PONG';
      }

    };
    $replies = [
      'PhpRedis < 5' => ['+PONG', 200],
      'Predis' => [$pong, 200],
      'faux' => [FALSE, 503],
      'autre chaîne' => ['NOPE', 503],
      'rien' => [NULL, 503],
    ];
    foreach ($replies as $label => [$reply, $status]) {
      $this->advance(HealthChecker::DEFAULT_WINDOW);
      $this->redis->reply = $reply;
      $response = $this->request('/health');
      $this->assertHealth($status, $status === 200 ? self::OK : self::REDIS_FAILED, $response, $label);
    }
    $this->assertSame(6, $this->redis->pings);
  }

  /**
   * Redis ne répond plus : 503, sans hôte, port ni message d'exception.
   */
  public function testRedisQuiNeRepondPlusRend503(): void {
    $this->redis->failure = new \RuntimeException('Connection refused [tcp://redis-de-test:6380]');

    $response = $this->request('/health');

    $this->assertHealth(503, self::REDIS_FAILED, $response);
    foreach (['redis-de-test', '6380', 'refused', 'RuntimeException'] as $detail) {
      $this->assertStringNotContainsString($detail, (string) $response->getContent());
    }
    $messages = $this->journal->messages();
    $this->assertCount(1, $messages, 'L’échec se journalise, une fois.');
    $this->assertStringContainsString('« redis »', $messages[0]);
  }

  /**
   * Le module `redis` actif sans connexion déclarée : rien n'est contacté.
   *
   * La fabrique se rabattrait sur 127.0.0.1:6379 : elle n'est pas appelée,
   * et Redis dit `not_configured`, jamais `ok`.
   */
  public function testSansConnexionDeclareeRienContacte(): void {
    $this->setSetting('redis.connection', []);

    $response = $this->request('/health');

    $this->assertHealth(200, '{"status":"ok","checks":{"database":"ok","redis":"not_configured","search_api":"not_configured","files":"ok"}}', $response);
    $this->assertSame([], $this->redis->connections, 'Aucune connexion demandée, donc aucun paramètre deviné.');
    $this->assertSame(0, $this->redis->pings);
  }

  /**
   * Un bac de cache Redis sans hôte déclaré : Redis est vérifié.
   *
   * Le site utilise alors l'hôte par défaut de la fabrique : c'est celui-là
   * qu'il faut vérifier, et `not_configured` mentirait.
   */
  public function testUnBacDeCacheRedisSansHoteEstVerifie(): void {
    $this->setSetting('redis.connection', ['interface' => 'PhpRedis']);
    $this->setSetting('cache', ['default' => 'cache.backend.redis']);

    $this->assertHealth(200, self::OK, $this->request('/health'));
    $this->assertSame(1, $this->redis->pings);
  }

  /**
   * Un seul bac de cache Redis (`cache.bins`), sans hôte : Redis est vérifié.
   */
  public function testUnBacParticulierEnRedisEstVerifie(): void {
    $this->setSetting('redis.connection', ['interface' => 'PhpRedis']);
    $this->setSetting('cache', ['bins' => ['render' => 'cache.backend.redis']]);

    $this->assertHealth(200, self::OK, $this->request('/health'));
    $this->assertSame(1, $this->redis->pings);
  }

  /**
   * Une panne de Redis se voit au plus tard 30 s après la vérification.
   *
   * Dans la fenêtre, le verdict gardé (dans un magasin qui répond, ici le
   * bac en mémoire du test) reste `ok` : c'est le prix de la fenêtre.
   */
  public function testUnePanneDeRedisSeVoitAuPlusTardTrenteSecondesApres(): void {
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0');
    $this->redis->failure = new \RuntimeException('Connection refused');

    $this->advance(1);
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 + 1 : verdict gardé');
    $this->advance(28);
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 + 29 : verdict gardé');
    $this->advance(1);
    $this->assertHealth(503, self::REDIS_FAILED, $this->request('/health'), 't0 + 30 : la panne se voit');
    $this->assertSame(2, $this->redis->pings);
  }

  /**
   * Un `ok` n'est jamais servi depuis un magasin en panne.
   *
   * Sur un site à Redis, le verdict est souvent gardé dans Redis (le bac
   * par défaut) : quand Redis tombe, le magasin tombe avec lui. Sa lecture
   * qui lève ne vaut pas verdict : tout est revérifié, et dit 503, à chaque
   * requête tant que le magasin ne peut rien garder.
   */
  public function testJamaisDeOkDepuisUnMagasinEnPanne(): void {
    $store = new SwitchableMemoryBackend($this->clock);
    $this->container->set('healthcheck.checker', new HealthChecker(
      $this->container->get('plugin.manager.healthcheck'),
      $this->container->get('config.factory'),
      $store,
      $this->clock,
      $this->container->get('logger.channel.healthcheck'),
    ));

    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 : verdict gardé dans le magasin');
    $this->assertNotFalse($store->get(HealthChecker::CID), 'Le verdict est bien dans le magasin.');

    $this->redis->failure = new \RuntimeException('Connection refused');
    $store->down = TRUE;
    $this->advance(5);
    $this->assertHealth(503, self::REDIS_FAILED, $this->request('/health'), 't0 + 5 : Redis et son magasin tombés');
    $this->advance(1);
    $this->assertHealth(503, self::REDIS_FAILED, $this->request('/health'), 't0 + 6 : rien n’a pu être gardé');
    $this->assertSame(3, $this->redis->pings, 'Magasin en panne : une vérification par requête.');
    $this->assertSame(3, $this->databaseChecks);
  }

}
