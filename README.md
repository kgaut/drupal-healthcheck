# Healthcheck — `kgaut/healthcheck`

Un point de santé `/health` pour Drupal 11, à donner à une sonde de
disponibilité (Uptime Kuma, job de CI post-déploiement…). Il rend un verdict
JSON par vérification : base de données, Redis, Search API, écriture de
fichiers. Il s'étend à d'autres services par des plugins.

```console
$ curl -sS https://www.exemple.fr/health
{"status":"ok","checks":{"database":"ok","redis":"ok","search_api":"warning","files":"ok"}}
```

## Installation

```bash
composer require kgaut/healthcheck
drush en healthcheck
```

Aucun réglage n'est nécessaire. Exigences : Drupal ^11.1 et PHP 8.3 ou plus.
⚠️ Ne pas l'installer à côté d'un autre module qui sert `/health`
(`drupal/health_check`, le projet drupal.org abandonné `healthcheck`…) :
voir l'ADR [`0001-infra-module-dedie-et-nom-machine-healthcheck`](docs/adr/0001-infra-module-dedie-et-nom-machine-healthcheck.md).

## La réponse

| Statut HTTP | Corps | Sens |
|---|---|---|
| `200` | `{"status":"ok","checks":{…}}` | aucune vérification **critique** n'a échoué |
| `503` | `{"status":"failed","checks":{…}}` | au moins une vérification critique a échoué |
| `503` en texte brut | page de maintenance | le site est en maintenance |
| `500` | page d'erreur | Drupal ne démarre pas (base ou Redis tombés au démarrage, disque perdu…) |

Verdict de chaque vérification :

- `ok` : le service a répondu ;
- `failed` : une vérification **critique** a échoué, et la réponse passe en 503 ;
- `warning` : une vérification **non critique** a échoué. ⚠️ **La réponse
  reste en 200** : le verdict global ne passe à `failed` que sur l'échec d'une
  vérification critique ;
- `not_configured` : le site n'utilise pas ce service (pas de Redis déclaré,
  pas de serveur Search API actif…). Ce n'est jamais un échec.

**Une sonde ne doit tenir pour sain qu'un HTTP 200 dont le corps contient
`"status":"ok"`, sans suivre les redirections.** Une panne franche donne un
500, et un proxy peut répondre 200 avec sa propre page.

Le corps ne contient ni hôte, ni chemin, ni version, ni message d'erreur.
Il dit en revanche quels services le site utilise (`redis: not_configured`
révèle un site sans Redis) : c'est le prix d'un verdict par vérification.
Aucune vérification active donne `503` et `"status":"failed"`. La
cause d'un échec va au journal de Drupal (canal `healthcheck`) et dans la
sortie de `drush healthcheck:run`.

Le verdict est gardé **30 secondes** au plus. Une rafale d'appels ne relance
donc pas les vérifications à chaque fois, et une panne se voit au plus
30 secondes après la vérification précédente. La réponse HTTP, elle, n'est
jamais mise en cache (`Cache-Control: no-store`).

## Les vérifications fournies

| Id | Vérifie | Critique par défaut | `not_configured` quand |
|---|---|---|---|
| `database` | `SELECT 1` sur la connexion par défaut | oui | jamais |
| `redis` | `PING` par la fabrique du module `redis` | oui | aucun hôte `redis.connection` ni bac de cache `cache.backend.redis` déclaré |
| `search_api` | `isAvailable()` de chaque serveur Search API actif (un `ping` pour Solr ; un backend base de données est toujours disponible, il ne contacte rien et c'est `database` qui compte) | **non** | module absent, ou aucun serveur actif |
| `files` | écriture, relecture puis suppression d'un fichier dans `public://.healthcheck` (droits du dossier jamais corrigés) | oui | jamais |

`redis` rend `failed` si une connexion est déclarée alors que le module
`redis` est absent.

## Réglages par site

Dans la config `healthcheck.settings`, exportée avec le reste de la config
du site :

```yaml
window: 30                                # durée de vie d'un verdict, en secondes
files_directory: 'public://.healthcheck'  # dossier du test d'écriture
checks:
  search_api:
    critical: true      # rendre Search API critique sur ce site : un Solr en panne → 503
  files:
    enabled: false      # ne pas jouer cette vérification sur ce site
```

Les mêmes réglages se surchargent par environnement dans `settings.php` :

```php
// Search API critique en production seulement.
$config['healthcheck.settings']['checks']['search_api']['critical'] = TRUE;
// Le test d'écriture dans un autre dossier.
$config['healthcheck.settings']['files_directory'] = 'private://healthcheck';
```

## Commande drush

```bash
drush healthcheck:run                 # tableau : vérification, verdict, critique, cause
drush healthcheck:run --format=json   # pour une CI
```

⚠️ La commande s'exécute sous l'utilisateur de drush. Elle écrit son témoin
dans `public://.healthcheck`, mais ne **crée** jamais ce dossier : il
appartiendrait à cet utilisateur, et le module ne corrige pas les droits.
Juste après l'installation, appelez `/health` une fois.

La commande vérifie à **chaque appel**, sans le verdict gardé de la route, et
affiche la cause de chaque échec. En JSON, `critical` est un booléen. Code de sortie : 1 si une vérification
critique échoue, 0 sinon (un `warning` ne fait pas échouer la commande).

## Ajouter une vérification depuis un autre module

```php
namespace Drupal\mon_module\Plugin\HealthCheck;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\healthcheck\Attribute\HealthCheck;
use Drupal\healthcheck\CheckResult;
use Drupal\healthcheck\HealthCheckBase;

#[HealthCheck(id: 'mon_service', label: new TranslatableMarkup('Mon service'), critical: TRUE, weight: 40)]
final class MonService extends HealthCheckBase {

  public function check(): CheckResult {
    // Interroger réellement le service, avec un délai court.
    return CheckResult::ok();
  }

}
```

Le contrat complet (`applies()`, exceptions, délais) est décrit dans
[docs/dev/ecrire-une-verification.md](docs/dev/ecrire-une-verification.md).

## Sonde après un déploiement

`scripts/ci-health.sh <url>` interroge le point de santé jusqu'à 6 fois, à
10 secondes d'intervalle, sans suivre les redirections, et sort en erreur si
aucune réponse n'est un 200 avec `"status":"ok"`. Un exemple de job GitLab
est donné dans
[docs/exploitation/sonde-post-deploiement.md](docs/exploitation/sonde-post-deploiement.md).

## Documentation

Tout est dans [docs/](docs/README.md) : exploitation, développement et
décisions (ADR).
