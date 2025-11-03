#!/bin/bash

set -ex

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 /path/to/local/phrasea/repo"
    exit 1
fi

PHRASEA_DIR="$1"

export TAG=local-dev

DEST="./downloads/${TAG}"

rm -rf "${DEST}"

mkdir -p "${DEST}/src"
cp -r "$PHRASEA_DIR/doc" "${DEST}/src/doc"

for dir in databox expose uploader; do
  mkdir -p "${DEST}/_generated/${dir}"
  cp -r "$PHRASEA_DIR/${dir}/api/doc" "${DEST}/_generated/${dir}/"
done

export PHRASEA_REFTYPE=branch
export PHRASEA_REFNAME=${TAG}
export PHRASEA_REFNAME=${TAG}
export SITE_NAME="Phrasea Dev"

docker compose build \
  && docker compose up -d
