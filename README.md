# documentation builder

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

because 1.2.1-beta4 will be interpreted as 1.2.14, and elected as > 1.2.3

---
## Serve

### a "branch" version
`docker run -p 8085:80 ghcr.io/alchemy-fr/phrasea-documentation:ps-906_documentation-refacto-md`

### a "tag" version
`docker run -p 8085:80 ghcr.io/alchemy-fr/phrasea-documentation:1.2.3`

### the lastest tag
`docker run -p 8085:80 ghcr.io/alchemy-fr/phrasea-documentation`
