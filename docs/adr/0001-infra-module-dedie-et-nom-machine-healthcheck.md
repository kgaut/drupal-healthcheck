# 0001 — Un module dédié, `kgaut/healthcheck`, de nom machine `healthcheck`

- **Statut** : En vigueur
- **Date** : 15/10/2026
- **Ticket** : #1
- **Périmètre** : infra, sites Drupal 11 maintenus par l'auteur
- **Remplace** : —
- **Amende** : —
- **Complète** : —

## Contexte

**Mesuré le 15/10/2026.**

- Incident de production sur un site client, le 15/10/2026 : le volume qui
  portait le code et les fichiers a disparu, le site répondait 500, et
  aucune sonde HTTP ne l'a vu. Aucun des six sites Drupal 11 visés n'a de
  point de santé. Tous sont en `drupal/core` 11.4, aucun en 11.0, et l'un
  d'eux force `platform.php` à 8.3.0.
- Le seul point de santé existant dans le parc est celui du module `uzinasit`
  (Kgaut.NET/uzinasit.kgaut.net, ADR
  `0972-infra-le-point-de-sante-verifie-la-base-et-redis-au-plus-une-fois-par-30-secondes`).
  Il vit dans un module transverse propre à la fabrique uzinasit et ne
  vérifie que la base et Redis, sans extension possible.
- `drupal/health_check` 3.2.0 (drupal.org, « Minimally maintained ») sert
  `/health`, mais ne rend qu'un horodatage avec un 200 : il ne vérifie aucun
  service. Il ne voit une panne que si Drupal ne démarre pas.
- `kgaut/kgaut_tools` 2.0.24 (Packagist) exige `drupal/pathauto: *`.
- Le projet drupal.org `healthcheck` existe : `project_status`
  `unsupported`, dernière version 2.0.0 (octobre 2020).

## Décision

Le point de santé est un **module dédié**, publié comme paquet Composer
**`kgaut/healthcheck`** (type `drupal-module`, dépôt
`Kgaut.NET/drupal-healthcheck`), de **nom machine `healthcheck`**, espace de
noms `Drupal\healthcheck`. Il exige `drupal/core: ^11.1` (premier 11.x où
les plugins se déclarent par attribut PHP sans repli) et PHP 8.3, la version
des sites cibles. Il ne dépend d'aucun module contrib. Redis et Search API ne
sont vérifiés que si leurs modules sont présents.

Kevin a choisi ce nom machine malgré le projet drupal.org homonyme. Le risque
est assumé : l'abandonné `healthcheck` ne peut pas cohabiter avec ce module
(même nom machine, même espace de noms), mais aucun site du parc ne
l'utilise, et rien ne justifie d'en installer une version non maintenue
depuis 2020.

## Options écartées

- **`drupal/health_check`** : il ne vérifie rien au-delà du démarrage, et
  n'est pas extensible. Il aurait vu l'incident du 15/10 (un 500), mais ni
  une base tombée après le démarrage, ni Redis, ni Solr, ni un disque en
  lecture seule.
- **Un sous-module de `kgaut/kgaut_tools`** : il tirerait `drupal/pathauto`
  sur chaque site qui veut un point de santé, et lierait le cycle de version
  du point de santé à celui d'une boîte à outils.
- **Copier le code d'uzinasit dans chaque site** : six copies divergeraient
  dès le premier correctif, et chacune devrait reporter ses tests.
- **Un autre nom machine** (`kgaut_healthcheck`, `health`) : écarté par
  Kevin au profit de `healthcheck`, le nom attendu par qui cherche le module.

## Conséquences

- Un site s'équipe par `composer require kgaut/healthcheck` puis
  `drush en healthcheck`, et reçoit `/health` sans autre réglage.
- ⚠️ Le module ne peut pas être installé avec le projet drupal.org
  `healthcheck` (conflit de nom machine). Il ne doit pas non plus l'être avec
  `drupal/health_check`, ni avec un autre module qui déclare `/health` (le
  module `uzinasit` par exemple) : deux routes sur le même chemin, et une
  seule répond.
- La publication (miroir GitHub `kgaut/drupal-healthcheck`, Packagist) reste
  à faire, sur le go de Kevin : rien ne la garantit encore.

## Suivi

- 15/10/2026 : rédigée au ticket #1, à la création du module.
- 15/10/2026 : publié le jour même sur go de Kevin. Le dépôt GitHub public
  `kgaut/drupal-healthcheck` (branche `1.0.x`) est enregistré sur Packagist,
  et la forge reste privée. La « publication reste à faire » des
  Conséquences est donc faite (#3).
