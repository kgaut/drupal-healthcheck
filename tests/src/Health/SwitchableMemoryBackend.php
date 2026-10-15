<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Health;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\MemoryBackend;

/**
 * Un bac de cache en mémoire qu'un test met en panne, comme un Redis tombé.
 *
 * En panne, lecture et écriture lèvent, comme le fait le backend Redis du
 * module `redis`, qui ne rattrape pas les exceptions de son client.
 */
final class SwitchableMemoryBackend extends MemoryBackend {

  /**
   * Le magasin est en panne.
   */
  public bool $down = FALSE;

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    $this->failIfDown();

    return parent::get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $this->failIfDown();

    return parent::getMultiple($cids, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->failIfDown();

    parent::set($cid, $data, $expire, $tags);
  }

  /**
   * Lève comme un client Redis dont le serveur ne répond plus.
   */
  private function failIfDown(): void {
    if ($this->down) {
      throw new \RuntimeException('Connection refused');
    }
  }

}
