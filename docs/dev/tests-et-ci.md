# Dev — Tests et CI

## Ce qui est testé

Tests kernel dans `tests/src/Kernel/`, joués par le noyau HTTP (intergiciels
compris : cache de pages, session), jamais par un appel direct au
contrôleur :

| Test | Couvre |
|---|---|
| `HealthRouteTest` | 200, `no-store`, HEAD, 405 sur POST, aucune session ni journal, maintenance (503 du cœur), fenêtre de 30 s sans glissement, fenêtre réglable, caches de pages contournés, base en panne, Redis déclaré sans module, vérification désactivée |
| `HealthRedisTest` | la vraie fabrique du module `redis`, avec un transport simulé (`FakeRedis`) : formes de `PONG`, panne, rien de contacté sans connexion, bac de cache Redis sans hôte, jamais de `ok` depuis un magasin en panne |
| `HealthRouteUnderRouteNormalizerTest` | pas de 301 sous `redirect` et deux langues préfixées, avec un témoin redirigé |
| `HealthSearchApiTest` | serveur disponible, `warning` en 200, critique par site en 503, aucun serveur actif |
| `HealthFilesTest` | témoin supprimé, une écriture par fenêtre, reliquat nettoyé, dossier impossible à créer, suppression refusée, dossier réglable |
| `HealthPluginApiTest` | vérification tierce : place dans le corps, échec, exception, `not_configured`, critique et active par site, commande drush |

Points de méthode, repris d'uzinasit :

- **Les caches de pages sont réactivés.** Sous PHPUnit (ligne de commande),
  le cœur les éteint (`CommandLineOrUnsafeMethod`), et un test « la route
  n'est pas mise en cache » serait vert quoi qu'il arrive. `WebOrUnsafeMethod`
  rend la politique d'un serveur web, et un témoin (`/healthcheck-test/cacheable`)
  prouve qu'ils servent bien un HIT.
- **L'horloge est simulée** par `datetime.time` (`FrozenClock`), jamais par
  `sleep()`.
- **Les vérifications se comptent** par les `SELECT 1` du journal des requêtes
  de la vraie connexion.
- **La base se met en panne** par `healthcheck_test`, qui donne à `database`
  une sous-classe pilotée par l'état (`FlakyDatabase`).

Contre-épreuves faites au #1 : chacune a fait rougir les tests qui la
couvrent.

- `_disable_route_normalizer` retiré ;
- témoin de `files` jamais supprimé ;
- `redis` qui s'applique toujours ;
- verdict jamais gardé ;
- `no-store` retiré ;
- `-L` ajouté au script de sonde.

## En local

Le module se teste dans un Drupal monté autour de lui, comme en CI :

```bash
docker run --rm -v "$PWD:/builds/healthcheck" -w /builds/healthcheck \
  -e SIMPLETEST_DB=sqlite://localhost//tmp/hc.sqlite -e SIMPLETEST_BASE_URL=http://localhost \
  wodby/drupal-php:8.3-dev bash -c '
    bash .ci/build-site.sh &&
    /tmp/site/vendor/bin/phpcs --standard=phpcs.xml.dist &&
    (cd /tmp/site && vendor/bin/phpstan analyse -c web/modules/custom/healthcheck/phpstan.neon.dist) &&
    (cd /tmp/site && vendor/bin/phpunit -c web/core web/modules/custom/healthcheck/tests)'
bash scripts/ci-health.sh --self-test
```

## Branches

Norme Drupal : la branche par défaut est `1.0.x`, et les tags sont `1.0.0`,
`1.0.1`… Une MR part de `1.0.x` et y revient sans commit de merge
(fusion en avance rapide).

## Piège DDEV : simuler une panne de Redis

Sur le réseau partagé de DDEV, le nom `redis` résout aussi vers les
conteneurs Redis des **autres projets** lancés. Arrêter le conteneur Redis
d'un projet ne simule donc pas une panne : le site en joint un autre.
Pour simuler une panne, déclarez un hôte qui n'existe pas (échec immédiat)
ou une IP qui ne répond pas (environ 5 s), dans `settings.local.php`.

## En CI

`.gitlab-ci.yml`, sur chaque MR, sur la branche par défaut (`1.0.x`) et sur les tags, dans l'image
`wodby/drupal-php:8.3-dev` (PHP 8.3, la version des sites cibles), sur les
runners `phpunit` :

- `phpcs` : standards `Drupal` et `DrupalPractice` (`phpcs.xml.dist`) ;
- `phpstan` : niveau 5, celui d'uzinasit (`phpstan.neon.dist`), avec
  `phpstan-drupal` et les modules optionnels installés ;
- `ci-health:self-test` : l'auto-test du script de sonde ;
- `phpunit` : les tests kernel, sur SQLite.

`.ci/build-site.sh` monte le site dans `/tmp/site`, hors du dépôt, avec
`drupal/core-dev`, drush 13, `redis`, `search_api` et `redirect`, puis y lie
le module.
