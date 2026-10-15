<?php

declare(strict_types=1);

namespace Drupal\healthcheck;

/**
 * Le résultat d'une vérification qui s'applique : réussie, ou échouée.
 *
 * La cause d'un échec va au journal et à la commande drush, jamais dans le
 * corps de `/health`.
 */
final class CheckResult {

  private function __construct(
    public readonly bool $ok,
    public readonly string $reason,
  ) {}

  /**
   * Le service a répondu comme attendu.
   */
  public static function ok(): self {
    return new self(TRUE, '');
  }

  /**
   * Le service n'a pas répondu comme attendu.
   *
   * @param string $reason
   *   La cause, pour le journal : jamais un secret.
   */
  public static function failed(string $reason): self {
    return new self(FALSE, $reason);
  }

}
