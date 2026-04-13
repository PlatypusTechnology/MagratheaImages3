#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE_FILE="$SCRIPT_DIR/docker-compose.session.yml"

clear
echo "--- MagratheaImages3 Docker Session Destroyer ---"
echo

# --- List available sessions ---
SESSION_FILES=("$SCRIPT_DIR"/docker/.env.*)
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
    read -p "Session name to destroy: " SESSION
fi

if [ -z "$SESSION" ]; then
    echo "No session name provided."
    exit 1
fi

ENV_FILE="$SCRIPT_DIR/docker/.env.$SESSION"

if [ ! -f "$ENV_FILE" ]; then
    echo "Session '$SESSION' not found."
    exit 1
fi

# --- Confirm ---
echo
read -p "This will remove all containers, volumes and data for session '$SESSION'. Are you sure? [y/N]: " CONFIRM
if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
    echo "Aborted."
    exit 0
fi

# --- Bring down ---
echo
echo "Stopping and removing containers for session '$SESSION'..."
export SESSION
docker compose -p "$SESSION" -f "$COMPOSE_FILE" down -v --remove-orphans

# --- Remove session env file ---
rm "$ENV_FILE"
echo "Removed: docker/.env.$SESSION"

echo
echo "================================================="
echo "  Session '$SESSION' destroyed."
echo "================================================="
