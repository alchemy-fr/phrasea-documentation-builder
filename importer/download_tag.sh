#!/bin/bash
echo PHRASEA_GITHUB=$PHRASEA_GITHUB
echo PHRASEA_IMAGES=$PHRASEA_IMAGES
echo TAG=$1

mkdir -p ./importer/downloads/$1

# copy the base doc from the GitHub repository
# nb: sparse checkout is experimental and also clone some unwnanted files from / (like /README.md) ?
#     so we clone in a tmp folder and then move the doc/ folder

git clone --depth 1 --filter=blob:none --sparse https://github.com/$PHRASEA_GITHUB.git ./importer/downloads/tmpclone/
cd ./importer/downloads/tmpclone
git sparse-checkout set doc
git checkout $1
echo "================ tmpclone ================ "
tree .

cd ../../../
mv ./importer/downloads/tmpclone/doc ./importer/downloads/$1/
rm -rf ./importer/downloads/tmpclone
echo "================ downloads $1 after gh clone ================ "
tree ./importer/downloads/$1


# copy the databox-api-php doc from the docker image

mkdir -p ./importer/downloads/$1/databox-api-php
docker pull $PHRASEA_IMAGES:$1
IMAGE_ID=$(docker create $PHRASEA_IMAGES:$1)
docker cp $IMAGE_ID:/srv/app/databox/api/doc/ ./importer/downloads/$1/databox-api-php/ || echo "No doc/ folder found in image"
docker rm -v $IMAGE_ID

echo "================ downloads $1 after image pull ================ "
tree ./importer/downloads/$1
