#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE_FILE="$ROOT/tests/wordpress/docker-compose.yml"

docker compose -f "$COMPOSE_FILE" up -d db wordpress

until docker compose -f "$COMPOSE_FILE" run --rm cli core is-installed --allow-root >/dev/null 2>&1; do
  sleep 2
  if ! docker compose -f "$COMPOSE_FILE" run --rm cli core is-installed --allow-root >/dev/null 2>&1; then
    docker compose -f "$COMPOSE_FILE" run --rm cli core install \
      --url=http://localhost:8099 \
      --title='DigitalOwnership local test' \
      --admin_user=admin \
      --admin_password=admin-password \
      --admin_email=admin@example.test \
      --skip-email \
      --allow-root >/dev/null 2>&1 || true
  fi
done

docker compose -f "$COMPOSE_FILE" run --rm cli rewrite structure '/%postname%/' --hard --allow-root >/dev/null
docker compose -f "$COMPOSE_FILE" run --rm cli plugin activate digitalownership-publication-verification --allow-root >/dev/null
docker compose -f "$COMPOSE_FILE" run --rm cli eval-file wp-content/plugins/digitalownership-publication-verification/tests/wordpress/evidence-save-regression.php --allow-root
docker compose -f "$COMPOSE_FILE" run --rm cli eval-file wp-content/plugins/digitalownership-publication-verification/tests/wordpress/multi-publication-isolation.php --allow-root
