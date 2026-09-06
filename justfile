kimai := "/opt/kimai"

# List available recipes.
default:
    @just --list

# Install this bundle's own dev tooling (phpstan, php-cs-fixer, phpunit).
install:
    composer install

# Install any packaged plugins and register this bundle with Kimai (kimai.sh plugin).
plugin-install:
    {{kimai}}/kimai.sh plugin

# Validate config and translations, then clear and rebuild Kimai's cache.
reload:
    {{kimai}}/bin/console kimai:reload

# Poll Lexware for order confirmations a webhook delivery might have missed.
reconcile-orders *args:
    {{kimai}}/bin/console kimai:lexware-sync:reconcile {{args}}

# Poll Lexware for invoice drafts a webhook delivery might have missed.
reconcile-invoices *args:
    {{kimai}}/bin/console kimai:lexware-sync:reconcile-invoices {{args}}

# Run both reconcile commands.
reconcile: reconcile-orders reconcile-invoices

# Confirm the configured Lexware API key still authenticates.
check-api-key:
    {{kimai}}/bin/console kimai:lexware-sync:check-api-key

# Run this bundle's automated test suite.
test *args:
    vendor/bin/phpunit --display-warnings {{args}}

# Check code style without modifying files.
codestyle:
    composer codestyle

# Fix code style violations in place.
codestyle-fix:
    composer codestyle-fix

# Run static analysis.
stan:
    composer phpstan

# Run the full local verification loop: code style, static analysis, tests.
check: codestyle stan test

# Create and migrate the database used by the functional and migration suites.
test-database *args:
    Tests/prepare-database.sh {{args}}

# Run only the fast tests that need neither kernel nor database.
test-unit *args:
    vendor/bin/phpunit --display-warnings --testsuite unit {{args}}

# Run the tests that boot Kimai against the test database.
test-functional *args:
    vendor/bin/phpunit --display-warnings --testsuite functional {{args}}

# Run the database migration tests.
test-migration *args:
    vendor/bin/phpunit --display-warnings --testsuite migration {{args}}

# Run the tests that talk to the real Lexware test account.
test-contract *args:
    vendor/bin/phpunit --display-warnings --testsuite contract {{args}}

# Run the test suite with a coverage report in var/coverage.
test-coverage *args:
    XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html var/coverage --coverage-text {{args}}
