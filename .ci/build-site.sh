#!/usr/bin/env bash
#
# build-site.sh — monte un Drupal de test autour du module, pour la QA.
#
# Le site est construit HORS du dépôt ($SITE_DIR, /tmp/site par défaut) et le
# module y est lié par un lien symbolique : un site construit dans le dépôt
# se contiendrait lui-même par ce lien.
#
# Les modules optionnels (redis, search_api, redirect) sont installés pour
# que leurs tests tournent et que phpstan connaisse leurs classes ; drush
# pour la commande `healthcheck:run`.

set -euo pipefail

MODULE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SITE_DIR="${SITE_DIR:-/tmp/site}"
DRUPAL_CORE="${DRUPAL_CORE:-^11.1}"

composer create-project "drupal/recommended-project:$DRUPAL_CORE" "$SITE_DIR" --no-install --no-interaction --quiet
cd "$SITE_DIR"
composer config allow-plugins true
composer require --no-interaction --no-progress -W \
  "drupal/core-dev:$DRUPAL_CORE" \
  'drush/drush:^13' \
  'drupal/redis:^1.8' \
  'drupal/search_api:^1.35' \
  'drupal/redirect:^1.10'
mkdir -p web/modules/custom
ln -sfn "$MODULE_DIR" web/modules/custom/healthcheck
cp web/core/phpunit.xml.dist web/core/phpunit.xml
echo "Site de test prêt : $SITE_DIR (drupal/core $(composer show drupal/core --format=json | php -r 'echo json_decode(stream_get_contents(STDIN))->versions[0];'))"
