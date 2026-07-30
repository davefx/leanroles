#!/usr/bin/env bash
#
# One-time setup for the test suite.
#
#   ./tests/bin/install.sh [db-name] [db-user] [db-pass] [db-host] [wp-version]
#
# Downloads WordPress core and the WordPress test library, and configures them
# against the database you name. Everything lands under tests/.wp/ so it can be
# thrown away and rebuilt without touching anything else.
#
# If you have no database handy, run tests/bin/start-db.sh first: it brings up a
# throwaway MariaDB on port 3307 that is isolated from whatever else you run.

set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-}"
DB_HOST="${4:-127.0.0.1:3307}"
WP_VERSION="${5:-latest}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WP_DIR="${ROOT}/tests/.wp/wordpress"
LIB_DIR="${ROOT}/tests/.wp/wordpress-tests-lib"
INSTALLER="${ROOT}/tests/.wp/install-wp-tests.sh"

mkdir -p "${ROOT}/tests/.wp"

if [ ! -f "${INSTALLER}" ]; then
	echo "Fetching the WordPress test installer…"
	curl -fsSL \
		-o "${INSTALLER}" \
		https://raw.githubusercontent.com/wp-cli/scaffold-command/main/templates/install-wp-tests.sh
	chmod +x "${INSTALLER}"
fi

echo "Installing WordPress ${WP_VERSION} and the test library…"
echo "  database: ${DB_NAME} on ${DB_HOST} as ${DB_USER}"

# `true` as the last argument means "the database already exists, do not create
# it" — the caller owns that decision.
WP_CORE_DIR="${WP_DIR}/" WP_TESTS_DIR="${LIB_DIR}/" \
	"${INSTALLER}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${DB_HOST}" "${WP_VERSION}" true

cat <<EOF

Done.

Run the suite with:

    export WP_TESTS_DIR="${LIB_DIR}"
    composer test                  # single site
    composer test:multisite        # network

EOF
