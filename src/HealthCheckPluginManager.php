<?php

declare(strict_types=1);

namespace Drupal\healthcheck;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\healthcheck\Attribute\HealthCheck;

/**
 * Le gestionnaire des vérifications `#[HealthCheck]`.
 *
 * Les définitions se modifient par `hook_healthcheck_info_alter()`.
 */
class HealthCheckPluginManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/HealthCheck', $namespaces, $module_handler, HealthCheckInterface::class, HealthCheck::class);
    $this->alterInfo('healthcheck_info');
    $this->setCacheBackend($cache_backend, 'healthcheck_plugins');
  }

}
