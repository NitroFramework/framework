#!/usr/bin/env bash
#
# Nitro vs Hyperf, on the same terms.
#
# Builds a minimal Nitro app, serves it under FrankenPHP worker mode with one
# worker per core, and drives it with the same wrk invocation Hyperf publishes.
# Prints a static-file control first, because a framework number is meaningless
# without knowing what the box does when no framework is involved.
#
# Usage:  bash linux-run.sh [port]

set -euo pipefail

PORT="${1:-8080}"
CORES="$(nproc)"
APP_DIR="${HOME}/nitro-bench-app"

say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

say "Box"
echo "cores        ${CORES}"
echo "memory       $(free -h 2>/dev/null | awk '/^Mem:/{print $2}' || echo '?')"
echo "kernel       $(uname -sr)"
php -v | head -1
command -v wrk >/dev/null || { echo "wrk is not installed — apt-get install -y wrk"; exit 1; }

say "FrankenPHP"
if ! command -v frankenphp >/dev/null; then
    echo "installing..."
    curl -fsSL https://frankenphp.dev/install.sh | sh
    sudo mv frankenphp /usr/local/bin/ 2>/dev/null || export PATH="$PWD:$PATH"
fi
frankenphp version | head -1

say "Application"
if [ ! -d "${APP_DIR}" ]; then
    composer create-project nitro/nitro "${APP_DIR}" --no-interaction --quiet
fi
cd "${APP_DIR}"

# Production settings: debug changes error handling and view freshness checks.
sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/' .env 2>/dev/null || true

# The hello-world route, matching Hyperf's payload: no session, no database,
# no view. Added once, idempotently.
if ! grep -q 'bench/t0-noop' routes/web.php; then
    cat >> routes/web.php <<'ROUTES'

// Benchmark: framework floor. Routing and response only.
Route::get('/bench/t0-noop', fn () => ['ok' => true]);
ROUTES
fi

php nitro config:cache >/dev/null 2>&1 || true
php nitro route:cache  >/dev/null 2>&1 || true
php nitro view:cache   >/dev/null 2>&1 || true

# One worker per core, which is what Hyperf's 8-core figure assumes.
cat > Caddyfile.bench <<CADDY
{
    auto_https off
    admin off
    frankenphp {
        worker {
            file ./public/worker.php
            num ${CORES}
            env APP_DEBUG false
        }
    }
}

:${PORT} {
    root * public
    encode
    php_server {
        try_files {path} /worker.php
    }
}
CADDY

say "Serving on :${PORT} with ${CORES} workers"
frankenphp run --config Caddyfile.bench > /tmp/nitro-bench.log 2>&1 &
SERVER_PID=$!
trap 'kill ${SERVER_PID} 2>/dev/null || true' EXIT

for _ in $(seq 1 40); do
    sleep 0.25
    if curl -fsS "http://127.0.0.1:${PORT}/bench/t0-noop" >/dev/null 2>&1; then
        break
    fi
done

curl -fsS "http://127.0.0.1:${PORT}/bench/t0-noop" || { echo "server did not come up:"; tail -20 /tmp/nitro-bench.log; exit 1; }
echo

# Let the workers reach steady state before measuring.
wrk -t"${CORES}" -c256 -d5s "http://127.0.0.1:${PORT}/bench/t0-noop" >/dev/null 2>&1 || true

say "CONTROL — static file, PHP not involved"
echo "ok" > public/control.txt
wrk -t8 -c1024 -d10s "http://127.0.0.1:${PORT}/control.txt"

say "NITRO — hello-world through the framework (Hyperf's exact wrk flags)"
wrk -t8 -c1024 -d10s "http://127.0.0.1:${PORT}/bench/t0-noop"

say "Reference"
echo "Hyperf published: 103,921 req/s on 8 cores / 16 GB, wrk -c 1024 -t 8"
echo "Their transfer was 190.16 MB over 1,049,478 requests = ~190 bytes/request."
echo "Compare Nitro's Transfer/sec line, not just Requests/sec."
