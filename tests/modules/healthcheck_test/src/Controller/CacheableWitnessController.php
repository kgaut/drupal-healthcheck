<?php

declare(strict_types=1);

namespace Drupal\healthcheck_test\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;

/**
 * Le témoin des caches de pages : une réponse cacheable, rien d'autre.
 */
final class CacheableWitnessController {

  /**
   * Une réponse JSON cacheable, que les deux caches de pages stockent.
   */
  public function witness(): CacheableJsonResponse {
    return new CacheableJsonResponse(['witness' => TRUE]);
  }

}
