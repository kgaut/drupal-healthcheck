<?php

declare(strict_types=1);

namespace Drupal\Tests\healthcheck\Kernel;

use Drupal\Core\File\FileSystem;
use Drupal\healthcheck\Plugin\HealthCheck\Files;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La vérification `files` : écrire, relire, supprimer, sans rien laisser.
 *
 * C'est la vérification qui aurait vu un volume de fichiers disparu, si la
 * route avait démarré. Le témoin ne doit jamais rester sur le disque, et une
 * seule écriture a lieu par fenêtre.
 */
#[Group('healthcheck')]
#[RunTestsInSeparateProcesses]
class HealthFilesTest extends HealthKernelTestBase {

  /**
   * Le corps quand tout répond.
   */
  private const OK = '{"status":"ok","checks":{"database":"ok","redis":"not_configured","search_api":"not_configured","files":"ok"}}';

  /**
   * Le corps quand l'écriture échoue.
   */
  private const FILES_FAILED = '{"status":"failed","checks":{"database":"ok","redis":"not_configured","search_api":"not_configured","files":"failed"}}';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'healthcheck',
  ];

  /**
   * Les fichiers témoins présents dans un dossier.
   *
   * @return list<string>
   *   Leurs URI.
   */
  private function probes(string $directory = 'public://.healthcheck'): array {
    $files = $this->container->get('file_system')->scanDirectory($directory, Files::PROBE_MASK, ['recurse' => FALSE]);

    return array_values(array_map(static fn(object $file): string => $file->uri, $files));
  }

  /**
   * Le témoin est écrit dans `public://.healthcheck`, puis supprimé.
   */
  public function testLeTemoinEstEcritPuisSupprime(): void {
    $this->assertHealth(200, self::OK, $this->request('/health'));

    $this->assertDirectoryExists('public://.healthcheck', 'Le dossier par défaut est créé.');
    $this->assertSame([], $this->probes(), 'Aucun témoin ne reste sur le disque.');
    $this->assertSame([], $this->journal->messages());
  }

  /**
   * Une écriture par fenêtre, pas une par appel.
   *
   * Le dossier est recréé à chaque vérification s'il a disparu : sa présence
   * dit donc qu'une vérification a eu lieu.
   */
  public function testUneEcritureParFenetre(): void {
    $fileSystem = $this->container->get('file_system');

    $this->request('/health');
    $fileSystem->rmdir('public://.healthcheck');
    $this->advance(10);
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 + 10 : verdict gardé');
    $this->assertDirectoryDoesNotExist('public://.healthcheck', 'Dans la fenêtre : rien n’est écrit.');

    $this->advance(20);
    $this->assertHealth(200, self::OK, $this->request('/health'), 't0 + 30');
    $this->assertDirectoryExists('public://.healthcheck', 'Fenêtre échue : le témoin est réécrit.');
  }

  /**
   * Un reliquat ancien est supprimé, le témoin d'une vérification en cours non.
   *
   * Deux vérifications simultanées (la route à l'expiration de la fenêtre,
   * ou la route et drush) : si l'une supprimait le témoin tout juste écrit
   * par l'autre, celle-ci relirait un fichier absent et rendrait un faux
   * `failed`, gardé toute la fenêtre. Seul un témoin de plus d'une minute est
   * un reliquat.
   */
  public function testUnReliquatAncienEstSupprimeUnTemoinRecentNon(): void {
    $fileSystem = $this->container->get('file_system');
    $directory = 'public://.healthcheck';
    $fileSystem->prepareDirectory($directory, FileSystem::CREATE_DIRECTORY);
    $old = "$directory/probe-0123456789abcdef.txt";
    $recent = "$directory/probe-fedcba9876543210.txt";
    file_put_contents($old, 'reliquat');
    touch($old, $this->clock->now - 61);
    file_put_contents($recent, 'témoin en cours');
    touch($recent, $this->clock->now - 5);
    file_put_contents("$directory/autre.txt", 'pas un témoin');

    $this->assertHealth(200, self::OK, $this->request('/health'));

    $this->assertSame([$recent], $this->probes(), 'Le reliquat ancien a disparu, le témoin récent est intact.');
    $this->assertFileExists("$directory/autre.txt", 'Seuls les témoins sont supprimés.');
  }

  /**
   * Un dossier de fichiers disparu n'est pas recréé : `failed`.
   *
   * Un `mkdir` récursif recréerait le dossier des fichiers, vide, et un `ok`
   * masquerait sa perte : c'est le cas même d'un volume détaché, quand le
   * dossier parent reste inscriptible.
   */
  public function testUnDossierDeFichiersDisparuResteAbsent(): void {
    // Le dossier des fichiers du site pointe là où plus rien n'existe.
    $public = $this->siteDirectory . '/files-disparu';
    $this->setSetting('file_public_path', $public);

    $this->assertHealth(503, self::FILES_FAILED, $this->request('/health'));

    $this->assertDirectoryDoesNotExist($public, 'Le dossier des fichiers n’est pas recréé.');
    $this->assertCount(1, $this->journal->messages(), 'Une entrée, sans avertissement de flux.');
    $this->assertStringContainsString('racine public:// absente', $this->journal->messages()[0]);
  }

  /**
   * La racine d'un schéma est refusée : le nettoyage y toucherait le site.
   */
  public function testLaRacineDuSchemaEstRefusee(): void {
    $this->configure('files_directory', 'public://');
    file_put_contents('public://probe-0123456789abcdef.txt', 'un fichier du site');
    touch('public://probe-0123456789abcdef.txt', $this->clock->now - 3600);

    $this->assertHealth(503, self::FILES_FAILED, $this->request('/health'));

    $this->assertFileExists('public://probe-0123456789abcdef.txt', 'Rien n’est supprimé à la racine.');
    $this->assertStringContainsString('refusé', $this->journal->messages()[0]);
  }

  /**
   * La commande drush ne crée jamais le dossier du témoin.
   *
   * Lancée sous un autre utilisateur que le serveur web, elle le créerait à
   * son nom, et plus rien ne corrige les droits. Le dossier absent est un
   * échec, avec sa cause ; présent, la commande vérifie normalement.
   */
  public function testLaCommandeNeCreePasLeDossier(): void {
    $checker = $this->container->get('healthcheck.checker');
    $files = static fn(array $outcomes): object => array_values(array_filter($outcomes, static fn(object $o): bool => $o->id === 'files'))[0];

    $missing = $files($checker->run(TRUE));
    $this->assertSame('failed', $missing->verdict);
    $this->assertStringContainsString('la commande ne le crée pas', $missing->reason);
    $this->assertDirectoryDoesNotExist('public://.healthcheck');

    $this->request('/health');
    $this->assertDirectoryExists('public://.healthcheck', 'La route, elle, le crée.');
    $this->assertSame('ok', $files($checker->run(TRUE))->verdict);
  }

  /**
   * Un dossier impossible à créer : 503, sans le chemin dans le corps.
   *
   * Le dossier est réglé sous un FICHIER : aucun `mkdir` n'y réussit, quel
   * que soit l'utilisateur qui joue le test.
   */
  public function testUnDossierNonCreableRend503(): void {
    file_put_contents('public://bouchon', 'un fichier, pas un dossier');
    $this->configure('files_directory', 'public://bouchon/sonde');

    $response = $this->request('/health');

    $this->assertHealth(503, self::FILES_FAILED, $response);
    $this->assertNoDetail(['bouchon', 'public://', 'sites/'], $response);
    $messages = $this->journal->messages();
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('« files »', $messages[0]);
    $this->assertStringContainsString('public://bouchon/sonde', $messages[0], 'Le chemin va au journal.');
  }

  /**
   * Un dossier en lecture seule : `failed`, et ses droits ne sont pas touchés.
   *
   * Une sonde ne modifie pas ce qu'elle mesure : corriger les droits au
   * passage masquerait la panne. Sans objet sous root, qui écrit partout.
   */
  public function testUnDossierEnLectureSeuleEchoueSansEtreCorrige(): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
      $this->markTestSkipped('root écrit dans un dossier en lecture seule.');
    }
    $directory = 'public://.healthcheck';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystem::CREATE_DIRECTORY);
    chmod($directory, 0555);

    try {
      $this->assertHealth(503, self::FILES_FAILED, $this->request('/health'));
      clearstatcache();
      $this->assertSame('555', substr(sprintf('%o', fileperms($directory)), -3), 'Les droits du dossier sont intacts.');
      $this->assertStringContainsString('« files »', $this->journal->messages()[0]);
    }
    finally {
      chmod($directory, 0775);
    }
  }

  /**
   * Un témoin impossible à supprimer : `failed`, et nettoyé au passage suivant.
   */
  public function testUnTemoinNonSupprimableEchoue(): void {
    $real = $this->container->get('file_system');
    $stubborn = new class($real) extends FileSystem {

      /**
       * La suppression est refusée.
       */
      public bool $refuse = TRUE;

      public function __construct(private readonly FileSystem $inner) {}

      /**
       * {@inheritdoc}
       */
      public function prepareDirectory(&$directory, $options = self::MODIFY_PERMISSIONS) {
        return $this->inner->prepareDirectory($directory, $options);
      }

      /**
       * {@inheritdoc}
       */
      public function scanDirectory($dir, $mask, array $options = []) {
        return $this->inner->scanDirectory($dir, $mask, $options);
      }

      /**
       * {@inheritdoc}
       */
      public function unlink($uri, $context = NULL) {
        return $this->refuse ? FALSE : $this->inner->unlink($uri, $context);
      }

    };
    $this->container->set('file_system', $stubborn);

    $this->assertHealth(503, self::FILES_FAILED, $this->request('/health'), 'Suppression refusée');
    $this->assertCount(1, $this->probes(), 'Le témoin est resté.');
    $this->assertStringContainsString('impossible à supprimer', $this->journal->messages()[0]);

    $stubborn->refuse = FALSE;
    // Le témoin resté devient un reliquat après une minute.
    touch($this->probes()[0], $this->clock->now - 61);
    $this->advance(30);
    $this->assertHealth(200, self::OK, $this->request('/health'), 'Passage suivant');
    $this->assertSame([], $this->probes(), 'Le reliquat est nettoyé.');
  }

  /**
   * Le dossier se règle par site, `temporary://` compris.
   */
  public function testLeDossierSeRegleParSite(): void {
    $this->configure('files_directory', 'temporary://healthcheck-test/');

    $this->assertHealth(200, self::OK, $this->request('/health'));

    $this->assertDirectoryExists('temporary://healthcheck-test');
    $this->assertSame([], $this->probes('temporary://healthcheck-test'));
    $this->assertDirectoryDoesNotExist('public://.healthcheck', 'Rien n’est écrit ailleurs.');
  }

}
