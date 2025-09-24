// @ts-check
// Note: type annotations allow type checking and IDEs autocompletion

import type * as Preset from "@docusaurus/preset-classic";
import type {Config} from "@docusaurus/types";
import type * as Plugin from "@docusaurus/types/src/plugin";
import type * as OpenApiPlugin from "docusaurus-plugin-openapi-docs";
// @ts-ignore
import * as version from "./version.json";
// @ts-ignore
import versions from './versions.json';

const config: Config = {
    title: "Phrasea documentation",
    tagline: "refname: " + version.refname + "  ;  reftype: " + version.reftype + "  ;  datetime: " + version.datetime,
    url: "https://phrasea.documentation.com",
    baseUrl: "/",
   // onBrokenLinks: "throw",
    onBrokenLinks: "ignore",
    onBrokenAnchors: "ignore",
    // onBrokenMarkdownLinks: "warn",
    onBrokenMarkdownLinks: "ignore",
    favicon: "img/favicon.ico",

    // GitHub pages deployment config.
    // If you aren't using GitHub pages, you don't need these.
    organizationName: "alchemy-fr", // Usually your GitHub org/username.
    projectName: "phrasea-documentation-builder", // Usually your repo name.

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

    presets: [
        [
            "classic",
            {
                docs: {
                    sidebarPath: require.resolve("./sidebars.ts"),
                    docItemComponent: "@theme/ApiItem", // Derived from docusaurus-theme-openapi
                    disableVersioning: false,
                    // DO NOT CHANGE THE FOLLOWING LINE, IT IS MANAGED BY THE build SCRIPT (set to false before build)
                    includeCurrentVersion: true,
                },
                blog: false,
                theme: {
                    customCss: require.resolve("./src/css/custom.css"),
                },
            } satisfies Preset.Options,
        ],
    ],

    themeConfig:
        {
            docs: {
                sidebar: {
                    hideable: true,
                    autoCollapseCategories: false,
                },
            },
            navbar: {
                title: "Phrasea documentation",
                logo: {
                    alt: "Phrasea Logo",
                    src: "img/phrasea.svg",
                },
                hideOnScroll: false,
                items: [
                    {
                        type: "doc",
                        docId: "intro",
                        position: "left",
                        label: "Tutorial",
                    },
                    {
                        label: "Databox API",
                        position: "left",
                        to: "/docs/category/databox-api",
                    },
                    {
                        type: "docsVersionDropdown",
                        position: "right",
                        dropdownActiveClassDisabled: true,
                    },
                    {
                        type: 'localeDropdown',
                        position: 'right',
                    },

                ],
            },
            footer: {
                style: "dark",
                links: [
                    // {
                    //     title: "Docs",
                    //     items: [
                    //         {
                    //             label: "Tutorial",
                    //             to: "/docs/intro",
                    //         },
                    //     ],
                    // },
                ],
                copyright: `Copyright © ${new Date().getFullYear()} Alchemy, Inc. Built with Docusaurus.`,
            },
            prism: {
                additionalLanguages: [
                    // "ruby",
                    // "csharp",
                    "php",
                    // "java",
                    // "powershell",
                    "json",
                    "bash",
                    // "dart",
                    // "objectivec",
                    // "r",
                ],
            },
            languageTabs: [
                {
                    highlight: "bash",
                    language: "curl",
                    logoClass: "curl",
                },
                {
                    highlight: "javascript",
                    language: "nodejs",
                    logoClass: "nodejs",
                },
                {
                    highlight: "php",
                    language: "php",
                    logoClass: "php",
                },
                {
                    highlight: "javascript",
                    language: "javascript",
                    logoClass: "javascript",
                },
            ],
        } satisfies Preset.ThemeConfig,

    plugins: [
        [
            "docusaurus-plugin-openapi-docs",
            {
                id: "openapi",
                docsPluginId: "classic",
                config: {
                    databox: {
                        specPath: "databox_api_schema.json",
                        outputDir: "docs/databox_api",
                        // downloadUrl: "https://raw.githubusercontent.com/PaloAltoNetworks/docusaurus-template-openapi-docs/main/examples/petstore.yaml",
                        sidebarOptions: {
                            groupPathsBy: "tag",
                            categoryLinkSource: "tag",
                        },
                    } satisfies OpenApiPlugin.Options,
                } satisfies Plugin.PluginOptions,
            }
        ],
        [
            require.resolve('docusaurus-lunr-search'),
            {
                languages: ['en', 'fr']
            }
        ]
    ],

    themes: ["docusaurus-theme-openapi-docs"],
};

export default async function createConfig() {
    return config;
}
