# documentation builder

## phrasea files structure

The files are extracted from 2 types of source:
- the GitHub repository

    contains "static" files in the `/doc` directory.
- the docker images

    contains "dynamic" files generated during the build.

    *for now only `databox-api-php` generates dynamic files.*

### "outdoc" files:
Some static files **referenced by `/doc`** pages are **outside** the `/doc` directory, e.g.
- configuration files, sources files, ...
- README.md files of each "application" like dashboard, databox, uploader, ...
- relative `doc` directories inside those same apps.

To include an "outdoc" file or directory to the documentation, add it to the `/doc/include.list` file:

```text
/bin/setup.sh
/configs/config.json
/dashboard/client/README.md
/databox/README.md
/databox/indexer/doc
...
```

### i18n:

#### Translating a page **label**:

The displayed label comes from
- The Front Matter `title`, e.g.:

```yaml
---
title: 'Attribute Initial Values'
---
```

- else, the first Header in markdown, e.g.:

```markdown
# Setup (with docker-compose)
```
- else, the name of the file


#### Translating a page **content**:

To translate a **file** `foobar.md` (default locale='en') to french: Create `foobar.fr.md` in the same directory.

#### Translating a directory (chapter) label:

By default, for the "chapters" navigation tree (left sidebar), directory names used.

To translate a chapter label (or to give it a friendly english name), add a `_locales.yaml` **in** the directory:

```yaml
# /doc/tech/storage/_locales.yaml
fr: Stockage
en: Storage
```

### Order of items in navigation sidebar

#### Order of pages (files)

Files are handled by alphabetical order. Add a numerical prefix to the name, e.g.: `/doc/tech/01_setup.md`

Don't forget to set a visible label in the file (by FrontMatter title or markdown Header), 
or the filename will be shown.

important: Links to this page must be fixed in all other md files !

#### Order of chapters (directories)

------------ to be checked ------------- 

---

## Documentation generation

Documentation is generated when:

- A **release** X.Y.Z of phrasea is made
    
    The image is tagged `X.Y.Z` and `latest`


- A **push** on a phrasea branch with "[documentation]" in the last commit message

    The image is tagged with the name of the branch, e.g. `"PS-xxx_my-doc-update"`


Variables are defined by https://github.com/alchemy-fr/phrasea-documentation-builder/settings/environments

- `MIN_VERSION` The minimum phrasea version to include, e.g. 3.12.5
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

---

### warning

use only **numbers** in phrasea releases:

1.2.3 ; ~~v1.2.3~~ ; ~~1.2.1-beta4~~

because 1.2.1-beta4 will be interpreted as 1.2.14, and elected as > 1.2.3 :(

---
## Serve

### a "branch" version
`docker run -p 8085:80 ghcr.io/alchemy-fr/phrasea-documentation:ps-906_documentation-refacto-md`

### a "tag" version
`docker run -p 8085:80 ghcr.io/alchemy-fr/phrasea-documentation:1.2.3`

### the lastest tag
`docker run -p 8085:80 ghcr.io/alchemy-fr/phrasea-documentation`
