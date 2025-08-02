.PHONY: $(MAKECMDGOALS)

build:
	docker compose build \
		--build-arg USER_ID=$(shell id -u) \
		--build-arg GROUP_ID=$(shell id -g)

start:
	@if ! docker compose ps --format '{{.Service}} {{.State}}' | grep -q '^app running$$'; then \
		docker compose up -d; \
	fi

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
