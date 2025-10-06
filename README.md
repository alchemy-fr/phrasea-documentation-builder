# documentation builder


choose environment: `sandbox-ci-documentation` or `phrasea`

```yaml
# .github/workflows/on_push.yaml
jobs:
  pull-ecr-images:
    environment: sandbox-ci-documentation
```

variables are defined by https://github.com/alchemy-fr/phrasea-documentation-builder/settings/environments

- `PHRASEA_GITHUB` github repository
- `PHRASEA_IMAGES` images repository
- `MIN_VERSION` The minimum phrasea version to include; 
As **number** with each segment 0...99, like: v3.12.5 ==> 31205 

