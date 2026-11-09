#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE_TAG="cron-expression-system-test:8.2"
USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
OUTPUT_DIR="${REPO_ROOT}/.system-test"
COMPOSER_SHA256="28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573"
COMPOSER_URL="https://getcomposer.org/download/2.8.10/composer.phar"

docker_cmd() {
    if [[ "${USE_SUDO_DOCKER}" == "1" ]]; then
        sudo docker "$@"
    else
        docker "$@"
    fi
}

mkdir -p "${OUTPUT_DIR}"

docker_cmd build -f "${REPO_ROOT}/docker/system-test/Dockerfile" -t "${IMAGE_TAG}" "${REPO_ROOT}"

docker_cmd run --rm \
    -v "${REPO_ROOT}:/app" \
    -w /app \
    "${IMAGE_TAG}" \
    bash -lc "COMPOSER_URL='${COMPOSER_URL}' COMPOSER_SHA256='${COMPOSER_SHA256}' bash /app/scripts/system-test-inner.sh"

echo "System test passed."
