FROM php:8.1-apache

COPY ./docker/apache/site-dev.conf /etc/apache2/sites-available/000-default.conf
RUN rm /etc/apache2/sites-enabled/000-default.conf

RUN docker-php-ext-install pdo pdo_mysql
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

#GD:
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && sync && \
	install-php-extensions gd xdebug

RUN apt-get update
RUN apt-get install -y \
        git \
        curl \
# 		libfreetype6-dev \
# 		libwebp-dev \
# 		libjpeg62-turbo-dev
        g++

# RUN docker-php-ext-install gd \
# 	&& docker-php-ext-configure gd \
# 		--with-jpeg \
#     && docker-php-ext-install -j$(nproc) gd
# RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

RUN a2enmod rewrite
RUN a2enmod ssl
RUN a2ensite 000-default.conf

RUN service apache2 restart

# INSTALL COMPOSER
RUN php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer
RUN alias composer='php composer.phar'
# RUN cd /var/www/html && ls && composer install --ignore-platform-reqs

EXPOSE 80
EXPOSE 8080
EXPOSE 443
