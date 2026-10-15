# 0002 — La vérification `files` ne corrige pas les droits du dossier qu'elle mesure

- **Statut** : Complétée par [`0003-plugin-verdicts-surs-sous-concurrence-et-hors-du-serveur-web`](0003-plugin-verdicts-surs-sous-concurrence-et-hors-du-serveur-web.md) : en vigueur ; la racine du schéma n'est jamais recréée, et la commande drush ne crée pas le dossier
- **Date** : 15/10/2026
- **Ticket** : #2
- **Périmètre** : plugin `files`
- **Remplace** : —
- **Amende** : [`0001-plugin-files-ecrit-dans-public`](archives/0001-plugin-files-ecrit-dans-public.md)
- **Complète** : —

## Contexte

La `1.0.0-beta1` préparait le dossier du témoin avec `CREATE_DIRECTORY` et
`MODIFY_PERMISSIONS` : « préparer le dossier (création, droits) », selon
l'ADR amendée.

**Mesuré à la recette de la beta1, en local, le 15/10/2026** : après un
`chmod 555` sur `public://.healthcheck`, le passage suivant remet des droits
d'écriture sans rien signaler, et `files` reste `ok`. La sonde répare ce
qu'elle est censée mesurer : un dossier devenu non inscriptible ne se voit
jamais.

## Décision

Le dossier est **créé** s'il manque (`CREATE_DIRECTORY`). Ses droits ne sont
**jamais** modifiés. Un dossier qui existe sans être inscriptible rend
`failed`, avec la cause au journal. Le reste de l'ADR amendée vaut toujours :
dossier `public://.healthcheck` réglable, reliquats nettoyés, témoin
supprimé quoi qu'il arrive, une écriture par fenêtre.

## Options écartées

- **Garder `MODIFY_PERMISSIONS` et le documenter** : la vérification dirait
  `ok` sur un disque que le site ne peut plus écrire, dès que le dossier du
  témoin est seul en cause.
- **Corriger les droits et rendre `warning`** : un écart de droits n'est pas
  une panne passagère, et la sonde n'a pas à agir sur le serveur.

## Conséquences

- Un `chmod` ou un changement de propriétaire sur `.healthcheck` fait passer
  le site en 503 jusqu'à ce qu'un opérateur le corrige. C'est voulu.
- **Interdit désormais** : toute écriture de la vérification hors du témoin
  et de la création du dossier.
- Sous root, un dossier en lecture seule reste inscriptible : le test qui le
  couvre est sans objet dans ce cas.

## Suivi

- 15/10/2026 : rédigée au ticket #2, sur un retour de recette.
- 15/10/2026 : complétée par `0003-plugin-verdicts-surs-sous-concurrence-et-hors-du-serveur-web` (#3).
