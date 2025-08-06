# DOCUSAURUS

## fresh install including openapi sample

https://github.com/PaloAltoNetworks/docusaurus-openapi-docs

https://github.com/PaloAltoNetworks/docusaurus-openapi-docs?tab=readme-ov-file#bootstrapping-from-template-new-docusaurus-site
This will fail using "git repository method" so we clone the repo locally

```shell
mkdir phrasea-documentation-builder/
cd phrasea-documentation-builder/
# we will use local template
git clone https://github.com/PaloAltoNetworks/docusaurus-template-openapi-docs.git
npx create-docusaurus@3.8.1 --typescript --package-manager pnpm phrasea docusaurus-template-openapi-docs
# ? this installed 3.7 ?

# prevent accidental commit
rm -rf docusaurus-template-openapi-docs/.git
rm  docusaurus-template-openapi-docs/.gitignore

cd phrasea
# bump to 3.8
pnpm i react@18 react-dom@18 @docusaurus/core@3.8.1 @docusaurus/preset-classic@3.8.1 @docusaurus/theme-common@3.8.1 @docusaurus/plugin-content-docs@3.8.1 @docusaurus/types@3.8.1
pnpm build

pnpm run serve
```

## add search

https://github.com/praveenn77/docusaurus-lunr-search

```shell
pnpm i docusaurus-lunr-search@3.6 lunr lunr-languages
pnpm dedupe
```

### set config for search

```typescript
// docusaurus.config.ts

const config: Config = {
    ...
    plugins: [
        ...,
        // add parsing
        [ 
            require.resolve('docusaurus-lunr-search'),
            {
                languages: ['en', 'fr'] // language codes
            }
        ]
    ],
    ...
};
```

## add i18n
### set config

```typescript
// docusaurus.config.ts

const config: Config = {
    ...
    // ------ add i18 settings
    i18n: {
        defaultLocale: 'en',
        locales: ['en', 'fr'],
        path: 'i18n',
        localeConfigs: {
            en: {
                label: 'English',
                direction: 'ltr',
                htmlLang: 'en-US',
                calendar: 'gregory',
                path: 'en',
            },
            fr: {
                label: 'Français',
                direction: 'ltr',
                htmlLang: 'fr-FR',
                calendar: 'gregory',
                path: 'fr',
            },
        },
    },
    ...
    themeConfig: {
        navbar: {
            items: [
                ...,
                // ------ add locale dropdown select
                {
                    type: 'localeDropdown',
                    position: 'left',
                },
            ],
        },
    },
    ...
};
```
### translate md files

a (incomplete) mirror of `docs/*` must be translated to `i18n/{locale}/docusaurus-plugin-content-docs/current`

e.g. translate intro.md to fr
```shell
mkdir -p i18n/fr/docusaurus-plugin-content-docs/current
cp docs/intro.md i18n/fr/docusaurus-plugin-content-docs/current/
```
translate `i18n/fr/docusaurus-plugin-content-docs/current/intro.md` 
```shell
pnpm run build
```


## add phrasea databox api

https://github.com/PaloAltoNetworks/docusaurus-openapi-docs

### generate the schema from phrasea dev container

```shell
cd databox/api
sf app:documentation:dump
```
==> the file is generated to `<phrasea>/doc/Databox/Api/schema.json`, copy/paste it here as `databox_api_schema.xml`

### declare the api, add to navbar
```typescript
// docusaurus.config.ts

const config: Config = {
    ...
    themeConfig:
    {
        ...,
        navbar: {
            ...,
            items: [
                ...,
                // ------ add
                {
                    label: "Databox API",
                    position: "left",
                    to: "/docs/category/databox-api",
                },
                ...,
            ],
        },
    },
    ...
    plugins: [
        [
            "docusaurus-plugin-openapi-docs",
            {
                id: "openapi",
                docsPluginId: "classic",
                config: {
                    petstore: {...} satisfies OpenApiPlugin.Options,
                    // ----------- add
                    databox: {
                        specPath: "databox_api_schema.json",
                        outputDir: "docs/databox_api",
                        sidebarOptions: {
                            groupPathsBy: "tag",
                            categoryLinkSource: "tag",
                        },
                    } satisfies OpenApiPlugin.Options,
                } satisfies Plugin.PluginOptions,
            }
        ],
        ...
    ],
    ...
};
```

### generate the `*.mdx` and the `sidebar.ts` files to `docs/databox_api`
```shell
# "databox" = key in config (one can use "all")
pnpm run gen-api-docs databox
```

### add the sidebar
```typescript
// sidebars.ts

const sidebars: SidebarsConfig = {
    ...,
    openApiSidebar: [
        ...,
        // ------ add
        {
            type: "category",
            label: "Databox API",
            link: {
                type: "generated-index",
                title: "Phrasea Databox API",
                description:
                    "This is the Phrasea Databox API.",
                slug: "/category/databox-api"
            },
            items: require("./docs/databox_api/sidebar.ts")
        },
        ...
    ],
};
```

pnpm i @docusaurus/core@latest @docusaurus/preset-classic@latest @docusaurus/theme-common@latest

pnpm dedupe

pnpm gen-api-docs all

pnpm build

