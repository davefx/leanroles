#!/usr/bin/env bash
#
# Brings up a throwaway MariaDB for the test suite.
#
#   ./tests/bin/start-db.sh          start it (idempotent)
#   ./tests/bin/start-db.sh stop     stop it
#   ./tests/bin/start-db.sh reset    throw the data away and start again
#
# It listens on 127.0.0.1:3307 with its own data directory, so it cannot
# collide with — or damage — whatever database server you already run on 3306.
# Nothing in here needs root.

set -euo pipefail

PORT=3307
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DATA_DIR="${ROOT}/tests/.wp/mysql-data"
LOG_FILE="${ROOT}/tests/.wp/mysql-error.log"

# The kernel caps unix socket paths at 107 characters, and a project checked out
# somewhere deep will blow straight past that from inside the repository.
SOCKET="${TMPDIR:-/tmp}/leanroles-mysql.sock"
PID_FILE="${TMPDIR:-/tmp}/leanroles-mysql.pid"

DB_NAME="wordpress_test"

is_up() {
	mysql -h 127.0.0.1 -P "${PORT}" -u root -e "SELECT 1" >/dev/null 2>&1
}

stop_db() {
	if [ -f "${PID_FILE}" ]; then
		kill "$(cat "${PID_FILE}")" 2>/dev/null || true
		rm -f "${PID_FILE}"
		echo "Stopped."
	else
		echo "Not running (no pid file)."
	fi
}

case "${1:-start}" in
	stop)
		stop_db
		exit 0
		;;
	reset)
		stop_db
		sleep 2
		rm -rf "${DATA_DIR}"
		;;
esac

if is_up; then
	echo "Already up on 127.0.0.1:${PORT}."
	mysql -h 127.0.0.1 -P "${PORT}" -u root -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};" 2>/dev/null
	exit 0
fi

mkdir -p "${ROOT}/tests/.wp"

if [ ! -d "${DATA_DIR}/mysql" ]; then
	echo "Initialising a data directory in ${DATA_DIR}…"
	mariadb-install-db \
		--datadir="${DATA_DIR}" \
		--auth-root-authentication-method=normal \
		--skip-test-db >/dev/null
fi

echo "Starting MariaDB on 127.0.0.1:${PORT}…"

nohup mariadbd \
	--datadir="${DATA_DIR}" \
	--port="${PORT}" \
	--socket="${SOCKET}" \
	--bind-address=127.0.0.1 \
	--pid-file="${PID_FILE}" \
	--log-error="${LOG_FILE}" \
	>/dev/null 2>&1 &

for _ in $(seq 1 30); do
	sleep 1
	if is_up; then
		break
	fi
done

if ! is_up; then
	echo "Failed to start. Last lines of ${LOG_FILE}:" >&2
	tail -20 "${LOG_FILE}" >&2
	exit 1
fi

mysql -h 127.0.0.1 -P "${PORT}" -u root -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};" 2>/dev/null

echo "Up. Database '${DB_NAME}' is ready on 127.0.0.1:${PORT}."
