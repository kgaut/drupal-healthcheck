<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Kernel;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\FormatterManager;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Drupal\healthcheck\Drush\Commands\HealthcheckCommands;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Une vérification fournie par un module tiers, et la commande drush.
 *
 * `healthcheck_probe_test` déclare `#[HealthCheck(id: 'probe')]` (poids 5),
 * comme un module métier déclarerait la vérification d'un service dont il
 * dépend. Son état pilote le résultat.
 */
#[Group('healthcheck')]
#[RunTestsInSeparateProcesses]
class HealthPluginApiTest extends HealthKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'healthcheck',
    'healthcheck_probe_test',
  ];

  /**
   * Le corps, selon le verdict de la sonde tierce.
   */
  private static function body(string $status, string $probe): string {
    return '{"status":"' . $status . '","checks":{"database":"ok","probe":"' . $probe . '","redis":"not_configured","search_api":"not_configured","files":"ok"}}';
  }

  /**
   * Pilote la sonde tierce.
   */
  private function mode(string $mode): void {
    $this->container->get('state')->set('healthcheck_probe_test.mode', $mode);
  }

  /**
   * La sonde tierce prend place dans le corps, à son poids.
   */
  public function testUneVerificationTiercePrendPlaceDansLeCorps(): void {
    $this->assertHealth(200, self::body('ok', 'ok'), $this->request('/health'));
    $this->assertSame(1, $this->container->get('state')->get('healthcheck_probe_test.calls'));
  }

  /**
   * Un échec rend `failed`, sans sa cause dans le corps.
   */
  public function testUnEchecRend503SansSaCause(): void {
    $this->mode('failed');

    $response = $this->request('/health');

    $this->assertHealth(503, self::body('failed', 'failed'), $response);
    $this->assertNoDetail(['418', 'tiers.interne'], $response);
    $this->assertStringContainsString('« probe »', $this->journal->messages()[0]);
    $this->assertStringContainsString('418', $this->journal->messages()[0]);
  }

  /**
   * Une exception vaut `failed` : la route ne tombe jamais en 500.
   */
  public function testUneExceptionVautFailed(): void {
    $this->mode('throw');

    $response = $this->request('/health');

    $this->assertHealth(503, self::body('failed', 'failed'), $response);
    $this->assertNoDetail(['RuntimeException', 'refused', 'tiers.interne', '8443'], $response);
    $this->assertStringContainsString('RuntimeException : Connection refused', $this->journal->messages()[0]);
  }

  /**
   * Une vérification qui ne s'applique pas : `not_configured`, sans appel.
   */
  public function testSansObjetLaVerificationNestPasAppelee(): void {
    $this->mode('not_applicable');

    $this->assertHealth(200, self::body('ok', 'not_configured'), $this->request('/health'));
    $this->assertSame(0, $this->container->get('state')->get('healthcheck_probe_test.calls', 0));
  }

  /**
   * Rendue non critique par le site, un échec rend `warning` et un 200.
   */
  public function testNonCritiqueParSiteUnEchecRendWarning(): void {
    $this->configure('checks.probe.critical', FALSE);
    $this->mode('failed');

    $this->assertHealth(200, self::body('ok', 'warning'), $this->request('/health'));
  }

  /**
   * Changer la criticité invalide le verdict gardé, dans la fenêtre.
   */
  public function testChangerLaCriticiteInvalideLeVerdictGarde(): void {
    $this->configure('checks.probe.critical', FALSE);
    $this->mode('failed');
    $this->assertHealth(200, self::body('ok', 'warning'), $this->request('/health'));

    $this->configure('checks.probe.critical', TRUE);
    $this->advance(1);

    $this->assertHealth(503, self::body('failed', 'failed'), $this->request('/health'));
    $this->assertSame(2, $this->container->get('state')->get('healthcheck_probe_test.calls'));
  }

  /**
   * Désactivée par le site, elle n'est ni appelée ni affichée.
   */
  public function testDesactiveeParSiteElleDisparait(): void {
    $this->configure('checks.probe.enabled', FALSE);
    $this->mode('throw');

    $this->assertHealth(200, '{"status":"ok","checks":{"database":"ok","redis":"not_configured","search_api":"not_configured","files":"ok"}}', $this->request('/health'));
    $this->assertSame(0, $this->container->get('state')->get('healthcheck_probe_test.calls', 0));
  }

  /**
   * La commande drush vérifie à chaque appel, avec la cause.
   *
   * Ni lecture ni écriture du verdict gardé : deux appels dans la même
   * seconde vérifient deux fois, et la route garde sa propre fenêtre. Code
   * de sortie 1 sur un échec critique, 0 sur un `warning`.
   */
  public function testLaCommandeDrushVerifieChaqueAppel(): void {
    $command = new HealthcheckCommands($this->container->get('healthcheck.checker'));
    $state = $this->container->get('state');
    // La commande ne crée pas le dossier du témoin : la route l'a fait.
    $directory = 'public://.healthcheck';
    $this->container->get('file_system')->prepareDirectory($directory, 1);

    $ok = $command->run();
    $this->assertSame(0, $ok->getExitCode());
    $rows = $ok->getOutputData()->getArrayCopy();
    $this->assertSame(['database', 'probe', 'redis', 'search_api', 'files'], array_keys($rows));
    $this->assertSame('ok', $rows['probe']['verdict']);

    $this->mode('failed');
    $failed = $command->run();
    $this->assertSame(1, $failed->getExitCode());
    $rows = $failed->getOutputData()->getArrayCopy();
    $this->assertSame('failed', $rows['probe']['verdict']);
    $this->assertStringContainsString('418', $rows['probe']['reason'], 'La cause est donnée à l’opérateur.');
    $this->assertSame(2, $state->get('healthcheck_probe_test.calls'), 'Une vérification par appel.');
    $this->assertFalse($this->container->get('cache.default')->get('healthcheck:verdict'), 'Rien n’est gardé pour la route.');
    $this->assertSame([], $this->journal->messages(), 'La commande affiche, elle ne journalise pas.');

    $this->configure('checks.probe.critical', FALSE);
    $warning = $command->run();
    $json = json_decode($this->formatOutput($warning, 'json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertFalse($json['probe']['critical'], 'En JSON, `critical` est un booléen.');
    $this->assertTrue($json['database']['critical']);
    $table = $this->formatOutput($warning, 'table');
    $this->assertMatchesRegularExpression('/probe\s+warning\s+non/', $table, 'Le tableau affiche oui/non.');
    $this->assertMatchesRegularExpression('/database\s+ok\s+oui/', $table);
    $this->assertSame(0, $warning->getExitCode(), 'Un warning ne fait pas échouer la commande.');
    $this->assertSame('warning', $warning->getOutputData()->getArrayCopy()['probe']['verdict']);
  }

  /**
   * Le rendu d'un résultat de commande, comme drush l'écrirait.
   */
  private function formatOutput(CommandResult $result, string $format): string {
    $formatters = new FormatterManager();
    $formatters->addDefaultFormatters();
    $formatters->addDefaultSimplifiers();
    $output = new BufferedOutput();
    $options = new FormatterOptions([
      'field-labels' => [
        'check' => 'Vérification',
        'verdict' => 'Verdict',
        'critical' => 'Critique',
        'reason' => 'Cause',
      ],
    ], []);
    $options->setOption('fields', 'check,verdict,critical,reason');
    $formatters->write($output, $format, $result->getOutputData(), $options);

    return $output->fetch();
  }

}
