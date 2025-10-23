#!/bin/bash
TAG=$1
echo TAG: $1

mkdir -p ./downloads/$TAG/src

# copy the base doc from the GitHub repository
# nb: sparse checkout is experimental
#     we clone in a tmp folder and then move the doc/ folder

git clone --filter=blob:none --no-checkout https://github.com/alchemy-fr/phrasea.git ./downloads/tmpclone/
cd ./downloads/tmpclone
git fetch origin
git checkout $TAG
cd ../../
cp -r  ./downloads/tmpclone/doc ./downloads/$TAG/src/

cat ./downloads/tmpclone/doc/include.list | while read -r p; do
  if [[ -d "./downloads/tmpclone/$p" ]]; then
    mkdir -p "./downloads/$TAG/src/doc/$p"
    cp -r "./downloads/tmpclone/$p" "./downloads/$TAG/src/doc/$p/.."
  elif [[ -f "./downloads/tmpclone/$p" ]]; then
    install -D "./downloads/tmpclone/$p" "./downloads/$TAG/src/doc/$p"
  fi
done
rm -rf ./downloads/tmpclone

# copy the databox-api-php doc from the docker image

mkdir -p ./downloads/$TAG/_generated/databox
docker pull public.ecr.aws/alchemyfr/ps-databox-api-php:$TAG
IMAGE_ID=$(docker create public.ecr.aws/alchemyfr/ps-databox-api-php:$TAG)
docker cp $IMAGE_ID:/srv/app/doc ./downloads/$TAG/_generated/databox || echo "No /srv/app/doc folder found in image"
docker rm -v $IMAGE_ID
