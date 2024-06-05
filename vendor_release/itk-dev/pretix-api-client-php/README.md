# PHP pretix api client

```sh
composer require itk-dev/pretix-api-client-php
```

## Test

```sh
docker-compose up -d
docker-compose exec phpfpm composer install
```

```sh
docker-compose exec pretix python /pretix/src/manage.py migrate
docker-compose exec pretix python /pretix/src/manage.py compress
docker-compose exec pretix python /pretix/src/manage.py collectstatic --no-input
```

Run the tests:

```sh
./scripts/test
```

## pretix

To access pretix, add

```sh
0.0.0.0 pretix
```

to your `/etc/hosts` file and run

```sh
open http://pretix:$(docker-compose port pretix 80 | cut -d: -f2)/control
```

Sign in as `admin@localhost` with password `admin`.
