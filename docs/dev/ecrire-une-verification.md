# Dev — Écrire une vérification

Décision : ADR [`0001-plugin-contrat-du-type-de-plugin-et-du-json`](../adr/0001-plugin-contrat-du-type-de-plugin-et-du-json.md).

N'importe quel module peut ajouter une vérification au point de santé, sans
toucher au module `healthcheck` : une classe dans `src/Plugin/HealthCheck/`,
portant l'attribut `#[HealthCheck]`.

## L'attribut

```php
#[HealthCheck(
  id: 'partenaire',                                 // clé dans le corps : un contrat, ne pas la renommer
  label: new TranslatableMarkup('API partenaire'),  // pour drush et le journal
  critical: TRUE,                                   // un échec → 503 ; FALSE → `warning`, 200
  weight: 40,                                       // ordre dans le corps (database 0, redis 10, search_api 20, files 30)
)]
```

L'identifiant ne contient que des minuscules ASCII, des chiffres et des `_`.

## Le contrat

Étendre `HealthCheckBase`, qui s'applique toujours et prend le libellé de
l'attribut, puis implémenter :

- **`applies(): bool`** (facultatif) : le site utilise-t-il ce service ? On
  lit la configuration ou les réglages, et **on ne contacte rien**. `FALSE`
  rend `not_configured`, et `check()` n'est pas appelée. C'est ce qui évite de
  vérifier un service par défaut que le site n'utilise pas (comme Redis sur
  127.0.0.1) ;
- **`check(): CheckResult`** : interroger réellement le service, puis rendre
  `CheckResult::ok()` ou `CheckResult::failed('cause')`. Elle **peut lever** :
  toute exception, et toute erreur PHP, vaut `failed`, avec sa classe et son
  message au journal.

Règles :

- la cause n'est jamais montrée dans le corps HTTP. Elle va au journal et
  dans `drush healthcheck:run`. Elle peut nommer un hôte ou un chemin, mais
  **jamais un secret** (mot de passe, jeton, URL signée) ;
- **borner la durée** : un délai de connexion court sur le client du
  service (2 à 5 secondes). Le module ne peut pas interrompre un appel
  bloquant, et une vérification lente ralentit la route une fois par
  fenêtre ;
- `check()` est appelée au plus une fois par fenêtre (30 s) par la route, et
  à chaque appel par la commande drush. Elle ne doit rien laisser derrière
  elle (fichier, ligne en base, entrée de cache) ;
- les services s'injectent par `create()`, comme pour tout plugin.

## Exemple : brancher une vérification existante

Un module métier, `partenaire`, a déjà un service de vérification,
`partenaire.health_check`, dont `check(int $maxDelay)` rend un résultat par
sous-système : `array<string, array{ok: bool, message: string}>`.
⚠️ `$maxDelay` n'est **pas** un délai de connexion. C'est le retard maximal
toléré, en secondes, pour la synchronisation et les suppressions : 21 600 par
défaut (`PartenaireHealthCheck::DEFAULT_MAX_DELAY`, 6 h). Avec `check(3)`, la
synchro passerait en échec dès qu'un compte attend plus de 3 s, donc en permanence.

```php
<?php

declare(strict_types=1);

namespace Drupal\partenaire\Plugin\HealthCheck;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\partenaire\PartenaireHealthCheck as PartenaireChecks;
use Drupal\healthcheck\Attribute\HealthCheck;
use Drupal\healthcheck\CheckResult;
use Drupal\healthcheck\HealthCheckBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[HealthCheck(id: 'partenaire', label: new TranslatableMarkup('Base partenaire'), critical: FALSE, weight: 40)]
final class Partenaire extends HealthCheckBase {

  protected PartenaireChecks $checks;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->checks = $container->get('partenaire.health_check');
    return $instance;
  }

  public function check(): CheckResult {
    $failed = [];
    foreach ($this->checks->check(PartenaireChecks::DEFAULT_MAX_DELAY) as $name => $result) {
      if (!$result['ok']) {
        $failed[] = $name . ' : ' . $result['message'];
      }
    }
    return $failed === [] ? CheckResult::ok() : CheckResult::failed(implode(' ; ', $failed));
  }

}
```

**Un service externe déjà surveillé ailleurs se déclare plutôt non
critique** (`critical: FALSE`). Sa panne rend `warning` et laisse le site en
200. Sinon, une base tierce injoignable met tout le site en 503 alors qu'il
tourne : c'est arrivé sur un site le 15/10/2026, avec une base tierce.

Le **délai de connexion court** ne se règle pas dans `check()`, mais sur le
client du service. Pour une base externe, il se pose dans le `settings.php`
du site, sur sa connexion (ici `partenaire`) :
`'pdo' => [\PDO::ATTR_TIMEOUT => 3]`. C'est au projet de le poser : sans lui,
une base injoignable fait attendre la route jusqu'au délai par défaut du
pilote.

## Modifier une vérification existante

`hook_healthcheck_info_alter(array &$definitions)` modifie les définitions
(classe, poids, criticité par défaut). Pour un réglage propre à un site,
préférer la config : `healthcheck.settings:checks.<id>.critical|enabled`.

## Tester

Le module de test `healthcheck_probe_test` (`tests/modules/`) montre une
vérification tierce pilotée par l'état, et `HealthPluginApiTest` la manière
de la tester par le noyau HTTP.
