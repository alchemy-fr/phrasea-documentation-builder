#!/bin/bash

# Variables
REPO_OWNER="alchemy-fr"
REPO_NAME="phrasea"
export REPO_OWNER
export REPO_NAME

function getTag()
{
  echo $1
  TAG=$1

  ARCHIVE_URL="https://github.com/$REPO_OWNER/$REPO_NAME/archive/refs/tags/$TAG.tar.gz"

  GZ_FILE="/tmp/$REPO_NAME-$TAG.tar.gz"
  GZ_DIR="/tmp/$REPO_NAME-$TAG"
  DOC_DIR="./my-app/content/docs/$TAG"

  # Download the archive
  echo "Downloading $TAG from $REPO_NAME repository to $GZ_FILE"
  # !! use dns to prevent __random__ (?) DNS resolution issues "curl: (6) Could not resolve host: codeload.github.com
  curl --connect-timeout 5 --dns-servers 8.8.8.8 -L -o "$GZ_FILE" "$ARCHIVE_URL"

  # Check if the download was successful
  if [ $? -ne 0 ]; then
      echo "Failed to download $ARCHIVE_URL"
      exit 0
  fi

  # Decompress the archive
  echo "Decompressing $GZ_FILE..."
  tar  -zx -f "$GZ_FILE" -C /tmp

  # Check if decompression was successful
  if [ $? -ne 0 ]; then
      echo "Failed to decompress $GZ_FILE"
      rm "$GZ_FILE"
      exit 0
  fi
  rm "$GZ_FILE"

  mkdir -p "$DOC_DIR/databox"
  cp "$GZ_DIR/README.md" "$DOC_DIR/README.md"
  cp -r "$GZ_DIR/doc/" "$DOC_DIR"

  # for test only : fake a FR file
  cp "$GZ_DIR/README.md" "$DOC_DIR/README.fr.md"

  find "$DOC_DIR" -type f -name "*.md" | while read -r file; do
    # fix ShikiError: Language `sequence` is not included in this bundle
    sed -i 's/```sequence/```/g' "$file"
    # fix missing "front matter" header
    filename=$(basename "$file")
    if ! grep -q "^---" "$file"; then
      echo -e -n "---\ntitle: $filename\n---\n" | cat - "$file" > "${file}.tmp" && mv "${file}.tmp" "$file"
      echo "Updated: $file"
    else
      echo "Already starts with '---': $file"
    fi
  done

  rm -rf "$GZ_DIR"
}
export -f getTag


# Fetch tags from GitHub
tags=$(curl -s "https://api.github.com/repos/$REPO_OWNER/$REPO_NAME/tags" | jq -r '.[].name')

# Process tags to get the latest minor version for each major version
echo "$tags" | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | awk -F. '
{
    major=$1; minor=$2; patch=$3;
    key=major"."minor;
    latest[key]=$0;
}
END {
    for (key in latest) {
        TAG=latest[key];
        print TAG;
    }
}' | xargs -n1 bash -c 'getTag $0';
