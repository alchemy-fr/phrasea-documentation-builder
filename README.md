# Documentation Builder

## phrasea files structure

The files are extracted from 2 types of source:
- the GitHub repository

    contains "static" files in the `/doc` directory.
- the docker images

    contains "dynamic" files generated during the build.


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

To translate a chapter label (or to give it a friendly english name), add a `_locales.yml` **in** the directory (`.yml`, not `.yaml`):

```yaml
# /doc/tech/storage/_locales.yml
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

- A **push** on any phrasea branch, when one of the pushed commit messages contains `[documentation]` (brackets included)

    The image is tagged with the name of the branch, e.g. `"PS-xxx_my-doc-update"`

The trigger lives in phrasea's workflows, which send a `repository_dispatch` to this repository:

- `next`, `master` and `release/**`: `ci.yaml`, at the end of the `setup` job, once all the images are built, tested and pushed.
  If a step fails (tests included), the documentation is not built. `pull_request`, `schedule` and manual runs never build it.
- any other branch: `documentation.yaml`, which builds only the `databox`, `expose` and `uploader` `api-php` images and the `configurator`
  (the ones holding `/srv/app/doc`) and triggers this builder with `PHRASEA_IMAGE_REGISTRY=ghcr.io/alchemy-fr` and `PHRASEA_IMAGE_TAG=<commit sha>`:
  the images are read from GHCR, the build cache, and never reach the public registries since they are not tested.
  It can also be run manually from the Actions tab (once the workflow exists on the default branch).
- tags: `release.yaml`.

Without `PHRASEA_IMAGE_REGISTRY`/`PHRASEA_IMAGE_TAG`, `bin/download-tag.sh` reads the public ECR images tagged with the ref name.

A branch name holding a `/` is not supported yet: `bin/download-tag.sh` refuses it, and the documentation image is tagged with the ref name.

See phrasea's `doc/tech/Development/ci.md` (Commit markers).


Variables are defined by https://github.com/alchemy-fr/phrasea-documentation-builder/settings/environments

- `MIN_VERSION` The minimum phrasea version to include, e.g. `3.12.5`
- `VERSIONS_COUNT` The maximum number of versions to include on "releases" documentation.
- `DOCS_BUILD_STRICT` Controls strict mode for the documentation build (`true` or `false`, default: `false`).
  - `true`: prebuild placeholders are disabled and any missing imported markdown/MDX dependency can fail the final build.
  - `false`: prebuild placeholders are enabled to tolerate missing imported markdown/MDX dependencies before `pnpm run build`.

e.g. `MIN_VERSION=1.1.2` ; `VERSIONS_COUNT=3` ; `DOCS_BUILD_STRICT=false`

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
