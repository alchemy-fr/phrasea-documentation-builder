# phrasea-documentation-builder

# Prerequisites
- Docker >= 27.3.1
- Docker Compose >= 2.30.3

We are using Docker Compose as a shell function named `dc`. More information about `dc` and why we use it can be found [here](https://github.com/alchemy-fr/Phraseanet#using-a-envlocal-method-for-custom-env-values).

Note: `dc ...` can be replaced by `docker compose ...`.

This documentation is built with [Fumadoc](https://fumadocs.vercel.app/) for its generation and management.


## build
```shell
dc build documentation
```

## run
```shell
dc up -d
```

Browse from your Docker Host `http://localhost:3000/` (if EXPOSITION_PORT is set to 3000)

browse `http://174.30.0.2:3000/`

## run dev mode
```shell
dc run --rm documentation pnpm run dev
```

## shell
```shell
dc run --rm documentation shell
```
