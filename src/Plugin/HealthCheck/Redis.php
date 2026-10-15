<?php

declare(strict_types=1);

namespace Drupal\healthcheck\Plugin\HealthCheck;

use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\healthcheck\Attribute\HealthCheck;
use Drupal\healthcheck\CheckResult;
use Drupal\healthcheck\HealthCheckBase;
use Drupal\redis\ClientFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Un `PING` sur le client Redis du site, obtenu par la fabrique du module.
 *
 * Le site utilise Redis s'il déclare un hôte (`redis.connection`) ou un bac
 * de cache Redis (`cache.default`, `cache.bins`). Sinon : `not_configured`,
 * et la fabrique n'est JAMAIS appelée, car elle se rabattrait sur
 * 127.0.0.1:6379 et vérifierait un Redis que le site n'utilise pas.
 *
 * Une connexion déclarée sans le module `redis` : `failed`, le site croit
 * utiliser Redis sans pouvoir l'atteindre.
 */
#[HealthCheck(
  id: 'redis',
  label: new TranslatableMarkup('Redis'),
  critical: TRUE,
  weight: 10,
)]
class Redis extends HealthCheckBase {

  /**
   * Le service de cache du module `redis`.
   */
  protected const CACHE_SERVICE = 'cache.backend.redis';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected ?ClientFactory $factory) {
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
      $container->has('redis.factory') ? $container->get('redis.factory') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    $connection = Settings::get('redis.connection', []);
    if (is_array($connection) && !empty($connection['host'])) {
      return TRUE;
    }
    $cache = Settings::get('cache', []);
    if (!is_array($cache)) {
      return FALSE;
    }
    $services = [$cache['default'] ?? NULL];
    if (isset($cache['bins']) && is_array($cache['bins'])) {
      $services = array_merge($services, array_values($cache['bins']));
    }

    return in_array(self::CACHE_SERVICE, $services, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function check(): CheckResult {
    if ($this->factory === NULL) {
      return CheckResult::failed('connexion Redis déclarée sans le module redis');
    }
    $reply = $this->factory->getClient()->ping();

    return self::isPong($reply)
      ? CheckResult::ok()
      : CheckResult::failed('réponse inattendue à PING');
  }

  /**
   * La réponse est-elle celle d'un `PING` réussi ?
   *
   * Selon le client : PhpRedis et Relay rendent `TRUE` (PhpRedis avant sa
   * version 5 : `+PONG`), Predis un statut `PONG`.
   */
  protected static function isPong(mixed $reply): bool {
    if ($reply === TRUE) {
      return TRUE;
    }
    if (is_string($reply) || $reply instanceof \Stringable) {
      return in_array(strtoupper(trim((string) $reply)), ['PONG', '+PONG'], TRUE);
    }

    return FALSE;
  }

}
