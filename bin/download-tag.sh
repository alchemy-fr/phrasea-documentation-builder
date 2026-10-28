#!/bin/bash

set -ex

TAG=$1
# The phrasea images to read /srv/app/doc from: the public ECR images tagged
# with the ref by default, or e.g. the GHCR build images of a work branch
# (ghcr.io/alchemy-fr + the commit sha), which never reach a public registry.
IMAGE_TAG=${2:-$TAG}
IMAGE_REGISTRY=${3:-public.ecr.aws/alchemyfr}

# Checks TAG dos not contain slashes or spaces
if [[ "$TAG" =~ [\ /] ]]; then
  echo "Error: TAG should not contain slashes or spaces."
  exit 1
fi

function fetch_container() {
  local image=$1
  local app=$2

  docker pull ${image} || exit 1
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
  && if [[ "$IMAGE_REGISTRY" == public.ecr.aws/* ]]; then
    aws ecr-public get-login-password --region us-east-1 | docker login --username AWS --password-stdin public.ecr.aws
  fi \
  && for app in databox expose uploader; do
    fetch_container "$IMAGE_REGISTRY/ps-$app-api-php:$IMAGE_TAG" $app
  done \
  && fetch_container "$IMAGE_REGISTRY/ps-configurator:$IMAGE_TAG" configurator
)

