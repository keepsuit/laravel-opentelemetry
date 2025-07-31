USER_ID = $(shell id -u)
GROUP_ID = $(shell id -g)

build:
	USER_ID=$(USER_ID) GROUP_ID=$(GROUP_ID) docker compose build

start:
	docker compose up -d

stop:
	docker compose down

shell:
	make start
	docker compose exec -it app /bin/bash

test:
	make start
	docker compose exec -it app /usr/bin/composer test

lint:
	make start
	docker compose exec -it app /usr/bin/composer lint
