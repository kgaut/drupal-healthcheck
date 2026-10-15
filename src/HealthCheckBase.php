<?php

declare(strict_types=1);

namespace Drupal\healthcheck;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * La base d'une vérification : s'applique toujours, libellé de l'attribut.
 *
 * Une vérification qui a besoin de services surcharge `create()`.
 */
abstract class HealthCheckBase extends PluginBase implements HealthCheckInterface, ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) ($this->pluginDefinition['label'] ?? $this->pluginId);
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return TRUE;
  }

}
