<?php

declare(strict_types=1);

namespace Drupal\healthcheck\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\healthcheck\HealthChecker;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * La commande `drush healthcheck:run` : les vérifications, maintenant.
 *
 * Même verdict que `/health`, mais calculé à chaque appel (ni lecture ni
 * écriture du verdict gardé) et avec la cause de chaque échec : la sortie
 * est lue par un opérateur ou une CI, pas par le public. Code de sortie 1
 * si une vérification critique a échoué, 0 sinon (un `warning` ne compte
 * pas). En JSON, `critical` est un booléen ; le tableau l'affiche oui/non.
 */
final class HealthcheckCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly HealthChecker $checker,
  ) {
    parent::__construct();
  }

  /**
   * Joue les vérifications du point de santé.
   */
  #[CLI\Command(name: 'healthcheck:run', aliases: ['hcr'])]
  #[CLI\Help(description: 'Joue les vérifications du point de santé, sans le verdict gardé. Code de sortie 1 si une vérification critique échoue.')]
  #[CLI\FieldLabels(labels: [
    'check' => 'Vérification',
    'label' => 'Libellé',
    'verdict' => 'Verdict',
    'critical' => 'Critique',
    'reason' => 'Cause',
  ])]
  #[CLI\DefaultTableFields(fields: ['check', 'verdict', 'critical', 'reason'])]
  #[CLI\Usage(name: 'drush healthcheck:run', description: 'Le verdict de chaque vérification, en tableau.')]
  #[CLI\Usage(name: 'drush healthcheck:run --format=json', description: 'Le même, en JSON, pour une CI.')]
  public function run(array $options = ['format' => 'table']): CommandResult {
    $rows = [];
    $checks = [];
    // La commande ne crée rien de durable : elle tourne peut-être sous un
    // autre utilisateur que le serveur web.
    foreach ($this->checker->run(TRUE) as $outcome) {
      $checks[$outcome->id] = $outcome->verdict;
      $rows[$outcome->id] = [
        'check' => $outcome->id,
        'label' => $outcome->label,
        'verdict' => $outcome->verdict,
        'critical' => $outcome->critical,
        'reason' => $outcome->reason,
      ];
    }
    $failed = HealthChecker::globalStatus($checks) === HealthChecker::FAILED;
    $data = new RowsOfFields($rows);
    // Lisible dans le tableau, booléen dans les formats de données.
    $data->addRendererFunction(static fn(string $key, mixed $cell, FormatterOptions $options): mixed => $key === 'critical' && is_bool($cell) ? ($cell ? 'oui' : 'non') : $cell);

    return CommandResult::dataWithExitCode($data, $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS);
  }

}
