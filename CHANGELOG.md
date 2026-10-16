# Changelog

Toutes les évolutions notables du module `kgaut/healthcheck`.

## [Non publié]

### Documentation

- #5 Exempter `/health` d'une authentification HTTP Basic : forme Apache par `%{THE_REQUEST}` (`%{REQUEST_URI}` est évalué après la réécriture vers `index.php` dans le `.htaccess` racine), forme nginx, vérifications.

## [1.0.0-beta4] — 15/10/2026

### Modifié

- #4 En ligne de commande, `files` vaut `not_configured` tant que `/health` n'a pas créé `.healthcheck` : `drush healthcheck:run` ne fait plus échouer un déploiement juste après l'installation. Un dossier de fichiers disparu reste `failed`. ADR `0004-plugin-files-en-ligne-de-commande-avant-le-premier-appel-web`, qui amende `0003-plugin-verdicts-surs-sous-concurrence-et-hors-du-serveur-web`.
- #4 `drush healthcheck:run` en JSON (ou tout format hors tableau) : lignes indexées par identifiant, sans champ `check` répété.

### Ajouté

- #4 Champs de contexte plats au journal : `event` (`healthcheck.check_failed`, `healthcheck.check_warning`, `healthcheck.no_active_check`, `healthcheck.definitions_unreadable`), `check` et `reason`.

## [1.0.0-beta3] — 15/10/2026

### Corrigé

- #3 `files` : deux vérifications simultanées ne se suppriment plus leurs témoins (seul un témoin de plus d'une minute est un reliquat), ce qui évite un faux 503 gardé toute la fenêtre.
- #3 `files` : un dossier de fichiers disparu n'est plus recréé (la racine `public://` doit exister), la racine d'un schéma est refusée comme dossier du témoin, et `drush healthcheck:run` ne crée plus `.healthcheck` sous son propre utilisateur.
- #3 Le verdict gardé dépend aussi de la criticité des vérifications, et aucune vérification active rend désormais 503. ADR `0003-plugin-verdicts-surs-sous-concurrence-et-hors-du-serveur-web`, qui complète `0001-plugin-contrat-du-type-de-plugin-et-du-json` et `0002-plugin-files-ne-corrige-pas-les-droits`.

### Documentation

- #3 Search API avec un backend base de données, services révélés par le corps, commande drush et dossier du témoin, appels simultanés ; publication consignée dans l'ADR du module dédié.

## [1.0.0-beta2] — 15/10/2026

### Corrigé

- #2 `drush healthcheck:run --format=json` : `critical` est un booléen, et non plus « oui »/« non ». Le tableau garde l'affichage oui/non.
- #2 La vérification `files` ne corrige plus les droits de `public://.healthcheck` : un dossier non inscriptible rend `failed` au lieu d'être réparé sans bruit. ADR `0002-plugin-files-ne-corrige-pas-les-droits`, qui amende `0001-plugin-files-ecrit-dans-public`.

### Documentation

- #2 Durée d'un appel en panne (Redis, base tierce, `PDO::ATTR_TIMEOUT`) et piège DDEV du nom `redis` partagé entre projets.

## [1.0.0-beta1] — 15/10/2026

### Ajouté

- #1 Point de santé `/health` extensible par plugins : type de plugin `#[HealthCheck]` (`applies()`, `check()`, critique ou non) et vérifications `database`, `redis`, `search_api` (non critique) et `files` (écriture dans `public://.healthcheck`). Verdict JSON `ok`/`failed` en 200/503, gardé 30 s, sans détail dans le corps. Réglages par site dans `healthcheck.settings`, commande `drush healthcheck:run`, script de sonde post-déploiement `scripts/ci-health.sh`, CI GitLab (phpcs, phpstan, phpunit). ADR `0001-infra-module-dedie-et-nom-machine-healthcheck`, `0001-plugin-contrat-du-type-de-plugin-et-du-json` `0001-plugin-files-ecrit-dans-public` et `0001-infra-docs-de-ticket-hors-du-depot-public` (docs de ticket hors du dépôt public).
