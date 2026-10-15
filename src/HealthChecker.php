<?php

declare(strict_types=1);

namespace Drupal\healthcheck;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Joue les vérifications du point de santé et garde leur verdict.
 *
 * Chaque plugin `#[HealthCheck]` actif rend un verdict : `ok`, `failed`,
 * `warning` (échec d'une vérification non critique) ou `not_configured` (le
 * site n'utilise pas ce service). Le verdict global est `failed` dès qu'une
 * vérification est `failed`, `ok` sinon : un `warning` ne fait jamais tomber
 * le point de santé (ADR `0001-plugin-contrat-du-type-de-plugin-et-du-json`).
 *
 * Pour la route, le verdict est gardé au plus `window` secondes (30 par
 * défaut), compté depuis l'instant de la vérification et jamais renouvelé
 * par une lecture : une rafale de requêtes ne déclenche pas une vérification
 * par requête (ni une écriture disque par requête), et une panne se voit au
 * plus `window` secondes après la vérification précédente. Un `failed` est
 * gardé comme un `ok`. Le magasin est le bac de cache par défaut du site
 * (Redis ou la base) : s'il ne répond pas, rien n'est tenu pour gardé et
 * tout est revérifié, de sorte qu'un `ok` n'est jamais servi depuis un
 * magasin en panne.
 *
 * La commande drush, elle, vérifie à chaque appel, sans lire ni écrire le
 * verdict gardé (`run()`).
 */
class HealthChecker {

  /**
   * La durée de vie d'un verdict par défaut, en secondes.
   */
  public const DEFAULT_WINDOW = 30;

  /**
   * Le dossier du test d'écriture par défaut.
   */
  public const DEFAULT_FILES_DIRECTORY = 'public://.healthcheck';

  /**
   * La clé du verdict gardé, dans le bac de cache par défaut.
   */
  public const CID = 'healthcheck:verdict';

  /**
   * La vérification a répondu.
   */
  public const OK = 'ok';

  /**
   * La vérification a échoué (et elle est critique).
   */
  public const FAILED = 'failed';

  /**
   * La vérification a échoué, mais elle n'est pas critique.
   */
  public const WARNING = 'warning';

  /**
   * Le site n'utilise pas ce service : ni réussite ni échec.
   */
  public const NOT_CONFIGURED = 'not_configured';

  /**
   * Les verdicts d'une vérification.
   */
  public const VERDICTS = [self::OK, self::FAILED, self::WARNING, self::NOT_CONFIGURED];

  public function __construct(
    protected readonly HealthCheckPluginManager $pluginManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly CacheBackendInterface $memo,
    protected readonly TimeInterface $time,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * L'état du site, pour la route : le verdict gardé s'il est dans sa fenêtre.
   *
   * Une vérification échouée se journalise à chaque nouvelle vérification,
   * donc au plus une fois par fenêtre.
   *
   * @return array{status: string, checks: array<string, string>}
   *   Le verdict global et celui de chaque vérification active.
   */
  public function status(): array {
    $now = (int) $this->time->getCurrentTime();
    $window = $this->window();
    $ids = $this->enabledIds();
    if ($ids === NULL) {
      return ['status' => self::FAILED, 'checks' => []];
    }

    $checks = $this->remembered($now, $window, $ids);
    if ($checks === NULL) {
      $checks = [];
      foreach ($this->run() as $outcome) {
        $checks[$outcome->id] = $outcome->verdict;
        $this->logOutcome($outcome);
      }
      try {
        $this->memo->set(self::CID, ['checked_at' => $now, 'checks' => $checks], $now + $window);
      }
      catch (\Throwable) {
        // Sans magasin, la requête suivante vérifie à nouveau : c'est le prix
        // d'une panne du magasin, jamais un verdict inventé.
      }
    }

    return ['status' => self::globalStatus($checks), 'checks' => $checks];
  }

  /**
   * Joue toutes les vérifications actives, maintenant.
   *
   * Ni lecture ni écriture du verdict gardé, rien au journal : c'est
   * l'appelant qui décide (la route journalise, la commande drush affiche).
   *
   * @return list<\Drupal\healthcheck\CheckOutcome>
   *   Le verdict de chaque vérification active, dans l'ordre du corps.
   */
  public function run(): array {
    $definitions = $this->definitions();
    if ($definitions === NULL) {
      return [];
    }
    $overrides = $this->overrides();
    $outcomes = [];
    foreach ($definitions as $id => $definition) {
      if (!self::isEnabled($overrides, $id)) {
        continue;
      }
      $critical = self::isCritical($overrides, $id, $definition);
      $label = (string) ($definition['label'] ?? $id);
      $outcomes[] = $this->runOne($id, $label, $critical);
    }

    return $outcomes;
  }

  /**
   * Le verdict global d'un ensemble de verdicts.
   *
   * @param array<string, string> $checks
   *   Le verdict de chaque vérification.
   */
  public static function globalStatus(array $checks): string {
    return in_array(self::FAILED, $checks, TRUE) ? self::FAILED : self::OK;
  }

  /**
   * Joue une vérification et traduit son résultat en verdict.
   */
  protected function runOne(string $id, string $label, bool $critical): CheckOutcome {
    $failed = $critical ? self::FAILED : self::WARNING;
    try {
      /** @var \Drupal\healthcheck\HealthCheckInterface $plugin */
      $plugin = $this->pluginManager->createInstance($id);
      if (!$plugin->applies()) {
        return new CheckOutcome($id, $label, $critical, self::NOT_CONFIGURED);
      }
      $result = $plugin->check();
    }
    catch (\Throwable $e) {
      return new CheckOutcome($id, $label, $critical, $failed, $e::class . ' : ' . $e->getMessage());
    }

    return $result->ok
      ? new CheckOutcome($id, $label, $critical, self::OK)
      : new CheckOutcome($id, $label, $critical, $failed, $result->reason);
  }

  /**
   * Le verdict gardé, s'il est lisible, bien formé et dans sa fenêtre.
   *
   * @param int $now
   *   L'instant courant.
   * @param int $window
   *   La durée de vie d'un verdict.
   * @param list<string> $ids
   *   Les vérifications actives, dans l'ordre : un verdict gardé sous une
   *   autre liste (réglage changé, module installé) ne vaut plus.
   *
   * @return array<string, string>|null
   *   Le verdict de chaque vérification, ou NULL s'il faut vérifier.
   */
  protected function remembered(int $now, int $window, array $ids): ?array {
    try {
      $item = $this->memo->get(self::CID);
    }
    catch (\Throwable) {
      // Le magasin ne répond pas (c'est peut-être Redis lui-même) : rien
      // n'est tenu pour gardé, les vérifications diront ce qui en est.
      return NULL;
    }
    if ($item === FALSE || !is_array($item->data)) {
      return NULL;
    }
    $checkedAt = $item->data['checked_at'] ?? NULL;
    $checks = $item->data['checks'] ?? NULL;
    // Un instant dans le futur (horloge reculée) ne prolonge rien.
    if (!is_int($checkedAt) || $checkedAt > $now || $now - $checkedAt >= $window) {
      return NULL;
    }
    if (!is_array($checks) || array_keys($checks) !== $ids) {
      return NULL;
    }
    foreach ($checks as $verdict) {
      if (!in_array($verdict, self::VERDICTS, TRUE)) {
        return NULL;
      }
    }

    return $checks;
  }

  /**
   * Journalise un échec ; une réussite, jamais.
   */
  protected function logOutcome(CheckOutcome $outcome): void {
    $context = ['@check' => $outcome->id, '@reason' => $outcome->reason];
    if ($outcome->verdict === self::FAILED) {
      $this->logger->error('Point de santé : la vérification « @check » a échoué (@reason).', $context);
    }
    elseif ($outcome->verdict === self::WARNING) {
      $this->logger->warning('Point de santé : la vérification non critique « @check » a échoué (@reason).', $context);
    }
  }

  /**
   * Les identifiants des vérifications actives, dans l'ordre du corps.
   *
   * @return list<string>|null
   *   NULL si les définitions sont illisibles.
   */
  protected function enabledIds(): ?array {
    $definitions = $this->definitions();
    if ($definitions === NULL) {
      return NULL;
    }
    $overrides = $this->overrides();

    return array_values(array_filter(
      array_keys($definitions),
      static fn(string $id): bool => self::isEnabled($overrides, $id),
    ));
  }

  /**
   * Les définitions des vérifications, par poids puis identifiant.
   *
   * @return array<string, array<string, mixed>>|null
   *   NULL si elles ne peuvent être lues (cache de découverte en panne…) :
   *   le point de santé dit alors `failed`, sans détail.
   */
  protected function definitions(): ?array {
    try {
      $definitions = $this->pluginManager->getDefinitions();
    }
    catch (\Throwable $e) {
      $this->logger->error('Point de santé : les vérifications sont illisibles (@reason).', [
        '@reason' => $e::class . ' : ' . $e->getMessage(),
      ]);
      return NULL;
    }
    uksort($definitions, static function (string $a, string $b) use ($definitions): int {
      return [(int) ($definitions[$a]['weight'] ?? 0), $a] <=> [(int) ($definitions[$b]['weight'] ?? 0), $b];
    });

    return $definitions;
  }

  /**
   * La durée de vie d'un verdict, réglée par site.
   */
  protected function window(): int {
    $window = $this->setting('window');

    return is_int($window) && $window > 0 ? $window : self::DEFAULT_WINDOW;
  }

  /**
   * Les réglages par vérification (`checks.<id>.enabled|critical`).
   *
   * @return array<string, array<string, mixed>>
   *   Les réglages, par identifiant.
   */
  protected function overrides(): array {
    $checks = $this->setting('checks');

    return is_array($checks) ? $checks : [];
  }

  /**
   * Une clé de `healthcheck.settings`, ou NULL si illisible.
   *
   * Une configuration illisible ne fait pas tomber la route : les valeurs
   * par défaut s'appliquent, et la vérification de la base dira le reste.
   */
  protected function setting(string $key): mixed {
    try {
      return $this->configFactory->get('healthcheck.settings')->get($key);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * La vérification est-elle active ? Oui, sauf réglage contraire.
   *
   * @param array<string, array<string, mixed>> $overrides
   *   Les réglages par vérification.
   * @param string $id
   *   L'identifiant de la vérification.
   */
  protected static function isEnabled(array $overrides, string $id): bool {
    return ($overrides[$id]['enabled'] ?? TRUE) !== FALSE;
  }

  /**
   * La vérification est-elle critique ? Le réglage, sinon l'attribut.
   *
   * @param array<string, array<string, mixed>> $overrides
   *   Les réglages par vérification.
   * @param string $id
   *   L'identifiant de la vérification.
   * @param array<string, mixed> $definition
   *   La définition du plugin.
   */
  protected static function isCritical(array $overrides, string $id, array $definition): bool {
    $critical = $overrides[$id]['critical'] ?? NULL;

    return is_bool($critical) ? $critical : (bool) ($definition['critical'] ?? TRUE);
  }

}
