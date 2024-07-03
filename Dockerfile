FROM itkdev/php8.1-fpm:latest

USER root

RUN apt-get update && apt-get --yes install rsync && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Set bash as default shell
# Make `sh` point to `bash` (rather than `dash` which is the default in Ubuntu) to resolve
# `sh: 1: [[: not found` error due to `"post-drupal-scaffold-cmd"` in composer.json.
RUN rm /bin/sh && ln -s bash /bin/sh

# Cf. `docker image inspect --format '{{.Config.User}}' itkdev/php8.1-fpm:latest`
USER deploy
