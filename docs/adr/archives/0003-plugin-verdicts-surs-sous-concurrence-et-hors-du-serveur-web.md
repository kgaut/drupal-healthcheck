# 0003 — Des verdicts sûrs sous concurrence, en ligne de commande et sans aucune vérification

- **Statut** : Amendée par [`0004-plugin-files-en-ligne-de-commande-avant-le-premier-appel-web`](../0004-plugin-files-en-ligne-de-commande-avant-le-premier-appel-web.md) : en ligne de commande, un dossier du témoin pas encore créé rend `not_configured` et non plus `failed`
- **Date** : 15/10/2026
- **Ticket** : #3
- **Périmètre** : plugin, vérificateur, vérification `files`, commande drush
- **Remplace** : —
- **Amende** : —
- **Complète** : [`0001-plugin-contrat-du-type-de-plugin-et-du-json`](../0001-plugin-contrat-du-type-de-plugin-et-du-json.md), [`0002-plugin-files-ne-corrige-pas-les-droits`](../0002-plugin-files-ne-corrige-pas-les-droits.md)

## Contexte

Relecture de la `1.0.0-beta2` le 15/10/2026, faite en lisant le code, sans
l'exécuter. Les défauts ont été reproduits ensuite par des tests kernel, au
ticket #3 :

- **Deux vérifications `files` simultanées** se nuisent. C'est le cas de la
  route à l'expiration de la fenêtre, ou de la route et de drush. L'une
  supprime le témoin tout juste écrit par l'autre, qu'elle prend pour un
  reliquat. L'autre rend alors un faux `failed`, gardé toute la fenêtre.
- **La commande drush lancée sous un autre utilisateur** que le serveur web
  crée `.healthcheck` à son nom. Depuis l'ADR 0002, rien ne corrige plus ces
  droits.
- **Un dossier de fichiers disparu est recréé**, vide, par le `mkdir`
  récursif de `prepareDirectory()`, et `files` rend `ok`. C'est le cas même
  d'un volume détaché.
- **`files_directory: 'public://'`** exposerait des fichiers du site au
  nettoyage des reliquats.
- **Le verdict gardé** ne dépend que de la liste des vérifications : un
  changement de criticité n'agit qu'à la fenêtre suivante.
- **Aucune vérification active** rend `200 {"status":"ok","checks":{}}`.

## Décision

1. **Reliquats** : `files` ne supprime un `probe-*.txt` que s'il est plus
   vieux que la fenêtre, et au moins qu'une minute. Un témoin plus récent
   appartient à une vérification en cours.
2. **Ligne de commande** : le vérificateur transmet à chaque plugin un
   contexte `cli` (`run(TRUE)`, dans la configuration du plugin). Un plugin
   n'y crée rien de durable. `files` n'y crée pas son dossier : absent, il
   rend `failed` en donnant la cause.
3. **Racine du schéma** : `files` exige que la racine de son schéma
   (`public://`) existe, et ne la recrée jamais. Seul le sous-dossier du
   témoin peut être créé, et la racine elle-même est refusée comme dossier
   du témoin.
4. **Verdict gardé** : il vaut pour une liste de vérifications actives **et**
   leur criticité. Un changement de l'une ou de l'autre fait revérifier tout
   de suite.
5. **Aucune vérification active** donne le statut global `failed` (503), et
   une entrée `error` au journal par fenêtre. Un point de santé qui ne
   vérifie rien ne prouve rien.

## Options écartées

- **Un verrou autour de la vérification**, contre la course des témoins : il
  ajouterait une écriture et un point de panne à chaque fenêtre (cf.
  ADR 0001). Un seuil d'âge suffit, car un témoin vit quelques
  millisecondes.
- **Ignorer `files` en ligne de commande** : la commande ne vérifierait plus
  l'écriture, alors qu'elle sert justement en CI et après un déploiement.
- **Créer le dossier en ligne de commande, puis corriger ses droits** :
  contraire à l'ADR 0002.
- **Rendre `ok` sans vérification active, avec un avertissement** : une
  sonde qui cherche `"status":"ok"` ne lit pas les avertissements.

## Conséquences

- Juste après une installation, `drush healthcheck:run` rend `files:
  failed` tant que `/health` n'a pas été appelé une fois.
- Un témoin resté sur le disque (suppression refusée) n'est nettoyé qu'une
  minute plus tard au plus tôt. La vérification est `failed` entre-temps,
  puisque la suppression a échoué.
- **Interdit désormais** : recréer la racine d'un schéma, créer en ligne de
  commande un fichier ou un dossier qui survit à la vérification, et rendre
  `ok` sans aucune vérification.

## Suivi

- 15/10/2026 : rédigée au ticket #3, sur une relecture de la beta2.
- 15/10/2026 : amendée par `0004-plugin-files-en-ligne-de-commande-avant-le-premier-appel-web` (#4).
