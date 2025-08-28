# documentation builder

Build the documentation from a phrasea release.
- pull the `phrasea-doc.zip`.
- build a static documentation with Docusaurus.
- push the result to the github documentation repository (or serve it locally).

## env-vars
to define in `.env.local`

- `DOC_GITHUB_TOKEN`="github_pat_xxxx"

    token with write (push) access to the documentation repository
    
    If not set, the builder will not push the result, but can serve it from the integrated web-server


- `PHRASEA_TAG`="x.y.z"

    phrasea tag to generate documentation for (e.g. "0.2.8").

    If not set, takes the highest tag

    The builder will push the documentation to a directory x.y (e.g. "0.2"), ignoring the 'z' patch number.

## mode "build and push"

**define** the `DOC_GITHUB_TOKEN` env-var into `.env.local`

`dc up` will build the documentation in a clone of the documentation repository, then commit/push it back.

The cloned repository is deleted afert push, so it is not possible to serve the documentation locally.

## mode "build and serve"

**comment** the `DOC_GITHUB_TOKEN` into `.env.local`

`dc up` will build the documentation into a "build" directory
and serve it on http://localhost:3078/


## mode "developper"

`dc run --rm -p 3078:3000 documentation bash`

### app "builder"

`cd` to the builder (php) app in `/srv/app`

run `php application.php export --help`

### app "docusaurus"

`cd` to the the docusaurus / phrasea site in `/srv/docusaurus/phrasea`, run

```shell
pnpm run start  # dev mode
# or
pnpm run serve
```


