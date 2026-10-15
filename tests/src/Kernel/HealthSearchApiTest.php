<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Kernel;

use Drupal\search_api\Entity\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La vérification `search_api` : chaque serveur actif, non critique.
 *
 * Le serveur de test de Search API (`search_api_test`) dit sa disponibilité
 * selon l'état `search_api_test.backend.return.isAvailable` : c'est la
 * méthode qu'un backend Solr implémente par un `ping`.
 */
#[Group('healthcheck')]
#[RunTestsInSeparateProcesses]
class HealthSearchApiTest extends HealthKernelTestBase {

  /**
   * Le corps quand le serveur répond.
   */
  private const OK = '{"status":"ok","checks":{"database":"ok","redis":"not_configured","search_api":"ok","files":"ok"}}';

  /**
   * Le corps quand le serveur ne répond plus : 200 quand même.
   */
  private const WARNING = '{"status":"ok","checks":{"database":"ok","redis":"not_configured","search_api":"warning","files":"ok"}}';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'search_api',
    'search_api_test',
    'healthcheck',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api']);
    Server::create([
      'id' => 'solr_principal',
      'name' => 'Solr principal',
      'backend' => 'search_api_test',
      'status' => TRUE,
    ])->save();
  }

  /**
   * Rend le serveur de test disponible ou non.
   */
  private function available(bool $available): void {
    $this->container->get('state')->set('search_api_test.backend.return.isAvailable', $available);
  }

  /**
   * Un serveur qui répond : `ok`.
   */
  public function testUnServeurQuiRepondRendOk(): void {
    $this->assertHealth(200, self::OK, $this->request('/health'));
    $this->assertSame([], $this->journal->messages());
  }

  /**
   * Un serveur qui ne répond plus : `warning`, et le site reste en 200.
   *
   * Non critique par défaut : un moteur de recherche en panne dégrade le
   * site sans le rendre indisponible. L'échec se journalise en
   * avertissement, avec le serveur en cause, absent du corps.
   */
  public function testUnServeurEnPanneRendWarningSans503(): void {
    $this->available(FALSE);

    $response = $this->request('/health');

    $this->assertHealth(200, self::WARNING, $response);
    $this->assertNoDetail(['solr_principal'], $response);
    $this->assertCount(1, $this->journal->records);
    $this->assertSame('warning', $this->levelName($this->journal->records[0]['level']));
    $this->assertSame('healthcheck.check_warning', $this->journal->records[0]['context']['event']);
    $this->assertSame('search_api', $this->journal->records[0]['context']['check']);
    $this->assertStringContainsString('solr_principal', $this->journal->messages()[0]);
  }

  /**
   * Rendu critique par le site, le même échec fait tomber la route.
   */
  public function testCritiqueParSiteUnServeurEnPanneRend503(): void {
    $this->configure('checks.search_api.critical', TRUE);
    $this->available(FALSE);

    $this->assertHealth(503, '{"status":"failed","checks":{"database":"ok","redis":"not_configured","search_api":"failed","files":"ok"}}', $this->request('/health'));
    $this->assertSame('error', $this->levelName($this->journal->records[0]['level']));
  }

  /**
   * Seuls les serveurs actifs comptent ; sans aucun : `not_configured`.
   */
  public function testSansServeurActifRendNotConfigured(): void {
    Server::load('solr_principal')->disable()->save();
    $this->available(FALSE);

    $this->assertHealth(200, '{"status":"ok","checks":{"database":"ok","redis":"not_configured","search_api":"not_configured","files":"ok"}}', $this->request('/health'));
  }

  /**
   * Le nom d'un niveau RFC 5424 de journal.
   */
  private function levelName(mixed $level): string {
    return match ($level) {
      3, 'error' => 'error',
      4, 'warning' => 'warning',
      default => (string) $level,
    };
  }

}
