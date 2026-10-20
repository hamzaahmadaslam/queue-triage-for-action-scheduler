#!/usr/bin/env bash
# The checks that need no WordPress install: PHP syntax, coding standards, unit tests and the build.
# The integration test needs a test WordPress site: WP_PATH=/path/to/wordpress bash tests/integration.sh
set -euo pipefail
cd "$(dirname "$0")/.."

for f in queue-triage-for-action-scheduler.php uninstall.php includes/*.php tests/fixtures/*.php tests/unit/*.php; do
  php -l "$f" >/dev/null
done
echo "php -l: ok"

if command -v composer >/dev/null 2>&1; then
  composer install --no-interaction --no-progress --quiet
else
  php "${COMPOSER_PHAR:?Install composer or set COMPOSER_PHAR}" install --no-interaction --no-progress --quiet
fi
vendor/bin/phpcs -q
echo "phpcs: ok"
vendor/bin/phpunit
bash bin/build.sh
