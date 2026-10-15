# 0004 — En ligne de commande, `files` rend `not_configured` tant que la route n'a pas créé son dossier

- **Statut** : En vigueur
- **Date** : 15/10/2026
- **Ticket** : #4
- **Périmètre** : vérification `files`, commande drush
- **Remplace** : —
- **Amende** : [`0003-plugin-verdicts-surs-sous-concurrence-et-hors-du-serveur-web`](archives/0003-plugin-verdicts-surs-sous-concurrence-et-hors-du-serveur-web.md)
- **Complète** : —

## Contexte

L'ADR 0003 (point 2) veut qu'en ligne de commande, `files` ne crée pas son
dossier et rende `failed` s'il est absent.

**Mesuré à la bascule d'uzinasit sur la `1.0.0-beta3`, le 15/10/2026** :
juste après l'installation, tant que `/health` n'a pas été appelé en HTTP,
`drush healthcheck:run` rend `files: failed` et sort en code 1. Un script de
déploiement qui enchaîne la commande après l'import de configuration échoue
donc, alors qu'aucun service n'est en panne.

## Décision

En ligne de commande, quand la racine du schéma (`public://`) existe et que
le dossier du témoin n'existe pas encore, `files` **ne s'applique pas** :
`applies()` rend faux, le verdict est `not_configured` et la commande sort en
code 0. La route créera le dossier à son premier appel.

Le reste de l'ADR 0003 vaut toujours. En particulier, la commande ne crée
jamais le dossier, et une racine absente rend `failed`, en ligne de commande
comme par la route.

## Options écartées

- **Garder `failed` et le documenter** : chaque script de déploiement
  devrait appeler `/health` avant drush, ou ignorer le code de sortie, ce qui
  revient à ne plus vérifier.
- **Créer le dossier en ligne de commande** : il appartiendrait à
  l'utilisateur de drush, et rien ne corrige les droits (ADR 0002).
- **Un verdict nouveau (« pas encore créé »)** : il élargirait le contrat
  pour un cas que `not_configured` décrit déjà, puisque le site n'utilise
  pas encore ce dossier.

## Conséquences

- Juste après une installation, `drush healthcheck:run` est vert sans
  vérifier l'écriture. Le premier appel web, celui de la sonde, la vérifie.
- **Interdit désormais** : rendre `not_configured` quand la racine du schéma
  manque, car c'est la panne que le module doit voir.

## Suivi

- 15/10/2026 : rédigée au ticket #4, sur un retour de la bascule d'uzinasit.
