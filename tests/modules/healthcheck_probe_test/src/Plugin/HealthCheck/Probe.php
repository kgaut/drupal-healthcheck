<?php

declare(strict_types=1);

namespace Drupal\healthcheck_probe_test\Plugin\HealthCheck;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\healthcheck\Attribute\HealthCheck;
use Drupal\healthcheck\CheckResult;
use Drupal\healthcheck\HealthCheckBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Une vérification tierce, comme celle qu'un module métier fournirait.
 *
 * L'état `healthcheck_probe_test.mode` la pilote : `ok` (par défaut),
 * `failed`, `throw` ou `not_applicable`. Chaque appel à `check()` est compté
 * dans `healthcheck_probe_test.calls`. Poids 5 : entre `database` et
 * `redis` dans le corps.
 */
#[HealthCheck(
  id: 'probe',
  label: new TranslatableMarkup('Sonde de test'),
  critical: TRUE,
  weight: 5,
)]
class Probe extends HealthCheckBase {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected StateInterface $state) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('state'));
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->mode() !== 'not_applicable';
  }

  /**
   * {@inheritdoc}
   */
  public function check(): CheckResult {
    $this->state->set('healthcheck_probe_test.calls', $this->state->get('healthcheck_probe_test.calls', 0) + 1);

    return match ($this->mode()) {
      'failed' => CheckResult::failed('le service tiers répond 418 sur https://tiers.interne/ping'),
      'throw' => throw new \RuntimeException('Connection refused [tcp://tiers.interne:8443]'),
      default => CheckResult::ok(),
    };
  }

  /**
   * Le mode demandé par le test.
   */
  private function mode(): string {
    return (string) $this->state->get('healthcheck_probe_test.mode', 'ok');
  }

}
