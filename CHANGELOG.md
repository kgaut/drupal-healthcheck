# Changelog

Toutes les évolutions notables du module `kgaut/healthcheck`.

## [Non publié]

### Corrigé

- #2 `drush healthcheck:run --format=json` : `critical` est un booléen, et non plus « oui »/« non ». Le tableau garde l'affichage oui/non.
- #2 La vérification `files` ne corrige plus les droits de `public://.healthcheck` : un dossier non inscriptible rend `failed` au lieu d'être réparé sans bruit. ADR `0002-plugin-files-ne-corrige-pas-les-droits`, qui amende `0001-plugin-files-ecrit-dans-public`.

### Documentation

- #2 Durée d'un appel en panne (Redis, base tierce, `PDO::ATTR_TIMEOUT`) et piège DDEV du nom `redis` partagé entre projets.

## [1.0.0-beta1] — 15/10/2026

### Ajouté

- #1 Point de santé `/health` extensible par plugins : type de plugin `#[HealthCheck]` (`applies()`, `check()`, critique ou non) et vérifications `database`, `redis`, `search_api` (non critique) et `files` (écriture dans `public://.healthcheck`). Verdict JSON `ok`/`failed` en 200/503, gardé 30 s, sans détail dans le corps. Réglages par site dans `healthcheck.settings`, commande `drush healthcheck:run`, script de sonde post-déploiement `scripts/ci-health.sh`, CI GitLab (phpcs, phpstan, phpunit). ADR `0001-infra-module-dedie-et-nom-machine-healthcheck`, `0001-plugin-contrat-du-type-de-plugin-et-du-json` `0001-plugin-files-ecrit-dans-public` et `0001-infra-docs-de-ticket-hors-du-depot-public` (docs de ticket hors du dépôt public).
