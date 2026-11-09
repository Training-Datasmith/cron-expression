#!/usr/bin/env bash
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p /app/.system-test

COMPOSER_URL="${COMPOSER_URL:-https://getcomposer.org/download/2.8.10/composer.phar}"
COMPOSER_SHA256="${COMPOSER_SHA256:-28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573}"

fail_deprecation_output() {
    local label="$1"
    local file="$2"
    local stripped
    stripped="$(mktemp)"
    sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g' "${file}" >"${stripped}"
    if grep -E '(^|[[:space:]])(PHP[[:space:]]+)?(Warning|Deprecated|Notice):' "${stripped}" >/dev/null; then
        echo "${label}: output contains Warning, Deprecated, or Notice lines" >&2
        grep -E '(^|[[:space:]])(PHP[[:space:]]+)?(Warning|Deprecated|Notice):' "${stripped}" >&2 || true
        rm -f "${stripped}"
        exit 1
    fi
    rm -f "${stripped}"
}

for ext in ctype dom json mbstring phar tokenizer xml xmlwriter; do
    if ! php -m | grep -qi "^${ext}$"; then
        echo "Missing required PHP extension: ${ext}" >&2
        exit 1
    fi
done

curl -fsSL -o /tmp/composer.phar "${COMPOSER_URL}"
echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c -
git config --global --add safe.directory /app

composer_out="$(mktemp)"
set +e
php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress >"${composer_out}" 2>&1
composer_status=$?
set -e
cat "${composer_out}"
fail_deprecation_output "composer update" "${composer_out}"
if [[ ${composer_status} -ne 0 ]]; then
    exit "${composer_status}"
fi
rm -f "${composer_out}"

php scripts/check-cluster-pins.php

audit_out="$(mktemp)"
set +e
php /tmp/composer.phar audit >"${audit_out}" 2>&1
audit_status=$?
set -e
cat "${audit_out}"
fail_deprecation_output "composer audit" "${audit_out}"
if [[ ${audit_status} -ne 0 ]]; then
    exit "${audit_status}"
fi
if ! grep -Fxq "No security vulnerability advisories found." "${audit_out}"; then
    echo "composer audit: missing exact success line" >&2
    exit 1
fi
rm -f "${audit_out}"

php scripts/check-rename-residual.php

phpunit_out="/app/.system-test/phpunit.out"
set +e
vendor/bin/phpunit --colors=never --no-coverage >"${phpunit_out}" 2>&1
phpunit_status=$?
set -e
cat "${phpunit_out}"
fail_deprecation_output "phpunit" "${phpunit_out}"
if [[ ${phpunit_status} -ne 0 ]]; then
    exit "${phpunit_status}"
fi

php scripts/check-phpunit-gate.php "${phpunit_out}"

phpstan_out="$(mktemp)"
set +e
vendor/bin/phpstan analyze --no-progress --no-ansi --memory-limit=1G >"${phpstan_out}" 2>&1
phpstan_status=$?
set -e
cat "${phpstan_out}"
fail_deprecation_output "phpstan" "${phpstan_out}"
if [[ ${phpstan_status} -ne 0 ]]; then
    exit "${phpstan_status}"
fi
if ! grep -F "[OK] No errors" "${phpstan_out}" >/dev/null; then
    echo "phpstan: missing [OK] No errors" >&2
    exit 1
fi
rm -f "${phpstan_out}"

php scripts/check-cluster-pins.php --self-test-wrong-sha
php scripts/check-rename-residual.php --self-test

echo "system-test: all steps passed."
