FROM serversideup/php:8.2-cli

# see: https://serversideup.net/open-source/docker-php/docs/guide/understanding-file-permissions#how-it-works
ARG USER_ID=33
ARG GROUP_ID=33

# see: https://serversideup.net/open-source/docker-php/docs/reference/environment-variable-specification
ENV SHOW_WELCOME_MESSAGE=false

USER root

RUN install-php-extensions grpc
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID

USER www-data
