<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Health;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Une horloge que le test fait avancer, à la place de `datetime.time`.
 *
 * Posée dans le conteneur AVANT que quiconque ne la lise : le vérificateur
 * du point de santé (l'instant de la vérification et l'âge du verdict) et
 * le bac de cache en mémoire (l'expiration de l'entrée) lisent la MÊME
 * horloge, et avancent ensemble. Jamais de `sleep()`.
 */
final class FrozenClock implements TimeInterface {

  /**
   * L'instant courant, en secondes Unix.
   */
  public int $now;

  public function __construct(?int $now = NULL) {
    $this->now = $now ?? time();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime() {
    return (float) $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime() {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime() {
    return (float) $this->now;
  }

}
