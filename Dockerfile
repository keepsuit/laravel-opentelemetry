FROM serversideup/php:8.2-cli

ARG USER_ID
ARG GROUP_ID

USER root

RUN install-php-extensions grpc
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID

USER www-data
