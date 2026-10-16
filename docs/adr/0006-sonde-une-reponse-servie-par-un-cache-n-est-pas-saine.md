# 0006 — Pour la sonde de CI, une réponse servie par un cache n'est pas saine

- **Statut** : En vigueur
- **Date** : 16/10/2026
- **Ticket** : #6
- **Périmètre** : `scripts/ci-health.sh`, documentation d'exploitation
- **Remplace** : —
- **Amende** : —
- **Complète** : —

## Contexte

Le module envoie `Cache-Control: no-store, private` sur `/health` et la route
est `no_cache` : Drupal ne la garde pas, un navigateur ou un proxy qui
respecte les en-têtes non plus.

**Mesuré le 16/10/2026, sur un site de production derrière Cloudflare** : une
règle de cache de la zone (« Cache Everything », durée imposée) **réécrit**
l'en-tête en `public, max-age=31536000` et sert `/health` depuis le cache :
`cf-cache-status: HIT`, `age` jusqu'à 7 951 s, corps `{"status":"ok",…}`.
`X-Drupal-Cache: UNCACHEABLE`, envoyé par l'origine, n'y change rien. La
supervision et la sonde de CI lisent donc un `ok` de plus de deux heures :
une panne du site ne se verrait pas.

**Supposé, non mesuré** : d'autres CDN (Fastly, CloudFront, Varnish
mutualisé) peuvent faire de même selon leur configuration ; ils signalent un
succès de cache par `X-Cache` ou `Age`.

Le module ne peut rien y faire : la règle du CDN passe outre ses en-têtes.

## Décision

1. **`scripts/ci-health.sh` ne rend jamais vert une réponse servie par un
   cache**, même `200` avec `"status":"ok"`. Signes lus dans les en-têtes de
   la réponse : `Age` supérieur à 0 ; `cf-cache-status` à `HIT`, `STALE`,
   `UPDATING` ou `REVALIDATED` ; `X-Cache` contenant `HIT`. Une telle réponse
   compte comme un essai raté (les essais suivants peuvent passer, si le cache
   a été vidé entre-temps), et le message d'échec nomme l'en-tête en cause et
   renvoie à la documentation.
2. **La correction est chez l'exploitant** : une règle « Bypass cache » sur
   `/health` dans le CDN, documentée dans
   `docs/exploitation/point-de-sante.md`, section « Derrière un CDN », avec la
   vérification à la main.

Limites : seuls ces en-têtes sont lus. Un cache qui ne signale rien (ni `Age`
ni en-tête de statut) n'est pas détecté.

## Options écartées

- **Un paramètre aléatoire dans l'URL de la sonde** (`/health?t=…`) : il
  contourne le cache de beaucoup de règles, mais pas d'une règle qui ignore la
  query string, et il casse l'exemption d'authentification HTTP documentée
  (`%{THE_REQUEST}` n'admet que `/health` exact). Il masquerait aussi le
  problème pour Kuma, qui interroge la même URL sans paramètre.
- **Une variable pour accepter une réponse en cache** (avec un
  avertissement) : un job vert sur un verdict ancien est précisément ce qu'on
  corrige. Un site qui ne peut pas encore exclure `/health` de son CDN
  désactive le job, ce qui se voit, plutôt que de le garder vert.
- **Ajouter d'autres en-têtes côté module** (`CDN-Cache-Control`,
  `Cloudflare-CDN-Cache-Control: no-store`) : sans effet face à une règle qui
  impose sa durée, et non mesuré. Rien ne garantit qu'un CDN les respecte.
- **Échouer dès le premier essai en cache** : plus rapide, mais une purge du
  CDN pendant le déploiement rendrait le job rouge alors que l'essai suivant
  aurait été frais.

## Conséquences

- Sur un site derrière un CDN qui garde `/health`, la sonde de CI devient
  rouge tant que la règle n'est pas posée. C'est voulu.
- Le contrôle vaut pour le script du module. Les dépôts qui embarquent leur
  propre copie de la sonde (templates de CI) doivent reprendre la même règle.
- Rien ne vérifie Uptime Kuma : un moniteur « mot-clé » reste vert sur une
  réponse en cache. La règle du CDN est la seule protection de ce côté.

## Suivi

- 16/10/2026 : écrite au ticket #6, après la mesure sur un site de production.
