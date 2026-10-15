<?php

declare(strict_types=1);

namespace Drupal\healthcheck;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Une vérification du point de santé.
 *
 * Le contrat (ADR `0001-plugin-contrat-du-type-de-plugin-et-du-json`) :
 * - `applies()` dit si le site utilise le service vérifié. `FALSE` rend
 *   `not_configured`, sans appeler `check()` : une vérification qui ne
 *   s'applique pas n'est jamais `ok` ;
 * - `check()` interroge réellement le service et rend `CheckResult::ok()` ou
 *   `CheckResult::failed($cause)`. Elle PEUT lever : toute exception (et toute
 *   erreur PHP) vaut `failed`, sa classe et son message vont au journal ;
 * - la cause ne sort jamais dans le corps de `/health` : elle va au journal
 *   et dans la sortie de `drush healthcheck:run`. Elle peut donc nommer un
 *   hôte ou un chemin, jamais un secret ;
 * - `check()` est appelée au plus une fois par fenêtre (30 s par défaut) par
 *   la route, à chaque appel par la commande drush. Elle doit borner sa
 *   durée elle-même (délai de connexion court) : PHP ne sait pas
 *   l'interrompre de l'extérieur.
 */
interface HealthCheckInterface extends PluginInspectionInterface {

  /**
   * Le libellé de la vérification.
   */
  public function label(): string;

  /**
   * Le site utilise-t-il le service vérifié ?
   *
   * Ne contacte rien : lit la configuration ou les réglages du site.
   */
  public function applies(): bool;

  /**
   * Vérifie réellement le service.
   *
   * @throws \Throwable
   *   Toute exception vaut `failed`.
   */
  public function check(): CheckResult;

}
