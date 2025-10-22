# documentation builder

Documentation is generated when:

- A **release** X.Y.Z of phrasea is made
    
    The image is tagged `X.Y.Z` and `latest`


- A **push** on a phrasea branch with "[documentation]" in the last commit message

    The image is tagged with the name of the branch, e.g. `"PS-xxx_my-doc-update"`


Variables are defined by https://github.com/alchemy-fr/phrasea-documentation-builder/settings/environments

- `MIN_VERSION` The minimum phrasea version to include, e.g. 3.12.5
- `VERSIONS_COUNT` The maximum number of versions to include on "releases" documentation.




