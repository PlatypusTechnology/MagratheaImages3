# MAGRATHEA IMAGES 3

Images manager based on Magrathea framework

## DB
### grant privileges:
```
docker-compose exec ${DB_DOCKER_NAME} sh -c 'mariadb -uroot -p${MYSQL_ROOT_PASSWORD}'
CREATE DATABASE ${MYSQL_DATABASE};
GRANT ALL PRIVILEGES ON ${MYSQL_USER}.* TO '${MYSQL_USER}'@'%' WITH GRANT OPTION;
```

```
docker-compose exec mag_sql sh -c 'mariadb -uroot -proot'
CREATE DATABASE magrathea_cloud;
GRANT ALL PRIVILEGES ON user.* TO 'user'@'%' WITH GRANT OPTION;
```

### APP Configuration:
```
==[n]app_name==|>>Magrathea Images>>;;
==[s]code_path==|>>/var/www/html/api>>;;
==[s]code_structure==|>>feature>>;;
==[s]code_namespace==|>>MagratheaImages3>>;;
```

