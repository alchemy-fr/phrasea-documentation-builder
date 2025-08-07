# documentation builder

## serve static documentation by integrated web server
`dc up -d`

## serve from the container

http://localhost:3078/


## dev mode
`dc run --rm -p 3078:3000 documentation "pnpm run start"`

or

dc run --rm -p 3078:3000 documentation bash

then in the container

```shell
pnpm run start  # dev mode
# or
pnpm run serve
```
