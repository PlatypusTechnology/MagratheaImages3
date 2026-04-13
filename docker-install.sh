#!/bin/bash

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE_FILE="$SCRIPT_DIR/docker-compose.session.yml"
CONFIG_SAMPLE="$SCRIPT_DIR/src/configs/magrathea.conf.sample"
CONFIG_FILE="$SCRIPT_DIR/src/configs/magrathea.conf"
BOOTSTRAP_FILE="$SCRIPT_DIR/src/api/bootstrap.php"

clear
echo "--- MagratheaImages3 Docker Session Installer ---"
echo

# --- Session name ---
read -p "Session name [magrathea]: " SESSION
SESSION=${SESSION:-magrathea}

ENV_FILE="$SCRIPT_DIR/docker/.env.$SESSION"

if docker compose -p "$SESSION" -f "$COMPOSE_FILE" ps -q 2>/dev/null | grep -q .; then
    echo "Session '$SESSION' is already running. Use docker-destroy.sh to remove it first."
    exit 1
fi

# --- App port ---
read -p "App HTTP port [8081]: " APP_PORT
APP_PORT=${APP_PORT:-8081}

if ss -tlnp 2>/dev/null | grep -q ":${APP_PORT}[[:space:]]"; then
    echo "Port $APP_PORT is already in use. Choose a different port."
    exit 1
fi

echo

# --- Database credentials ---
read -p "Database name [mag_images]: " DB_NAME
DB_NAME=${DB_NAME:-mag_images}
read -p "Database user [user]: " DB_USER
DB_USER=${DB_USER:-user}
read -sp "Database password [password]: " DB_PASS
echo
DB_PASS=${DB_PASS:-password}
read -sp "Database root password [root]: " DB_ROOT_PASS
echo
DB_ROOT_PASS=${DB_ROOT_PASS:-root}

echo

# --- Optional ---
read -p "JWT secret (leave blank to auto-generate): " JWT_SECRET
JWT_SECRET=${JWT_SECRET:-$(head -c 24 /dev/urandom | base64 | tr -d '\n')}
read -p "Sentry DSN (leave blank to skip): " SENTRY_DSN

APP_URL="http://localhost:$APP_PORT"

echo

# --- Write session env file ---
cat > "$ENV_FILE" <<EOF
MYSQL_ROOT_PASSWORD=$DB_ROOT_PASS
MYSQL_HOST=mag_sql
MYSQL_DATABASE=$DB_NAME
MYSQL_USER=$DB_USER
MYSQL_PASSWORD=$DB_PASS
JWT_SECRET=$JWT_SECRET
SENTRY_DSN=$SENTRY_DSN
EOF

echo "Created session env: docker/.env.$SESSION"

# --- Create required directories ---
for DIR in "$SCRIPT_DIR/logs" "$SCRIPT_DIR/backups" "$SCRIPT_DIR/medias" "$SCRIPT_DIR/cache"; do
    if [ ! -d "$DIR" ]; then
        mkdir -p "$DIR"
        echo "Created directory: $DIR"
    fi
done

# --- Configure magrathea.conf ---
JWT_KEY=$(head -c 32 /dev/urandom | base64 | tr -d '\n')
cp "$CONFIG_SAMPLE" "$CONFIG_FILE"

sed -i "s/^\(\s*use_environment = \).*/\1\"$SESSION\"/" "$CONFIG_FILE"

# Remove existing section for this environment
sed -i "/^\[$SESSION\]/,/^\[/ {/^\[$SESSION\]/!{/^\[/!d}}" "$CONFIG_FILE"
sed -i "/^\[$SESSION\]/d" "$CONFIG_FILE"

# Append new environment section
cat >> "$CONFIG_FILE" <<EOF

[$SESSION]
  db_host = "mag_sql"
  db_name = "$DB_NAME"
  db_user = "$DB_USER"
  db_pass = "$DB_PASS"
  logs_path = "/var/www/logs"
  backups_path = "/var/www/backups"
  medias_path = "/var/www/medias"
  cache_path = "/var/www/cache"
  timezone = "America/Sao_Paulo"
  app_url = "$APP_URL"
  jwt_key = "$JWT_KEY"
  secure_api = true
  webp_quick_access = true
EOF

echo "Configured: src/configs/magrathea.conf"

# --- Start containers ---
echo
echo -n "Starting containers"
export SESSION APP_PORT
docker compose -p "$SESSION" -f "$COMPOSE_FILE" up -d --build --quiet-pull 2>&1 | while read -r line; do
    echo -n "."
done
echo

# mag_sql healthcheck already gates the app container, but we still need to
# wait before running the manual SQL import below.
echo -n "Waiting for database to be ready"
until docker compose -p "$SESSION" -f "$COMPOSE_FILE" exec -T mag_sql \
        mariadb-admin ping -u root -p"$DB_ROOT_PASS" --silent 2>/dev/null; do
    echo -n "."
    sleep 2
done
echo " ready!"

# --- Import schema ---
echo "Importing database schema from database/database.sql..."
docker compose -p "$SESSION" -f "$COMPOSE_FILE" exec -T mag_sql \
    mariadb -u root -p"$DB_ROOT_PASS" "$DB_NAME" < "$SCRIPT_DIR/database/database.sql"
echo "Schema imported."

# --- Unlock bootstrap ---
if [ -f "$BOOTSTRAP_FILE" ]; then
    sed -i '/^die;$/d' "$BOOTSTRAP_FILE"
    echo "Unlocked: src/api/bootstrap.php"
fi

# --- Done ---
echo
echo "================================================="
echo "  Session '$SESSION' is up!"
echo "  App URL:       $APP_URL"
echo "  Bootstrap:     $APP_URL/bootstrap.php"
echo "  To stop:       ./docker-destroy.sh"
echo "================================================="
