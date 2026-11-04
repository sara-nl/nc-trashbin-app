#!/usr/bin/env bash
# Starts the official Nextcloud Docker image, installs the app from this checkout
# and runs the end-to-end test against it. The container is removed afterwards.
#
# usage: tests/e2e/run-docker.sh <image tag, e.g. 35 or latest> [port]
#
# Set FORCE_ENABLE=1 to enable the app on a Nextcloud version newer than max-version in info.xml.
set -euo pipefail
TAG=${1:?usage: $0 <image tag, e.g. 35 or latest> [port]}
PORT=${2:-8080}
APP_DIR=$(cd "$(dirname "$0")/../.." && pwd)
CONTAINER=surf_trashbin-e2e-$TAG
IMAGE=nextcloud:$TAG-apache
if [ "$TAG" = latest ]; then
	IMAGE=nextcloud:apache
fi

docker rm -fv "$CONTAINER" >/dev/null 2>&1 || true
trap 'docker rm -fv "$CONTAINER" >/dev/null 2>&1 || true' EXIT

docker run -d --name "$CONTAINER" -p "127.0.0.1:$PORT:80" \
	-e SQLITE_DATABASE=nextcloud \
	-e NEXTCLOUD_ADMIN_USER=admin \
	-e NEXTCLOUD_ADMIN_PASSWORD="$(head -c 24 /dev/urandom | base64)" \
	-e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
	"$IMAGE" >/dev/null

echo "Waiting for Nextcloud ($TAG) to be installed..."
for _ in $(seq 1 120); do
	if curl -s "http://localhost:$PORT/status.php" | grep -q '"installed":true'; then
		break
	fi
	sleep 2
done
curl -s "http://localhost:$PORT/status.php" | grep -q '"installed":true' || { docker logs "$CONTAINER" | tail -20; exit 1; }

# COPYFILE_DISABLE keeps macOS tar from adding ._ files, which Nextcloud would load as classes
docker exec "$CONTAINER" mkdir -p /var/www/html/custom_apps/surf_trashbin
COPYFILE_DISABLE=1 tar -C "$APP_DIR" -cf - appinfo lib |
	docker exec -i "$CONTAINER" tar -C /var/www/html/custom_apps/surf_trashbin -xf - 2>/dev/null
docker exec "$CONTAINER" chown -R www-data:www-data /var/www/html/custom_apps/surf_trashbin
docker exec -u www-data "$CONTAINER" php occ app:enable ${FORCE_ENABLE:+--force} surf_trashbin
# New users get no skeleton files, which keeps their trashbins and the run small
docker exec -u www-data "$CONTAINER" php occ config:system:set skeletondirectory --value=''

status=0
"$APP_DIR/tests/e2e/e2e.sh" "http://localhost:$PORT" docker "$CONTAINER" || status=$?

echo "== Nextcloud log (warnings and errors)"
log=$(docker exec "$CONTAINER" sh -c 'cat /var/www/html/data/nextcloud.log 2>/dev/null' || true)
echo "$log"

# Any error in the log, such as an uncaught exception or a failed trashbin copy, fails the run.
unexpected=$(echo "$log" | python3 -c 'import json,sys
for line in sys.stdin:
    try:
        entry = json.loads(line)
    except ValueError:
        continue
    if entry.get("level", 0) >= 3:
        print(entry["message"][:300])')
if [ -n "$unexpected" ]; then
	echo "== Unexpected errors in the Nextcloud log"
	echo "$unexpected"
	status=1
fi
exit $status
