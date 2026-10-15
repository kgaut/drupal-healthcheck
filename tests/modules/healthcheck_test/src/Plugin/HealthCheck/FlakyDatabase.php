<?php

declare(strict_types=1);

namespace Drupal\healthcheck_test\Plugin\HealthCheck;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\State\StateInterface;
use Drupal\healthcheck\CheckResult;
use Drupal\healthcheck\Plugin\HealthCheck\Database;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * La vérification `database`, qu'un test met en panne.
 *
 * Pas d'attribut : ce n'est pas un plugin à part, mais la classe que
 * `hook_healthcheck_info_alter()` donne à `database`.
 */
class FlakyDatabase extends Database {

  /**
   * L'état, qui porte la panne demandée par le test.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function check(): CheckResult {
    if ($this->state->get('healthcheck_test.database_down', FALSE)) {
      // Ce que lève le pilote quand le serveur ne répond plus.
      throw new DatabaseExceptionWrapper('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (db.interne:3306)');
    }

    return parent::check();
  }

}
