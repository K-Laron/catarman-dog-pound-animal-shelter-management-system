#!/usr/bin/env bash
set -e

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME_DIR="$PROJECT_ROOT/storage/runtime"
STATE_PATH="$RUNTIME_DIR/app-server.json"
STDOUT_PATH="$RUNTIME_DIR/php-server.out.log"
STDERR_PATH="$RUNTIME_DIR/php-server.err.log"
HOST_NAME="127.0.0.1"
PORT=8000
ENTRY_PATH="/adopt"
URL="http://$HOST_NAME:$PORT$ENTRY_PATH"

mkdir -p "$RUNTIME_DIR"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP executable was not found on PATH." >&2
    exit 1
fi

get_app_server_pids() {
    pgrep -f "php.*-S $HOST_NAME:$PORT.*-t public" || true
}

get_running_state() {
    local pids
    pids=$(get_app_server_pids)
    if [ -n "$pids" ]; then
        echo "running"
        return 0
    fi

    if [ -f "$STATE_PATH" ]; then
        rm -f "$STATE_PATH"
    fi
    echo "stopped"
}

RUNNING_STATE=$(get_running_state)
if [ "$RUNNING_STATE" = "running" ]; then
    if command -v xdg-open >/dev/null; then xdg-open "$URL" >/dev/null 2>&1 &
    elif command -v open >/dev/null; then open "$URL" >/dev/null 2>&1 &
    fi
    echo "Application already running at $URL"
    exit 0
fi

cd "$PROJECT_ROOT"
php -S "$HOST_NAME:$PORT" -t public > "$STDOUT_PATH" 2> "$STDERR_PATH" &
PROCESS_PID=$!

READY=false
for i in {1..20}; do
    sleep 0.5
    if curl -s --head -m 2 "$URL" | head -n 1 | grep -qE "HTTP/1\.[01] [2345]"; then
        READY=true
        break
    fi
    if ! kill -0 $PROCESS_PID 2>/dev/null; then
        break
    fi
done

if [ "$READY" = false ]; then
    if kill -0 $PROCESS_PID 2>/dev/null; then
        kill $PROCESS_PID 2>/dev/null || true
    fi
    echo "Application server failed to start at $URL. Check $STDERR_PATH" >&2
    exit 1
fi

STARTED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

cat <<JSON > "$STATE_PATH"
{
    "pid": $PROCESS_PID,
    "pids": [$PROCESS_PID],
    "url": "$URL",
    "entry_path": "$ENTRY_PATH",
    "host": "$HOST_NAME",
    "port": $PORT,
    "stdout": "$STDOUT_PATH",
    "stderr": "$STDERR_PATH",
    "started_at": "$STARTED_AT"
}
JSON

if command -v xdg-open >/dev/null; then xdg-open "$URL" >/dev/null 2>&1 &
elif command -v open >/dev/null; then open "$URL" >/dev/null 2>&1 &
fi
echo "Application started at $URL"
