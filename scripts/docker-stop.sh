#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILE="$ROOT_DIR/docker-compose.session.yml"

clear
echo "--- MagratheaImages3 Docker Session Stopper ---"
echo

# --- List available sessions ---
SESSION_FILES=("$ROOT_DIR"/docker/.env.*)
AVAILABLE=()

for f in "${SESSION_FILES[@]}"; do
    [[ -f "$f" ]] || continue
    name="$(basename "$f" | sed 's/^\.env\.//')"
    [[ "$name" == "sample" ]] && continue
    AVAILABLE+=("$name")
done

if [ ${#AVAILABLE[@]} -eq 0 ]; then
    echo "No sessions found."
    exit 0
fi

echo "Available sessions:"
for s in "${AVAILABLE[@]}"; do
    STATUS=$(docker compose -p "$s" -f "$COMPOSE_FILE" ps --status running -q 2>/dev/null | wc -l | tr -d ' ')
    if [ "$STATUS" -gt 0 ]; then
        echo "  - $s  (running)"
    else
        echo "  - $s  (stopped)"
    fi
done
echo

# --- Pick session ---
if [ -n "$1" ]; then
    SESSION="$1"
else
    read -p "Session name to stop: " SESSION
fi

if [ -z "$SESSION" ]; then
    echo "No session name provided."
    exit 1
fi

ENV_FILE="$ROOT_DIR/docker/.env.$SESSION"

if [ ! -f "$ENV_FILE" ]; then
    echo "Session '$SESSION' not found."
    exit 1
fi

# --- Stop ---
echo
echo "Stopping containers for session '$SESSION'..."
export SESSION
docker compose -p "$SESSION" -f "$COMPOSE_FILE" stop

echo
echo "================================================="
echo "  Session '$SESSION' stopped."
echo "  Data and env file were kept. Resume with:"
echo "    ./scripts/docker-install.sh"
echo "================================================="
