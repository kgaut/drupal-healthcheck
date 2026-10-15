<?php

declare(strict_types=1);

namespace Drupal\healthcheck\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\healthcheck\HealthChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /health : le point de santé, pour une sonde de disponibilité.
 *
 * `200` quand aucune vérification critique n'a échoué, `503` sinon, avec le
 * verdict de chaque vérification et rien d'autre : l'adresse est publique.
 *
 * La réponse est une `JsonResponse` de Symfony, pas une réponse cacheable
 * de Drupal : le cache de pages et le cache dynamique ne stockent que les
 * réponses cacheables, et un cache de pages ne connaît pas la fenêtre du
 * vérificateur (il ignore `max-age`). `Cache-Control: no-store` est gardé
 * tel quel par le cœur, qui ne remplace l'en-tête que s'il n'a pas été posé.
 */
final class HealthController implements ContainerInjectionInterface {

  public function __construct(
    private readonly HealthChecker $checker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('healthcheck.checker'),
    );
  }

  /**
   * L'état du site, en JSON.
   */
  public function health(): JsonResponse {
    $status = $this->checker->status();

    return new JsonResponse(
      // Un objet, même vide : `"checks":{}` et non `"checks":[]`.
      ['status' => $status['status'], 'checks' => (object) $status['checks']],
      $status['status'] === HealthChecker::OK ? 200 : 503,
      ['Cache-Control' => 'no-store'],
    );
  }

}
