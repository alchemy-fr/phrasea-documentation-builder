# Documentation Builder

## phrasea files structure

The files are extracted from 2 types of source:
- the GitHub repository

    contains "static" files in the `/doc` directory.
- the docker images

    contains "dynamic" files generated during the build.

    *for now only `databox-api-php` generates dynamic files.*


## i18n

### Translating a page **title**:

The displayed title comes either from
- The Front Matter `title`, e.g.:

```yaml
---
title: 'Attribute Initial Values'
---
```

- or the first Header in the markdown content, e.g.:

```markdown
# My Page Title
```

- or the name of the file (without extension)


### Translating page **content**:

To translate a file `foobar.md` (default locale='en') to french, create `foobar.fr.md` in the same directory.

### Translating a directory (chapter) label:

By default, for the "chapters" navigation tree (left sidebar), directory names used.

To translate a chapter label (or to give it a friendly english name), add a `_locales.yaml` **in** the directory:

```yaml
# /doc/tech/storage/_locales.yaml
fr: Stockage
en: Storage
```

## Order of items in navigation sidebar

### Order of pages (files)

Files are handled by alphabetical order. Add a numerical prefix to the name, e.g.: `/doc/tech/01_setup.md`

Don't forget to set a visible label in the file (by FrontMatter title or markdown Header), 
or the filename will be shown.

important: Links to this page must be fixed in all other md files !

### Order of chapters (directories)

TODO

## Documentation generation

Documentation is generated when:

- A **release** X.Y.Z of phrasea is published on GitHub
    
    The image is tagged `X.Y.Z` and `latest`

- A **push** on a phrasea branch and the last commit message contains `[documentation]`

    The image is tagged with the name of the branch, e.g. `"PS-xxx_my-doc-update"`


Variables are defined by https://github.com/alchemy-fr/phrasea-documentation-builder/settings/environments

- `MIN_VERSION` The minimum phrasea version to include, e.g. `3.12.5`
- `VERSIONS_COUNT` The maximum number of versions to include on "releases" documentation.

e.g. `MIN_VERSION=1.1.2` ; `VERSIONS_COUNT=3`

~~1.0.0~~  
~~1.1.0 ; 1.1.1 ;~~ 1.1.2 ; 1.1.5  
1.2.0 ; 1.2.1  
1.3.0 ; 1.3.1 ; **1.3.2**  
1.4.0 ; **1.4.1**  
2.0.0 ; 2.0.1 ; **2.0.2**

- versions < 1.1.2 are ignored
- the 3 highest \<major>.\<minor> versions are selected
- the highest \<patch> is used

### Important note about versioning

When releasing phrasea,
use only **numbers** in phrasea releases:

1.2.3 ; ~~v1.2.3~~ ; ~~1.2.1-beta4~~

## Deploying and testing the documentation site locally

Set the `IMAGE_TAG` (default: `latest`) environment variable to the desired version:

```bash
# A "branch" version:
# export IMAGE_TAG=ps-906_documentation-refacto-md
# A "tag" version:
# export IMAGE_TAG=1.2.3
docker compose up -d
```

Then open your browser at [http://localhost:8080](http://localhost:8080)

See [.env](./.env) for other configuration options.

## Development

To build the documentation image locally (for development or testing):

1. Ensure you have generated the dynamic documentation files by running the appropriate build scripts in your local phrasea repository.

2. Run the build-local script with the path to your phrasea repository:

```bash
./bin/dev/build-local.sh /path/to/phrasea/repo
```
