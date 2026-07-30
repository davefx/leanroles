#!/usr/bin/env bash
#
# One-time setup for the library's test suite.
#
#   ./tests/bin/install.sh [db-name] [db-user] [db-pass] [db-host] [wp-version]
#
# Downloads WordPress and the WordPress test library into tests/.wp/, which is
# gitignored and can be thrown away.

set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-}"
DB_HOST="${4:-127.0.0.1:3306}"
WP_VERSION="${5:-latest}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
INSTALLER="${ROOT}/tests/.wp/install-wp-tests.sh"

mkdir -p "${ROOT}/tests/.wp"

if [ ! -f "${INSTALLER}" ]; then
	curl -fsSL \
		-o "${INSTALLER}" \
		https://raw.githubusercontent.com/wp-cli/scaffold-command/main/templates/install-wp-tests.sh
	chmod +x "${INSTALLER}"
fi

WP_CORE_DIR="${ROOT}/tests/.wp/wordpress/" \
WP_TESTS_DIR="${ROOT}/tests/.wp/wordpress-tests-lib/" \
	"${INSTALLER}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${DB_HOST}" "${WP_VERSION}" true

echo
echo "Done. Run: composer test"
