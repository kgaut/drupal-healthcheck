# 0001 — Le contrat du type de plugin `#[HealthCheck]` et du corps JSON de `/health`

- **Statut** : En vigueur
- **Date** : 15/10/2026
- **Ticket** : #1
- **Périmètre** : plugin, API publique du module
- **Remplace** : —
- **Amende** : —
- **Complète** : —

## Contexte

Le point de santé d'uzinasit (ADR `0972-…`, `1136-…` et `1254-…` de
Kgaut.NET/uzinasit.kgaut.net) est le modèle. Il est figé sur deux
vérifications, base et Redis. Il fixe un contrat déjà lu par des sondes :
`200` ou `503`, et un corps `{"status":"ok"|"failed","checks":{…}}`. Une
sonde ne tient pour sain qu'un 200 portant `"status":"ok"`, sans suivre les
redirections. Le job post-déploiement d'uzinasit (#1254) et un moniteur
Uptime Kuma en dépendent.

**Mesuré sur les six sites cibles le 15/10/2026** : trois utilisent Redis,
deux Search API (Solr), aucun un stockage de fichiers distant, et deux n'ont
ni Redis ni Search API. L'un a besoin d'une vérification métier, fournie par
son propre module.

**Pièges relevés par uzinasit le 15/10/2026** :

- le cache de pages ignore `max-age`, se contourne par `?x` ou par un cookie,
  et ne garde jamais une 5xx ;
- en maintenance, l'anonyme doit recevoir le 503 du cœur ;
- le module `redirect` renvoie une 301 vers `/fr/health` sur un site
  multilingue ;
- la fabrique Redis retombe sur 127.0.0.1 sans connexion déclarée ;
- une panne franche au démarrage donne un 500, pas un 503.

La session uzinasit demande de garder le contrat JSON tel quel : son
remplacement par ce module est envisagé (uzinasit#1263), sans décision.

## Décision

**Le type de plugin.** Un module déclare une vérification par une classe de
`src/Plugin/HealthCheck/` portant
`#[HealthCheck(id, label, critical = TRUE, weight = 0)]` et implémentant
`HealthCheckInterface` :

- `applies(): bool` dit, sans rien contacter, si le site utilise le service.
  `FALSE` rend `not_configured`, et `check()` n'est pas appelée ;
- `check(): CheckResult` interroge réellement le service et rend
  `CheckResult::ok()` ou `CheckResult::failed($cause)`. Elle peut lever : toute
  exception, et toute erreur PHP, vaut un échec ;
- la cause va au journal (canal `healthcheck`) et dans la sortie de
  `drush healthcheck:run`, **jamais** dans le corps HTTP ;
- chaque vérification borne elle-même sa durée (délai de connexion du
  client). Le module ne l'interrompt pas : PHP ne sait pas interrompre un
  appel bloquant de l'extérieur.

L'identifiant est la clé du verdict dans le corps : c'est un contrat, il ne se
renomme pas.

**Les verdicts.** Par vérification : `ok`, `failed` (échec d'une vérification
critique), `warning` (échec d'une vérification non critique), et
`not_configured`. Le statut global vaut `failed` dès qu'une vérification est
`failed`, `ok` sinon, avec un `200` si `ok` et un `503` si `failed`. Il n'y a
**pas** de statut global `warning` : la sonde reste binaire.

**Le corps** : `{"status":…,"checks":{"<id>":"<verdict>",…}}`, dans l'ordre des
poids puis des identifiants (`database` 0, `redis` 10, `search_api` 20,
`files` 30). Il ne contient rien d'autre : ni hôte, ni chemin, ni version, ni
durée, ni message. La réponse est une `JsonResponse` avec
`Cache-Control: no-store` et l'option de route `no_cache`, sur une route
`GET` (et `HEAD`) anonyme, `_format: json`, `_disable_route_normalizer`, sans
`_maintenance_access`.

**La fenêtre.** Le verdict de la route est gardé au plus `window` secondes
(30 par défaut) dans le bac de cache par défaut. La fenêtre part de la
vérification et ne glisse pas à la lecture. Toutes les vérifications sont
jouées ensemble, une fois par fenêtre, sans verrou. Un magasin illisible ne
vaut pas verdict : tout est revérifié. Un verdict gardé sous une autre liste
de vérifications actives ne vaut plus. Un échec est journalisé à chaque
vérification, donc au plus une fois par fenêtre : en `error` s'il est
critique, en `warning` sinon. Une réussite ne s'écrit jamais.

**Les réglages par site** vivent dans la config `healthcheck.settings` :
`window`, `files_directory`, et `checks.<id>.enabled|critical`. Un réglage
propre à un environnement passe par la surcharge standard de Drupal dans
`settings.php` (`$config['healthcheck.settings'][…]`). Les plugins fournis
sont critiques, sauf `search_api`.

**La commande** `drush healthcheck:run` (alias `hcr`) joue les mêmes
vérifications, mais **à chaque appel** : elle ne lit ni n'écrit le verdict
gardé, ne journalise rien, affiche la cause de chaque échec et accepte
`--format=json`. Elle sort en code 1 si une vérification critique échoue.

## Options écartées

- **Un statut global `warning` ou `degraded`** : une sonde qui cherche
  `"status":"ok"` le lirait comme une panne, et le contrat d'uzinasit
  casserait. Le `warning` reste au niveau de chaque vérification.
- **Garder la fenêtre dans le cache de pages** (`max-age`) : il ignore
  `max-age`, se contourne par `?x` ou par un cookie, et ne garde jamais une
  5xx.
- **Une clé parallèle `$settings['healthcheck']`** en plus de la config :
  deux sources de vérité pour le même réglage. La surcharge `$config` couvre
  déjà le besoin.
- **Un délai imposé par le module à chaque vérification** : rien ne permet
  d'interrompre un appel PHP bloquant (`pcntl_alarm` n'existe pas sous
  PHP-FPM). Le délai appartient au client de chaque service.
- **Un verrou autour de la vérification** : des appels simultanés à
  l'expiration vérifient chacun. C'est la borne assumée par uzinasit. Un
  verrou ajouterait une écriture et un point de panne sur chaque fenêtre.
- **Une commande drush qui lit le verdict gardé** : la CI et l'opérateur
  veulent l'état présent, pas celui d'il y a 29 secondes.
- **Les causes dans le corps, derrière un jeton** : un jeton se perd dans un
  moniteur ou un journal de CI. La commande drush donne les causes à qui a
  accès au serveur.

## Conséquences

- Un module tiers ajoute une vérification sans toucher à ce module. Sa clé
  apparaît dans le corps dès son activation. Une clé en plus sous `checks`
  ne casse pas une sonde qui lit `"status":"ok"`.
- **Interdit désormais** : renommer un identifiant de plugin livré, ajouter
  un statut global autre que `ok` ou `failed`, mettre un détail dans le
  corps, ou ajouter `_maintenance_access` à la route.
- Rien ne garantit qu'une vérification tierce borne sa durée. Une
  vérification lente ralentit la route une fois par fenêtre, et la commande
  drush à chaque appel.
- Une panne qui empêche Drupal de démarrer donne un 500 que le module ne
  formate pas. C'est pourquoi la sonde exige le mot-clé.
- Le remplacement du `/health` d'uzinasit par ce module reste possible sans
  changer de contrat. Le corps aura des clés en plus (`search_api`, `files`).

## Suivi

- 15/10/2026 : rédigée au ticket #1. Les points ouverts ont été tranchés avec
  les mainteneurs du premier site cible et d'uzinasit : `search_api` non critique, pas de
  statut global `warning`, commande drush calculée à chaque appel,
  Drupal ^11.1.
