# Installation Guide

This project can be installed in two ways:

- **Docker (local/dev)** — spins up isolated containers with a single script
- **Production** — runs `install.sh` directly on the server

---

## Docker Installation (local / dev)

Uses `docker-compose.session.yml` to create a fully isolated environment. Multiple sessions can coexist side by side, each with its own database volume and port.

### Requirements

- Docker with Compose v2 (`docker compose` command)

### Start a session

```bash
./docker-install.sh
```

You will be prompted for:

| Prompt | Default | Notes |
|---|---|---|
| Session name | `magrathea` | Used as the Docker project name and config environment key |
| App HTTP port | `8081` | The port exposed on your host machine |
| Database name | `mag_images` | |
| Database user | `user` | |
| Database password | `password` | |
| Database root password | `root` | Used only for the initial schema import |
| JWT secret | _(auto-generated)_ | Leave blank to generate one |
| Sentry DSN | _(blank)_ | Optional |

The script will:

1. Create `docker/.env.<session>` with the credentials
2. Write `src/configs/magrathea.conf` with the session environment block
3. Build and start the containers (`mag_sql` + `magrathea_images`)
4. Wait for the database to be healthy, then import `database/database.sql`
5. Remove the `die;` guard from `src/api/bootstrap.php`

Once done, the app is available at `http://localhost:<port>`.

> **Note:** only `database/database.sql` is imported on a fresh install. Migration files (e.g. `database/migration-3.3.0.sql`) are intended for upgrading existing installations and should not be re-run on a clean database.

### Destroy a session

```bash
./docker-destroy.sh
```

Or pass the session name directly to skip the prompt:

```bash
./docker-destroy.sh magrathea
```

This will:

1. List available sessions with their running status
2. Ask for confirmation
3. Run `docker compose down -v --remove-orphans`, which removes containers **and** their volumes (all database data is deleted)
4. Delete `docker/.env.<session>`

---

## Production Installation

Uses `install.sh` to configure the project directly on the host server. PHP, Apache (or nginx), Composer, and MariaDB/MySQL must already be installed and running.

### Requirements

- PHP 8.1+
- Composer
- Apache or nginx with rewrite/alias support
- MariaDB or MySQL — database and user created beforehand
- Write access to the project directory

### Grant DB privileges before running

Connect as root and create the database and user:

```sql
CREATE DATABASE mag_images;
CREATE USER 'user'@'%' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON mag_images.* TO 'user'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

### Run the installer

From the project root:

```bash
bash install.sh
```

You will be prompted for:

| Prompt | Notes |
|---|---|
| Environment name | Arbitrary label stored in `magrathea.conf` (e.g. `production`) |
| App URL | Full URL the app is served from; `https://` is prepended automatically if no scheme is given |
| Database host | Hostname or IP of the DB server |
| Database name | Must already exist |
| Database user | Must already have privileges on the database |
| Database password | |
| Logs path | Defaults to `<project>/logs` |
| Backups path | Defaults to `<project>/backups` |
| Medias path | Defaults to `<project>/medias` |
| Cache path | Defaults to `<project>/cache` |

The script will:

1. Create any missing directories (logs, backups, medias, cache)
2. Generate a random JWT key
3. Copy `src/configs/magrathea.conf.sample` → `src/configs/magrathea.conf` and write the environment block
4. Remove the `die;` guard from `src/api/bootstrap.php`, which blocks the app until installation is complete

### Finish setup

After the script completes:

**1. Install PHP dependencies:**

```bash
cd src && composer install
```

**2. Import the database schema:**

```bash
mariadb -u <user> -p <database> < database/database.sql
```

**3. Run the web bootstrap:**

Open `<APP_URL>/bootstrap.php` in your browser. This finalizes the framework configuration.

**4. Point your web server document root to `src/api/`.**

Example Apache virtual host:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/project/src/api

    <Directory /path/to/project/src/api>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Applying migrations

When upgrading an existing installation, run the relevant migration files against your database:

```bash
mariadb -u <user> -p <database> < database/migration-3.3.0.sql
```

Run migration files in order. Do not run them on a fresh install — `database.sql` already includes all schema changes up to the current version.
