<?php

declare(strict_types=1);

namespace Drupal\healthcheck\Plugin\HealthCheck;

use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\healthcheck\Attribute\HealthCheck;
use Drupal\healthcheck\CheckResult;
use Drupal\healthcheck\HealthCheckBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Une requête réelle, `SELECT 1`, sur la connexion par défaut du site.
 *
 * Le démarrage de Drupal touche déjà la base, mais par des chemins qui ne
 * nous appartiennent pas (le cache du conteneur avale les erreurs de
 * requête) : d'où la requête explicite.
 */
#[HealthCheck(
  id: 'database',
  label: new TranslatableMarkup('Base de données'),
  critical: TRUE,
  weight: 0,
)]
class Database extends HealthCheckBase {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected Connection $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('database'));
  }

  /**
   * {@inheritdoc}
   */
  public function check(): CheckResult {
    $one = $this->connection->query('SELECT 1')->fetchField();

    return (string) $one === '1'
      ? CheckResult::ok()
      : CheckResult::failed('réponse inattendue à SELECT 1');
  }

}
