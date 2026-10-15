<?php

declare(strict_types=1);

namespace Drupal\healthcheck\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Déclare une vérification du point de santé.
 *
 * La classe porteuse vit dans `src/Plugin/HealthCheck/` d'un module et
 * implémente `HealthCheckInterface` (le plus simple : étendre
 * `HealthCheckBase`).
 *
 * @see \Drupal\healthcheck\HealthCheckInterface
 * @see docs/dev/ecrire-une-verification.md
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class HealthCheck extends Plugin {

  /**
   * Construit l'attribut.
   *
   * @param string $id
   *   L'identifiant : la clé de la vérification dans le corps de `/health`.
   *   Minuscules ASCII, chiffres et `_` ; il fait partie du contrat lu par
   *   les sondes, ne le renommez pas.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   Le libellé, pour la commande drush et le journal.
   * @param bool $critical
   *   Un échec fait-il tomber le point de santé en 503 ? Sinon, il rend
   *   `warning` et la réponse reste 200. Réglable par site
   *   (`healthcheck.settings:checks.<id>.critical`).
   * @param int $weight
   *   L'ordre dans le corps de la réponse, croissant (puis l'identifiant).
   * @param class-string|null $deriver
   *   Le dériveur, le cas échéant.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly bool $critical = TRUE,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
