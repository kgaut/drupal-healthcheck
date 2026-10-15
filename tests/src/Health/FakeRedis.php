<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Health;

use Drupal\redis\ClientInterface;

/**
 * Un Redis de test, posé derrière la VRAIE fabrique du module `redis`.
 *
 * `ClientFactory::setClient()` en fait le mandataire de client : c'est la
 * fabrique du module qui lit `redis.connection` et appelle `getClient()`
 * avec l'hôte et le port du site. Ce double garde ces paramètres (la preuve
 * qu'aucun n'est deviné), se rend lui-même comme client, et répond à `PING`
 * comme on le lui dit : une réponse, ou une exception.
 */
final class FakeRedis implements ClientInterface {

  /**
   * Les connexions demandées à la fabrique : hôte et port, dans l'ordre.
   *
   * @var list<array{host: mixed, port: mixed}>
   */
  public array $connections = [];

  /**
   * Le nombre de `PING` reçus.
   */
  public int $pings = 0;

  /**
   * La réponse au prochain `PING`.
   */
  public mixed $reply = TRUE;

  /**
   * L'exception levée par `PING` à la place d'une réponse, si posée.
   */
  public ?\Exception $failure = NULL;

  /**
   * {@inheritdoc}
   */
  public function getClient($host = NULL, $port = NULL, $base = NULL, $password = NULL, $replicationHosts = [], $persistent = FALSE) {
    $this->connections[] = ['host' => $host, 'port' => $port];

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'FakeRedis';
  }

  /**
   * La commande `PING`.
   */
  public function ping(): mixed {
    $this->pings++;
    if ($this->failure !== NULL) {
      throw $this->failure;
    }

    return $this->reply;
  }

}
