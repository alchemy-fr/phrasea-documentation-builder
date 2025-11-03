#!/bin/bash

set -e

TAG=$1

# Checks TAG dos not contain slashes or spaces
if [[ "$TAG" =~ [\ /] ]]; then
  echo "Error: TAG should not contain slashes or spaces."
  exit 1
fi

DATABOX_IMAGE="public.ecr.aws/alchemyfr/ps-databox-api-php:$TAG"

(cd ./downloads \
  && rm -rf ./tmpclone \
  && mkdir -p ./$TAG/src \
  && git clone --filter=blob:none --no-checkout https://github.com/alchemy-fr/phrasea.git ./tmpclone \
  && (cd ./tmpclone \
    && git fetch origin \
    && git checkout $TAG \
  ) \
  && cp -r ./tmpclone/doc ./$TAG/src/ \
  && rm -rf ./tmpclone \
  && mkdir -p ./$TAG/_generated/databox \
  && docker pull ${DATABOX_IMAGE} \
  && IMAGE_ID=$(docker create ${DATABOX_IMAGE}) \
  && docker cp $IMAGE_ID:/srv/app/doc ./$TAG/_generated/databox || echo "No /srv/app/doc folder found in image" \
  && docker rm -v $IMAGE_ID
)
