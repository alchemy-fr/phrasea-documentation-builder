#!/bin/bash
TAG=$1
echo TAG: $1

cd ./importer/downloads

mkdir -p ./$TAG/src

# copy the base doc from the GitHub repository

git clone --filter=blob:none --no-checkout https://github.com/alchemy-fr/phrasea.git ./tmpclone/
cd ./tmpclone
git fetch origin
git checkout $TAG
cd ..
cp -r  ./tmpclone/doc ./$TAG/src/

cat ./tmpclone/doc/include.list | while read -r p; do
  if [[ -d "./tmpclone/$p" ]]; then
    mkdir -p "./$TAG/src/doc/$p"
    cp -r "./tmpclone/$p" "./$TAG/src/doc/$p/.."
  elif [[ -f "./tmpclone/$p" ]]; then
    install -D "./tmpclone/$p" "./$TAG/src/doc/$p"
  fi
done
rm -rf ./tmpclone

# copy the databox-api-php doc from the docker image

mkdir -p ./$TAG/_generated/databox
docker pull public.ecr.aws/alchemyfr/ps-databox-api-php:$TAG
IMAGE_ID=$(docker create public.ecr.aws/alchemyfr/ps-databox-api-php:$TAG)
docker cp $IMAGE_ID:/srv/app/doc ./$TAG/_generated/databox || echo "No /srv/app/doc folder found in image"
docker rm -v $IMAGE_ID

cd ../..
