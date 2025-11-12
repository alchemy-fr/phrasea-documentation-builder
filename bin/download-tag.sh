#!/bin/bash

set -ex

TAG=$1

# Checks TAG dos not contain slashes or spaces
if [[ "$TAG" =~ [\ /] ]]; then
  echo "Error: TAG should not contain slashes or spaces."
  exit 1
fi

function fetch_container() {
  local image=$1
  local app=$2

  docker pull ${image}
  docker run --rm --entrypoint="" ${image} test -d /srv/app/doc
  if [ "$?" -eq "0" ]; then
    IMAGE_ID=$(docker create ${image})
    mkdir -p ./$TAG/_generated/$app
    docker cp $IMAGE_ID:/srv/app/doc ./$TAG/_generated/$app
    docker rm -v $IMAGE_ID
  else
    echo "No /srv/app/doc folder found in image $image"
  fi
}

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
  && for app in databox expose uploader; do
    fetch_container "public.ecr.aws/alchemyfr/ps-$app-api-php:$TAG" $app
  done \
  && fetch_container "public.ecr.aws/alchemyfr/ps-configurator:$TAG" configurator
)
