#!/usr/bin/env bash
# Uses already-installed ESLint, Playwright and Chromium; installs nothing.
set -euo pipefail
module_dir="$(cd "$(dirname "$0")/.." && pwd)"
: "${ESLINT_BIN:?Set the path to eslint/bin/eslint.js}"
node_bin="${NODE_BIN:-node}"
"$node_bin" "$ESLINT_BIN" --config "$module_dir/.eslintrc.json" "$module_dir"/js/*.js
"$node_bin" "$module_dir/tests/browser/ui.test.cjs"
