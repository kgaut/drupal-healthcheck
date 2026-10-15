<?php

declare(strict_types=1);

namespace Drupal\healthcheck\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\healthcheck\Attribute\HealthCheck;
use Drupal\healthcheck\CheckResult;
use Drupal\healthcheck\HealthCheckBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * La disponibilité de chaque serveur Search API actif (Solr, base…).
 *
 * `not_configured` sans le module `search_api` ou sans serveur actif.
 * Non critique par défaut : un moteur de recherche en panne dégrade le site
 * sans le rendre indisponible. Un site peut le rendre critique par
 * `healthcheck.settings:checks.search_api.critical`.
 *
 * La disponibilité est celle que dit le backend (`isAvailable()`, un `ping`
 * pour Solr) : son délai est celui du connecteur, réglé sur le serveur.
 */
#[HealthCheck(
  id: 'search_api',
  label: new TranslatableMarkup('Search API'),
  critical: FALSE,
  weight: 20,
)]
class SearchApi extends HealthCheckBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->servers() !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function check(): CheckResult {
    $down = [];
    foreach ($this->servers() as $id => $server) {
      if (!$server->isAvailable()) {
        $down[] = $id;
      }
    }

    return $down === []
      ? CheckResult::ok()
      : CheckResult::failed('serveur(s) indisponible(s) : ' . implode(', ', $down));
  }

  /**
   * Les serveurs actifs, par identifiant.
   *
   * @return array<string, \Drupal\search_api\ServerInterface>
   *   Aucun sans le module.
   */
  protected function servers(): array {
    if (!$this->moduleHandler->moduleExists('search_api')) {
      return [];
    }
    /** @var array<string, \Drupal\search_api\ServerInterface> $servers */
    $servers = $this->entityTypeManager->getStorage('search_api_server')->loadByProperties(['status' => TRUE]);
    ksort($servers);

    return $servers;
  }

}
