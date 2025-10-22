#!/bin/bash

clear

# Bash script to configure MagratheaImages3 system interactively
CONFIG_SAMPLE="$(dirname "$0")/src/configs/magrathea.conf.sample"
CONFIG_FILE="$(dirname "$0")/src/configs/magrathea.conf"

# Ask user for configuration values
echo "--- MagratheaImages3 Installer ---"
read -p "Enter environment name: " ENV_NAME
read -p "Enter Magrathea Images URL: " APP_URL
# Ensure APP_URL starts with http:// or https://
if [[ ! "$APP_URL" =~ ^https?:// ]]; then
	APP_URL="https://$APP_URL"
fi
echo
read -p "Enter database host: " DB_HOST
read -p "Enter database name: " DB_NAME
read -p "Enter database user: " DB_USER
read -sp "Enter database password: " DB_PASS
echo

# Ask for paths with defaults (absolute to current dir)
CUR_DIR="$(pwd)"
DEF_LOGS_PATH="$CUR_DIR/logs"
DEF_BACKUPS_PATH="$CUR_DIR/backups"
DEF_MEDIAS_PATH="$CUR_DIR/media"

read -p "Enter logs path [$DEF_LOGS_PATH]: " LOGS_PATH
LOGS_PATH=${LOGS_PATH:-$DEF_LOGS_PATH}
read -p "Enter backups path [$DEF_BACKUPS_PATH]: " BACKUPS_PATH
BACKUPS_PATH=${BACKUPS_PATH:-$DEF_BACKUPS_PATH}
read -p "Enter medias path [$DEF_MEDIAS_PATH]: " MEDIAS_PATH
MEDIAS_PATH=${MEDIAS_PATH:-$DEF_MEDIAS_PATH}

# Create folders if they do not exist
for DIR in "$LOGS_PATH" "$BACKUPS_PATH" "$MEDIAS_PATH"; do
	if [ ! -d "$DIR" ]; then
		mkdir -p "$DIR"
		echo "Created directory: $DIR"
	fi
done

# Generate a random JWT key
JWT_KEY=$(head -c 32 /dev/urandom | base64)

# Copy sample config to new config file
cp "$CONFIG_SAMPLE" "$CONFIG_FILE"

# Set use_environment in [general]
sed -i "s/^\(\s*use_environment = \).*/\1\"$ENV_NAME\"/" "$CONFIG_FILE"

# Remove existing section for this environment if present
sed -i "/^\[$ENV_NAME\]/,/^\[/ {/^\[$ENV_NAME\]/!{/^\[/!d}}" "$CONFIG_FILE"
sed -i "/^\[$ENV_NAME\]/d" "$CONFIG_FILE"

# Append new environment section
echo "" >> "$CONFIG_FILE"
echo "[$ENV_NAME]" >> "$CONFIG_FILE"
echo "  db_host = \"$DB_HOST\"" >> "$CONFIG_FILE"
echo "  db_name = \"$DB_NAME\"" >> "$CONFIG_FILE"
echo "  db_user = \"$DB_USER\"" >> "$CONFIG_FILE"
echo "  db_pass = \"$DB_PASS\"" >> "$CONFIG_FILE"
echo "  logs_path = \"$LOGS_PATH\"" >> "$CONFIG_FILE"
echo "  backups_path = \"$BACKUPS_PATH\"" >> "$CONFIG_FILE"
echo "  medias_path = \"$MEDIAS_PATH\"" >> "$CONFIG_FILE"
echo "  timezone = \"America/Sao_Paulo\"" >> "$CONFIG_FILE"
echo "  app_url = \"$APP_URL\"" >> "$CONFIG_FILE"
echo "  jwt_key = \"$JWT_KEY\"" >> "$CONFIG_FILE"


# Animation: fake processing
echo -n "Processing"
for i in {1..5}; do
	sleep 0.3
	echo -n "."
done
echo
sleep 0.5
echo -e "\n\n\033[1;36m✨ Creating bootstrap file... ✨\033[0m\n"

# Remove 'die;' from bootstrap.php
BOOTSTRAP_FILE="$(dirname "$0")/src/api/bootstrap.php"
sed -i '/^die;$/d' "$BOOTSTRAP_FILE"

# More animation
echo -n "Finalizing"
for i in {1..5}; do
	sleep 0.2
	echo -n "."
done
echo -e "\n\n\033[1;32m✅ Installation complete!\033[0m\n"

# Final instructions
echo -e "To finish setup:\n"
echo -e "1. Run \033[1;33mcomposer install\033[0m inside the src folder:"
echo -e "   cd src && composer install\n"
echo -e "2. Point your browser to: \033[1;34m$APP_URL/bootstrap.php\033[0m\n"
