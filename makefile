IMAGE_NAME = laravel-opentelemetry-development
CONTAINER_NAME = $(IMAGE_NAME)

build:
	docker build -t $(IMAGE_NAME) --build-arg USER_ID=$(shell id -u) --build-arg GROUP_ID=$(shell id -g) .

start:
	docker run --rm -it --name $(CONTAINER_NAME) -v $(CURDIR):/var/www/html $(IMAGE_NAME)

shell:
	docker exec -it $(CONTAINER_NAME) /bin/bash || \
	echo "Container '$(CONTAINER_NAME)' is not running. Start it with 'make start', or build it with 'make build' if you didn't yet."
