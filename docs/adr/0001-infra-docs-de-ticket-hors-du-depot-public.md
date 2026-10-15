# 0001 — Les docs de ticket ne sont pas versionnés dans ce dépôt public

- **Statut** : En vigueur
- **Date** : 15/10/2026
- **Ticket** : #1
- **Périmètre** : infra, documentation du dépôt
- **Remplace** : —
- **Amende** : —
- **Complète** : —

## Contexte

La règle commune aux projets de l'auteur versionne un doc par ticket dans
`docs/tickets/`. Ce dépôt est publié en public, pour Packagist, par un remote
GitHub (`kgaut/drupal-healthcheck`). Le dépôt de travail, sur la forge de
l'auteur, reste privé.

**Mesuré le 15/10/2026** : le doc du ticket #1 cite les sites clients qui
utiliseront le module, leurs versions et leurs services, ainsi que l'incident
de production qui a motivé le module. Une fois poussé en public, un commit ne
se retire plus de façon sûre : miroirs, caches, clones.

## Décision

Les docs de ticket ne sont **pas versionnés** dans ce dépôt. `docs/tickets/`
est ignoré par le `.gitignore`, versionné, pour suivre chaque clone. Le
contenu d'un doc de ticket est posé en commentaire du ticket, sur la forge
privée, et il y est tenu à jour.

Le reste de `docs/` (ADR, exploitation, dev) est versionné et public. Il ne
nomme aucun client, aucun hôte de production et aucun ticket de projet
client.

L'historique de `1.0.x` a été réécrit avant le premier push public, pour que
rien de ce qui précède ne soit publié.

## Options écartées

- **Anonymiser le doc de ticket et le versionner** : le doc de ticket sert
  justement à garder le contexte réel (sites, tickets liés). Anonymisé, il
  ne servirait plus.
- **Publier tel quel**, comme le fait un autre dépôt de l'auteur : des
  versions de production et l'architecture disque d'un client n'ont rien à
  faire dans un paquet public.
- **Rendre public le dépôt de la forge** : tickets et MR, qui citent les
  clients, deviendraient visibles aussi.

## Conséquences

- **Interdit désormais** : nommer un client, un hôte de production ou un
  ticket de projet client dans un fichier versionné.
- Rien ne le vérifie automatiquement : c'est une relecture à faire avant
  chaque push public.
- Le doc de ticket n'est plus publié par la synchronisation de documentation
  du tableau de bord, qui lit `docs/`.

## Suivi

- 15/10/2026 : décidée par Kevin au moment de publier, au ticket #1.
