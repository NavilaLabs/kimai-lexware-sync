#!/usr/bin/env bash
#
# Creates and migrates the database used by the functional and migration test suites.
# Safe to run repeatedly. Pass --fresh to drop and rebuild it from nothing.

set -euo pipefail

plugin_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
kimai_directory="${KIMAI_DIRECTORY:-$(cd "${plugin_directory}/../../.." && pwd)}"

database_host="${TEST_DATABASE_HOST:-sqldb}"
database_port="${TEST_DATABASE_PORT:-3306}"
database_name="${TEST_DATABASE_NAME:-kimai_test}"
database_user="${TEST_DATABASE_USER:-kimai}"
database_password="${TEST_DATABASE_PASSWORD:-kimai}"
admin_user="${TEST_DATABASE_ADMIN_USER:-root}"
admin_password="${TEST_DATABASE_ADMIN_PASSWORD:-kimai}"

if [ ! -f "${kimai_directory}/bin/console" ]; then
    echo "Cannot find a Kimai installation at ${kimai_directory}. Set KIMAI_DIRECTORY." >&2
    exit 1
fi

fresh=0
for argument in "$@"; do
    case "${argument}" in
        --fresh) fresh=1 ;;
        *) echo "Unknown argument: ${argument}" >&2; exit 1 ;;
    esac
done

run_as_admin() {
    mysql --host="${database_host}" --port="${database_port}" \
        --user="${admin_user}" --password="${admin_password}" --batch --skip-column-names -e "$1"
}

echo "Preparing test database ${database_name} on ${database_host}"

if [ "${fresh}" -eq 1 ]; then
    run_as_admin "DROP DATABASE IF EXISTS \`${database_name}\`;"
fi

run_as_admin "CREATE DATABASE IF NOT EXISTS \`${database_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# The migration tests build scratch databases next to this one, so the grant covers the whole
# family rather than a single name. It is skipped when the test user is the administrator
# itself, which is how continuous integration runs.
if [ "${database_user}" != "${admin_user}" ]; then
    run_as_admin "GRANT ALL PRIVILEGES ON \`${database_name}\`.* TO '${database_user}'@'%';"
    run_as_admin "GRANT ALL PRIVILEGES ON \`${database_name}\_%\`.* TO '${database_user}'@'%';"
    run_as_admin "FLUSH PRIVILEGES;"
fi

export APP_ENV=dev
export DATABASE_URL="mysql://${database_user}:${database_password}@${database_host}:${database_port}/${database_name}?charset=utf8mb4&serverVersion=8.3.0"

# The migration paths inside the plugin configuration are written relative to Kimai's root,
# so both commands have to run from there, exactly as Kimai's own install command does.
cd "${kimai_directory}"

# Two separate processes on purpose: Doctrine freezes its migration dependencies after the
# first configuration is loaded, so one process can only ever run one migration set.
echo "Running Kimai migrations"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --quiet

echo "Running plugin migrations"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --quiet \
    --configuration="${plugin_directory}/Migrations/doctrine_migrations.yaml"

table_count="$(run_as_admin "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${database_name}' AND table_name LIKE 'kimai2_ext_lexware_%';")"

echo "Done. ${table_count} plugin tables present in ${database_name}."
