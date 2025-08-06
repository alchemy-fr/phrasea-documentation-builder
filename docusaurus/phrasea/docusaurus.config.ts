// @ts-check
// Note: type annotations allow type checking and IDEs autocompletion

import type * as Preset from "@docusaurus/preset-classic";
import type {Config} from "@docusaurus/types";
import type * as Plugin from "@docusaurus/types/src/plugin";
import type * as OpenApiPlugin from "docusaurus-plugin-openapi-docs";

const config: Config = {
    title: "My Site",
    tagline: "Dinosaurs are cool",
    url: "https://your-docusaurus-test-site.com",
    baseUrl: "/",
   // onBrokenLinks: "throw",
    onBrokenLinks: "ignore",
    onBrokenAnchors: "ignore",
    // onBrokenMarkdownLinks: "warn",
    onBrokenMarkdownLinks: "ignore",
    favicon: "img/favicon.ico",

    // GitHub pages deployment config.
    // If you aren't using GitHub pages, you don't need these.
    organizationName: "facebook", // Usually your GitHub org/user name.
    projectName: "docusaurus", // Usually your repo name.

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
                    // Please change this to your repo.
                    // Remove this to remove the "edit this page" links.
                    docItemComponent: "@theme/ApiItem", // Derived from docusaurus-theme-openapi
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
                },
            },
            navbar: {
                title: "My Site",
                logo: {
                    alt: "My Site Logo",
                    src: "img/logo.svg",
                },
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
                        type: 'localeDropdown',
                        position: 'left',
                    },

                ],
            },
            footer: {
                style: "dark",
                links: [
                    {
                        title: "Docs",
                        items: [
                            {
                                label: "Tutorial",
                                to: "/docs/intro",
                            },
                        ],
                    },
                ],
                copyright: `Copyright © ${new Date().getFullYear()} My Project, Inc. Built with Docusaurus.`,
            },
            prism: {
                additionalLanguages: [
                    "ruby",
                    "csharp",
                    "php",
                    "java",
                    "powershell",
                    "json",
                    "bash",
                    "dart",
                    "objectivec",
                    "r",
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
                        downloadUrl:
                            "https://raw.githubusercontent.com/PaloAltoNetworks/docusaurus-template-openapi-docs/main/examples/petstore.yaml",
                        sidebarOptions: {
                            groupPathsBy: "tag",
                            categoryLinkSource: "tag",
                        },
                    } satisfies OpenApiPlugin.Options,
                } satisfies Plugin.PluginOptions,
            }
        ],
        // [
        //     require.resolve('docusaurus-lunr-search'),
        //     {
        //         languages: ['en', 'fr'] // language codes
        //     }
        // ]
    ],

    themes: ["docusaurus-theme-openapi-docs"],
};

export default async function createConfig() {
    return config;
}
