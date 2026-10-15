# 0001 — La vérification `files` écrit dans `public://.healthcheck`, une fois par fenêtre

- **Statut** : Amendée par [`0002-plugin-files-ne-corrige-pas-les-droits`](../0002-plugin-files-ne-corrige-pas-les-droits.md) : le dossier est créé s'il manque, ses droits ne sont plus corrigés
- **Date** : 15/10/2026
- **Ticket** : #1
- **Périmètre** : plugin `files`
- **Remplace** : —
- **Amende** : —
- **Complète** : [`0001-plugin-contrat-du-type-de-plugin-et-du-json`](../0001-plugin-contrat-du-type-de-plugin-et-du-json.md)

## Contexte

Lors d'un incident de production sur un site client, le 15/10/2026, un
volume a disparu. **Mesuré** : sur ce serveur, les fichiers publics et
`vendor/` sont sur ce même volume. Le site répondait donc 500, et la route
n'aurait pas démarré. Le
point de santé d'uzinasit ne vérifie que des lectures (`SELECT 1`, `PING`) et
ne prouve rien de l'écriture (ADR `0972-…` d'uzinasit, « ce qu'un 200 ne
prouve pas »).

**Supposé, non mesuré sur chaque serveur** : `temporary://` pointe souvent
sur `/tmp`, c'est-à-dire sur un autre volume que les fichiers du site. Un
test d'écriture dans `temporary://` dirait « ok » pendant que le disque des
fichiers est perdu ou en lecture seule.

## Décision

La vérification `files` (critique) écrit un témoin dans
**`public://.healthcheck`** par défaut. Le dossier se règle par
`healthcheck.settings:files_directory`, surchargeable dans `settings.php`.

Déroulé, à chaque vérification :

1. préparer le dossier (création, droits). Un échec rend `failed` ;
2. supprimer tout reliquat `probe-*.txt` d'un passage précédent. Un reliquat
   impossible à supprimer rend `failed` ;
3. écrire `probe-<aléa>.txt` (contenu aléatoire), le relire, puis le
   **supprimer quoi qu'il arrive**. Une écriture incomplète, une relecture
   différente ou un témoin encore présent après suppression rendent
   `failed`.

Elle est jouée au plus une fois par fenêtre, sous la même garde que les
autres vérifications. Une rafale d'appels n'écrit donc pas à chaque appel.

## Options écartées

- **`temporary://`** : il vit souvent sur un autre volume que les fichiers du
  site, et n'aurait pas vu leur perte. Il reste possible par site
  (`files_directory: 'temporary://…'`).
- **`private://`** : beaucoup de sites n'en ont pas, et la vérification
  serait `not_configured` partout où elle a le plus de sens.
- **Un nom de fichier fixe, réécrit à chaque fois** : il resterait sur le
  disque entre deux passages, et une suppression qui échoue ne se verrait
  pas.
- **Écrire à chaque appel**, hors de la fenêtre : une rafale sur une adresse
  publique deviendrait une rafale d'écritures disque.

## Conséquences

- Le dossier `.healthcheck` apparaît dans les fichiers publics de chaque
  site. Il est vide entre deux passages, sauf si une suppression a échoué :
  le point de santé est alors déjà en 503.
- Le témoin est lisible par le web pendant quelques millisecondes, à une
  adresse aléatoire. Son contenu est aléatoire et ne porte rien.
- Un serveur dont le volume des fichiers est **plein** ou en **lecture
  seule** passe en 503 dans la fenêtre suivante.
- Rien ne vérifie un stockage distant (S3, flysystem) : aucun site cible n'en
  a au 15/10/2026. Un tel site réglera `files_directory` sur son schéma.

## Suivi

- 15/10/2026 : rédigée au ticket #1. Dossier `public://` et nettoyage des
  reliquats validés avec les mainteneurs du premier site cible.
- 15/10/2026 : amendée par `0002-plugin-files-ne-corrige-pas-les-droits` (#2).
