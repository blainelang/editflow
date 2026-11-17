#!/usr/bin/env bash
# Run inside the Drupal project/runtime (for example via ddev exec).
# Tests require an explicitly supplied isolated test DB and reachable test URL.
set -euo pipefail
module_dir="$(cd "$(dirname "$0")/.." && pwd)"
# Locate Composer and Drupal without assuming custom/contrib or webroot names.
project_dir="$module_dir"
while [[ ! -f "$project_dir/vendor/autoload.php" ]]; do
  [[ "$project_dir" != / ]] || { echo "Cannot find the Drupal Composer project" >&2; exit 1; }
  project_dir="$(dirname "$project_dir")"
done
drupal_root="${DRUPAL_ROOT:-$("$project_dir/vendor/bin/drush" status --field=root)}"
: "${SIMPLETEST_DB:?Set an isolated PHPUnit database URL}"
: "${SIMPLETEST_BASE_URL:?Set a reachable local test base URL}"
export BROWSERTEST_OUTPUT_DIRECTORY="${BROWSERTEST_OUTPUT_DIRECTORY:-/tmp/editflow-browser-output}"
mkdir -p "$BROWSERTEST_OUTPUT_DIRECTORY"
cd "$project_dir"
vendor/bin/phpcs --standard="$module_dir/phpcs.xml.dist"
vendor/bin/phpstan analyse -c "$module_dir/phpstan.neon" --no-progress
vendor/bin/phpunit -c "$drupal_root/core/phpunit.xml.dist" "$module_dir/tests/src/Unit"
vendor/bin/phpunit -c "$drupal_root/core/phpunit.xml.dist" "$module_dir/tests/src/Functional"

vendor/bin/phpunit -c "$drupal_root/core/phpunit.xml.dist" "$module_dir/tests/src/Kernel"
