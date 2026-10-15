# Documentation — Healthcheck

Module Drupal `kgaut/healthcheck` : un point de santé `/health`, extensible par
plugins. Le [README du dépôt](../README.md) donne l'installation et les
réglages, et les pages ci-dessous le reste.

## Exploitation

- [Exploitation — Point de santé](exploitation/point-de-sante.md) : ce que
  prouve un 200 et ce qu'il ne prouve pas, la fenêtre, le journal, la
  maintenance, la vérification à la main.
- [Exploitation — Sonde post-déploiement](exploitation/sonde-post-deploiement.md) :
  `scripts/ci-health.sh` et un exemple de job GitLab.

## Développement

- [Dev — Écrire une vérification](dev/ecrire-une-verification.md) : le type de
  plugin `#[HealthCheck]`, pour un module tiers.
- [Dev — Tests et CI](dev/tests-et-ci.md) : la QA du module, en local et en CI.

## Décisions (ADR)

Le dossier [adr/](adr/) ne contient que les décisions en vigueur :

- [`0001-infra-module-dedie-et-nom-machine-healthcheck`](adr/0001-infra-module-dedie-et-nom-machine-healthcheck.md) ;
- [`0001-plugin-contrat-du-type-de-plugin-et-du-json`](adr/0001-plugin-contrat-du-type-de-plugin-et-du-json.md) ;
- [`0001-plugin-files-ecrit-dans-public`](adr/0001-plugin-files-ecrit-dans-public.md) ;
- [`0001-infra-docs-de-ticket-hors-du-depot-public`](adr/0001-infra-docs-de-ticket-hors-du-depot-public.md).

## Tickets

Les docs de ticket ne sont pas dans ce dépôt public : ils vivent sur les
tickets de la forge (ADR `0001-infra-docs-de-ticket-hors-du-depot-public`).
