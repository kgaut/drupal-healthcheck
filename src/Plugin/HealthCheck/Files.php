<?php

declare(strict_types=1);

namespace Drupal\healthcheck\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
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
 * Une sonde ne modifie pas ce qu'elle mesure : le dossier est créé s'il
 * manque, mais ses droits ne sont jamais corrigés. Un dossier non inscriptible
 * rend `failed`.
 *
 * Le témoin ne doit jamais rester sur le disque :
 * - un reliquat d'un passage précédent (`probe-*.txt`) est supprimé avant
 *   l'écriture ; s'il ne peut l'être, la vérification échoue ;
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

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected FileSystemInterface $fileSystem,
    protected ConfigFactoryInterface $configFactory,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function check(): CheckResult {
    $directory = $this->directory();
    // Sans MODIFY_PERMISSIONS : corriger les droits masquerait la panne.
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      return CheckResult::failed("dossier $directory impossible à créer ou à écrire");
    }

    foreach ($this->fileSystem->scanDirectory($directory, self::PROBE_MASK, ['recurse' => FALSE]) as $leftover) {
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
   * Le dossier du témoin, sans `/` final.
   */
  protected function directory(): string {
    $directory = $this->configFactory->get('healthcheck.settings')->get('files_directory');

    $directory = is_string($directory) && $directory !== '' ? $directory : HealthChecker::DEFAULT_FILES_DIRECTORY;

    // `public://` seul garde ses barres : c'est la racine du schéma.
    return str_ends_with($directory, '://') ? substr($directory, 0, -1) : rtrim($directory, '/');
  }

}
