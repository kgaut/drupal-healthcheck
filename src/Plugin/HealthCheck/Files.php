<?php

declare(strict_types=1);

namespace Drupal\healthcheck\Plugin\HealthCheck;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\healthcheck\Attribute\HealthCheck;
use Drupal\healthcheck\CheckResult;
use Drupal\healthcheck\HealthChecker;
use Drupal\healthcheck\HealthCheckBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Écrit, relit puis supprime un fichier témoin dans les fichiers du site.
 *
 * Le dossier est `public://.healthcheck` par défaut
 * (`healthcheck.settings:files_directory`) : le volume des fichiers publics
 * est celui qu'un site perd quand son disque disparaît, là où
 * `temporary://` vit souvent sur un autre (`/tmp`). ADR
 * `0001-plugin-files-ecrit-dans-public`, amendée par
 * `0002-plugin-files-ne-corrige-pas-les-droits`.
 *
 * Une sonde ne modifie pas ce qu'elle mesure :
 * - la racine du schéma (`public://`) doit exister : un dossier de fichiers
 *   disparu n'est jamais recréé, il rend `failed` ;
 * - seul le sous-dossier du témoin est créé s'il manque, et jamais par la
 *   commande drush (`cli` dans la configuration du plugin), qui le créerait
 *   au nom de son propre utilisateur. En ligne de commande, un sous-dossier
 *   absent sous une racine présente rend `not_configured` : ce n'est pas une
 *   panne, le premier appel à `/health` le crée (ADR
 *   `0004-plugin-files-en-ligne-de-commande-avant-le-premier-appel-web`) ;
 * - ses droits ne sont jamais corrigés : non inscriptible, il rend `failed` ;
 * - la racine d'un schéma est refusée comme dossier du témoin : le nettoyage
 *   y toucherait des fichiers du site.
 *
 * Le témoin ne doit jamais rester sur le disque :
 * - un reliquat (`probe-*.txt`) plus vieux que la fenêtre, et au moins
 *   qu'une minute, est supprimé avant l'écriture ; s'il ne peut l'être, la
 *   vérification échoue. Un témoin plus récent est celui d'une vérification
 *   simultanée (route et drush, appels à l'expiration de la fenêtre) : il
 *   n'est pas touché ;
 * - le témoin est supprimé même si l'écriture ou la relecture a échoué ;
 *   s'il existe encore après, la vérification échoue.
 *
 * Joué au plus une fois par fenêtre par la route, comme toute vérification :
 * une rafale d'appels n'écrit pas à chaque appel.
 */
#[HealthCheck(
  id: 'files',
  label: new TranslatableMarkup('Écriture de fichiers'),
  critical: TRUE,
  weight: 30,
)]
class Files extends HealthCheckBase {

  /**
   * Le motif des fichiers témoins, pour retrouver les reliquats.
   */
  public const PROBE_MASK = '/^probe-[0-9a-f]+\.txt$/';

  /**
   * L'âge minimal d'un reliquat supprimable, en secondes.
   *
   * Un témoin vit quelques millisecondes : au-delà d'une minute (ou de la
   * fenêtre, si elle est plus longue), il n'appartient plus à une
   * vérification en cours.
   */
  public const LEFTOVER_MIN_AGE = 60;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected FileSystemInterface $fileSystem,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {
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
      $container->get('file_system'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Faux seulement en ligne de commande, quand la racine du schéma existe et
   * que le dossier du témoin n'a pas encore été créé par la route. Une racine
   * absente, elle, s'applique : `check()` la dira en échec.
   */
  public function applies(): bool {
    if (!($this->configuration['cli'] ?? FALSE)) {
      return TRUE;
    }
    $directory = $this->directory();
    $scheme = StreamWrapperManager::getScheme($directory);

    return $scheme === FALSE || !@is_dir($scheme . '://') || @is_dir($directory);
  }

  /**
   * {@inheritdoc}
   */
  public function check(): CheckResult {
    $directory = $this->directory();
    $scheme = StreamWrapperManager::getScheme($directory);
    if ($scheme === FALSE || trim((string) StreamWrapperManager::getTarget($directory), '/') === '') {
      return CheckResult::failed("dossier $directory refusé : il faut un sous-dossier dédié, pas la racine d'un schéma");
    }
    $root = $scheme . '://';
    // `@` : le flux local de Drupal émet un avertissement sur un chemin
    // absent, que le journal recevrait à chaque vérification.
    if (!@is_dir($root)) {
      return CheckResult::failed("racine $root absente : le dossier des fichiers du site n'existe plus");
    }
    if (!@is_dir($directory)) {
      if ($this->configuration['cli'] ?? FALSE) {
        return CheckResult::failed("dossier $directory absent : la commande ne le crée pas (il appartiendrait à son utilisateur), un appel à /health le crée");
      }
      // Sans MODIFY_PERMISSIONS : corriger les droits masquerait la panne.
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
        return CheckResult::failed("dossier $directory impossible à créer");
      }
    }
    elseif (!is_writable($directory)) {
      return CheckResult::failed("dossier $directory non inscriptible");
    }

    $threshold = (int) $this->time->getCurrentTime() - max(self::LEFTOVER_MIN_AGE, $this->window());
    foreach ($this->fileSystem->scanDirectory($directory, self::PROBE_MASK, ['recurse' => FALSE]) as $leftover) {
      $mtime = @filemtime($leftover->uri);
      if ($mtime !== FALSE && $mtime > $threshold) {
        // Le témoin d'une vérification simultanée : pas un reliquat.
        continue;
      }
      if (!$this->remove($leftover->uri)) {
        return CheckResult::failed("reliquat {$leftover->uri} impossible à supprimer");
      }
    }

    $uri = $directory . '/probe-' . bin2hex(random_bytes(8)) . '.txt';
    $payload = bin2hex(random_bytes(16));
    $written = @file_put_contents($uri, $payload);
    $read = $written === strlen($payload) ? @file_get_contents($uri) : FALSE;
    // Toujours, quoi qu'aient donné l'écriture et la relecture.
    $removed = $this->remove($uri);

    if ($written !== strlen($payload)) {
      return CheckResult::failed("écriture impossible dans $directory");
    }
    if ($read !== $payload) {
      return CheckResult::failed("relecture différente de l'écriture dans $directory");
    }
    if (!$removed) {
      return CheckResult::failed("témoin $uri impossible à supprimer");
    }

    return CheckResult::ok();
  }

  /**
   * Supprime un fichier ; vrai s'il n'existe plus après.
   */
  protected function remove(string $uri): bool {
    clearstatcache(TRUE);
    if (file_exists($uri)) {
      @$this->fileSystem->unlink($uri);
      clearstatcache(TRUE);
    }

    return !file_exists($uri);
  }

  /**
   * Le dossier du témoin, sans `/` final (sauf une racine, refusée ensuite).
   */
  protected function directory(): string {
    $directory = $this->configFactory->get('healthcheck.settings')->get('files_directory');
    $directory = is_string($directory) && $directory !== '' ? $directory : HealthChecker::DEFAULT_FILES_DIRECTORY;

    return str_ends_with($directory, '://') ? $directory : rtrim($directory, '/');
  }

  /**
   * La fenêtre du point de santé, en secondes.
   */
  protected function window(): int {
    $window = $this->configFactory->get('healthcheck.settings')->get('window');

    return is_int($window) && $window > 0 ? $window : HealthChecker::DEFAULT_WINDOW;
  }

}
