<?php

declare(strict_types=1);

namespace Drupal\healthcheck;

/**
 * Le verdict d'une vérification jouée, avec ce qu'en sait l'opérateur.
 *
 * Seuls l'identifiant et le verdict sortent dans le corps de `/health`.
 */
final class CheckOutcome {

  public function __construct(
    public readonly string $id,
    public readonly string $label,
    public readonly bool $critical,
    public readonly string $verdict,
    public readonly string $reason = '',
  ) {}

}
