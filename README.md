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
- `NEXT_BRANCH` name of the "current" branch that will be named "Next" on documentation

