#!/usr/bin/env bash
#
# Run the suite against every WordPress version the plugin claims to support.
#
#   ./tests/bin/matrix.sh                 # the declared floor, a pre-6.6 release, and latest
#   ./tests/bin/matrix.sh 5.9 6.2 latest  # whichever versions you name
#
# The floor matters more than it looks. Running only against the newest
# WordPress hides anything that depends on an API added since — and this is
# exactly how the batch cache flush was found to be broken on everything before
# 6.1, where wp_cache_flush_group() does not exist.
#
# 6.5 is in the default set on purpose: it is the last line before 6.6 changed
# the autoload column from yes/no to a small vocabulary, and the size probe has
# to read both.

set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DB_HOST="${LEANROLES_DB_HOST:-127.0.0.1:3307}"
DB_USER="${LEANROLES_DB_USER:-root}"
DB_PASS="${LEANROLES_DB_PASS:-}"

VERSIONS=("$@")
if [ ${#VERSIONS[@]} -eq 0 ]; then
	VERSIONS=(5.9 6.5 latest)
fi

INSTALLER="${ROOT}/tests/.wp/install-wp-tests.sh"

if [ ! -x "${INSTALLER}" ]; then
	echo "Run tests/bin/install.sh first." >&2
	exit 1
fi

status=0
declare -a summary

for version in "${VERSIONS[@]}"; do
	slug="$(echo "${version}" | tr -cd '[:alnum:]')"
	dir="${ROOT}/tests/.wp/matrix/${slug}"
	db="wordpress_test_${slug}"

	echo
	echo "════════════════════════════════════════════════════════"
	echo "  WordPress ${version}"
	echo "════════════════════════════════════════════════════════"

	if [ ! -f "${dir}/wordpress-tests-lib/includes/functions.php" ]; then
		mkdir -p "${dir}"

		mysql -h "${DB_HOST%%:*}" -P "${DB_HOST##*:}" -u "${DB_USER}" \
			${DB_PASS:+-p"${DB_PASS}"} \
			-e "CREATE DATABASE IF NOT EXISTS ${db};" 2>/dev/null

		WP_CORE_DIR="${dir}/wordpress/" WP_TESTS_DIR="${dir}/wordpress-tests-lib/" \
			"${INSTALLER}" "${db}" "${DB_USER}" "${DB_PASS}" "${DB_HOST}" "${version}" true \
			> "${dir}/install.log" 2>&1 || {
				echo "  install failed; see ${dir}/install.log"
				summary+=("${version}: INSTALL FAILED")
				status=1
				continue
			}
	fi

	installed="$(grep -m1 "wp_version = " "${dir}/wordpress/wp-includes/version.php" | cut -d"'" -f2)"

	# Old WordPress on a new PHP emits its own deprecation notices. They are
	# not ours and would otherwise bury the result.
	if WP_TESTS_DIR="${dir}/wordpress-tests-lib" "${ROOT}/vendor/bin/phpunit" 2>&1 \
		| grep -vE '^(PHP )?(Deprecated|Warning):' \
		| tail -25; then
		summary+=("${installed}: see above")
	fi

	if ! WP_TESTS_DIR="${dir}/wordpress-tests-lib" "${ROOT}/vendor/bin/phpunit" > /dev/null 2>&1; then
		status=1
		summary[-1]="${installed}: FAILED"
	else
		summary[-1]="${installed}: passed"
	fi
done

echo
echo "════════════════════════════════════════════════════════"
for line in "${summary[@]}"; do
	echo "  ${line}"
done
echo "════════════════════════════════════════════════════════"

exit "${status}"
