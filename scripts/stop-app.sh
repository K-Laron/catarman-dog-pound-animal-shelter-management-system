#!/usr/bin/env bash
set -e

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME_DIR="$PROJECT_ROOT/storage/runtime"
STATE_PATH="$RUNTIME_DIR/app-server.json"
HOST_NAME="127.0.0.1"
PORT=8000

get_app_server_pids() {
    pgrep -f "php.*-S $HOST_NAME:$PORT.*-t public" || true
}

PIDS_STRING=$(get_app_server_pids)
PIDS=($PIDS_STRING)

if [ ! -f "$STATE_PATH" ] && [ ${#PIDS[@]} -eq 0 ]; then
    echo "Application server is not running."
    exit 0
fi

if [ -f "$STATE_PATH" ]; then
    # Try parsing json simply
    JSON_PIDS=$(grep -o '"pid": [0-9]*' "$STATE_PATH" | grep -o '[0-9]*' || true)
    if [ -n "$JSON_PIDS" ]; then
        for PID in $JSON_PIDS; do
            PIDS+=($PID)
        done
    fi
    # If the JSON uses pids array
    JSON_ARRAY_PIDS=$(grep -o '"pids": \[[^]]*\]' "$STATE_PATH" | grep -o '[0-9]\+' || true)
    if [ -n "$JSON_ARRAY_PIDS" ]; then
        for PID in $JSON_ARRAY_PIDS; do
            PIDS+=($PID)
        done
    fi
fi

# Remove duplicates
UNIQUE_PIDS=()
for PID in "${PIDS[@]}"; do
    FOUND=0
    for UPID in "${UNIQUE_PIDS[@]}"; do
        if [ "$PID" -eq "$UPID" ]; then
            FOUND=1
            break
        fi
    done
    if [ $FOUND -eq 0 ]; then
        UNIQUE_PIDS+=($PID)
    fi
done

if [ ${#UNIQUE_PIDS[@]} -eq 0 ]; then
    rm -f "$STATE_PATH"
    echo "Application server process was already stopped."
    exit 0
fi

for PID in "${UNIQUE_PIDS[@]}"; do
    kill -9 "$PID" 2>/dev/null || true
done

rm -f "$STATE_PATH"
echo "Application server stopped (PIDs ${UNIQUE_PIDS[*]})."
