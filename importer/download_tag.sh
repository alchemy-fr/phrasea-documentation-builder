#!/bin/bash
TAG=$1

echo TAG: $1

mkdir -p ./importer/downloads/$TAG

# copy the base doc from the GitHub repository
# nb: sparse checkout is experimental
#     we clone in a tmp folder and then move the doc/ folder

git clone --filter=blob:none --no-checkout https://github.com/alchemy-fr/phrasea.git ./importer/downloads/tmpclone/
cd ./importer/downloads/tmpclone
git fetch origin
git sparse-checkout set doc
git checkout $TAG
cd ../../../
mv ./importer/downloads/tmpclone/doc ./importer/downloads/$TAG/
rm -rf ./importer/downloads/tmpclone

# copy the databox-api-php doc from the docker image

mkdir -p ./importer/downloads/$TAG/generated/databox
docker pull public.ecr.aws/alchemyfr/ps-databox-api-php:$TAG
IMAGE_ID=$(docker create public.ecr.aws/alchemyfr/ps-databox-api-php:$TAG)
docker cp $IMAGE_ID:/srv/app/doc ./importer/downloads/$TAG/generated/databox || echo "No /srv/app/doc folder found in image"
docker rm -v $IMAGE_ID
