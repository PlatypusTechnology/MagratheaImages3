# Magrathea V2

## starting a Magrathea Project

### STEP 1: define URLs
Define the urls that will be used (at least on dev).
For this tutorial, let's suppose we're using `stark-industres.localhost.com`
Then open hosts file (in Linux under `\etc\hosts`) and add the alias:
`127.0.0.1			stark-industres.localhost.com`

### STEP 2: setup docker
Setup docker
Here it goes a `docker-compose.yml` sample just for you:
```
version: '3.7'
services:
  mag_sql:
    image: mariadb
    container_name: "database-sql"
    command: --default-authentication-plugin=mysql_native_password
    volumes:
      - ./database:/home/backups
    env_file:
      - ./docker/.env
    ports:
      - 3306

  magrathea_images:
    hostname: stark-industres.localhost.com
    container_name: "magrathea-images"
    build:
      context: .
      dockerfile: ./docker/Dockerfile
    volumes: 
      - ./src:/var/www/html
      - ./logs:/var/www/logs
      - ./backups:/var/www/backups
#      - ./src/api/compress:/var/www/compress
    ports:
      - 8080:80
      - 443
    env_file:
      - ./docker/.env

# helper containers:
  magrathea_phpmyadmin:
    image: phpmyadmin/phpmyadmin
    container_name: magrathea-dev_pma
    environment:
      PMA_HOST: mag_sql
      PMA_PORT: 3306
      PMA_ARBITRARY: 1
    depends_on:
      - mag_sql
    ports:
      - 8183:80
```
See that `magrathea_phpmyadmin` container there? That's just a helper.
If you feel you don't need to check your SQL shit, you can remove it.

Docker also uses a Dockerfile for loading the PHP and a `.env`
For those we can create a `docker` folder in the root and add:
`.env`: 
```
MYSQL_ROOT_PASSWORD=root
MYSQL_DATABASE=stark_industries
MYSQL_USER=user
MYSQL_PASSWORD=password
JWT_SECRET=jwt_secret
```

`Dockerfile`: 
```
FROM php:8.1-apache

COPY ./docker/apache/site-dev.conf /etc/apache2/sites-available/000-default.conf
RUN rm /etc/apache2/sites-enabled/000-default.conf

RUN docker-php-ext-install pdo pdo_mysql
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

RUN apt-get update
RUN apt-get install -y \
        git \
        curl \
        g++

RUN a2enmod rewrite
RUN a2enmod ssl
RUN a2ensite 000-default.conf

RUN service apache2 restart

EXPOSE 80
EXPOSE 8080
EXPOSE 443
```

### STEP 3: setup apache
Ok... Docker is copying inside our container a `side-dev.conf` apache file for loading the app. we can create it inside our `docker` folder.
```
ServerName stark-industres.localhost.com

<VirtualHost *:80>
	ServerName stark-industres.localhost.com
	ServerAlias www.stark-industres.localhost.com

	ServerAdmin webmaster@localhost
	DocumentRoot /var/www/html/api
	ErrorLog ${APACHE_LOG_DIR}/error.log
	CustomLog ${APACHE_LOG_DIR}/access.log combined
	Options Indexes FollowSymLinks

	<Directory /var/www/html/api>
		Options Indexes FollowSymLinks
		AllowOverride All
		Require all granted
	</Directory>
</VirtualHost>
```
of course, you shall rename `stark-industres.localhost.com` to your chosed domain.

### STEP 4: install Magrathea
So far you just created the environment for you to code.
As you can see, we're putting our project inside a `src` file of our root. So, to keep going, we will install our composer libraries in that folder.

Go there and run:
`composer require platypustechnology/magratheaphp2`
If you wish a dev-version:
`composer require platypustechnology/magratheaphp2:dev-main`
This will create the `vendor` folder with the required files

### STEP 5: config file
Inside `src/configs` we create a `magrathea.conf`
Like this:
```
[general]
	use_environment = "dev"
	time_zone = "America/Sao_Paulo"

[dev]
	db_host = "mag_sql"
	db_name = "stark_industries"
	db_user = "user"
	db_pass = "password"
	logs_path = "/var/www/logs"
	backups_path = "/var/www/backups"
	time_zone = "America/Sao_Paulo"
	app_url = "http://stark-industres.localhost.com:8080"
	jwt_key = ""

[production]
	db_host = "127.0.0.1"
	db_name = "db_name"
	db_user = "db_user"
	db_pass = "password123"
	logs_path = ""
	backups_path = ""
	timezone = "America/Sao_Paulo"
	app_url = "https://platypusweb.com"
	jwt_key = ""
```

### STEP 6: bootstraping
Let's dive on Magrathea now.
As the docker shows (this can be changed if you wish), our main development folder is inside `src/api/`
Create a file there (let's call it `bootstrap.php`):
```
<?php
//die;
require "../vendor/autoload.php";

Magrathea2\MagratheaPHP::Instance()
	->AppPath(realpath(dirname(__FILE__)))
	->Dev()
	->Load();
Magrathea2\Bootstrap\Start::Instance()->Load();
```

Now run your docker, go to `stark-industres.localhost.com:8080/bootstrap.php` and you shall already see the magic happening.

The first screen will ask you to create some basic structure:
- a `backups` folder (for database backups);
- a `logs` folder;
Those folders are defined in our `magrathea.conf` file.
Docker will look for both this folders in the root of the application. You can create them there so.

## troubleshooting

### permissions
if you're having problems with permissions, maybe you would have to change ownership of directory to php group. This can be done with: 
`sudo chown -R www-data:www-data *`
(and can be moved back with a similar call, but setting your own user)