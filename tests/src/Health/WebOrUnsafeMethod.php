<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Health;

use Drupal\Core\PageCache\RequestPolicy\CommandLineOrUnsafeMethod;

/**
 * La politique de requête des caches de pages, comme hors ligne de commande.
 *
 * `CommandLineOrUnsafeMethod` refuse tout cache quand PHP tourne en ligne
 * de commande : sous PHPUnit, le cache de pages et le cache dynamique ne
 * stockeraient donc jamais rien, et un test « la route n'est pas mise en
 * cache » serait vert quoi que fasse la route. Cette variante garde le
 * refus des méthodes non sûres (POST…) et oublie la ligne de commande.
 */
final class WebOrUnsafeMethod extends CommandLineOrUnsafeMethod {

  /**
   * {@inheritdoc}
   */
  protected function isCli() {
    return FALSE;
  }

}
