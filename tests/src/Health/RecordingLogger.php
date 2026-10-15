<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Health;

use Drupal\Core\Logger\RfcLoggerTrait;
use Psr\Log\LoggerInterface;

/**
 * Un journal de test : garde chaque entrée, de tous les canaux, à tout niveau.
 *
 * Ajouté à `logger.factory`, il reçoit tout ce que reçoit `dblog` (le
 * journal que lit la production) et plus : aucun filtre de niveau.
 */
final class RecordingLogger implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * Les entrées reçues : niveau, message brut et contexte.
   *
   * @var list<array{level: mixed, message: string, context: array<mixed>}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

  /**
   * Les messages reçus, variables remplacées, pour les messages d'échec.
   *
   * @return list<string>
   *   Un message par entrée.
   */
  public function messages(): array {
    return array_map(
      static fn(array $record): string => strtr($record['message'], array_filter(
        $record['context'],
        static fn(mixed $value, string|int $key): bool => is_string($key) && is_scalar($value),
        ARRAY_FILTER_USE_BOTH,
      )),
      $this->records,
    );
  }

}
