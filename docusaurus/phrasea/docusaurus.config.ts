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

const siteName = process.env.SITE_NAME || "Phrasea Documentation";

const config: Config = {
    title: siteName,
    tagline: undefined,
    url: process.env.SITE_URL || "https://doc.phrasea.com",
    baseUrl: "/",
    onBrokenLinks: "warn",     // Opts: 'ignore' | 'log' | 'warn' | 'throw'
    onBrokenAnchors: "warn",
    favicon: "img/favicon.ico",
    future: {
        v4: true,
        experimental_faster: true,
    },
    markdown: {
        hooks: {
            onBrokenMarkdownLinks: "warn",
            onBrokenMarkdownImages: "warn",
        },
    },

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
                    routeBasePath: '/', // Serve the docs at the site's root
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
                title: siteName,
                logo: {
                    alt: "Phrasea Logo",
                    src: "img/phrasea.svg",
                },
                hideOnScroll: false,
                items: [
                    {
                        type: "docSidebar",
                        sidebarId: "userdocSidebar",
                        position: "left",
                        label: "User",
                    },
                    {
                        type: "docSidebar",
                        sidebarId: "techdocSidebar",
                        position: "left",
                        label: "Tech",
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
                    {
                        label: 'Phrasea',
                        href: 'https://www.phrasea.com',
                    },
                    {
                        label: 'Github',
                        href: 'https://github.com/alchemy-fr/phrasea',
                    },

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
                    databox: createApiPlugin('databox'),
                    expose: createApiPlugin('expose'),
                    uploader: createApiPlugin('uploader'),
                } satisfies Plugin.PluginOptions,
            }
        ],
        [
            require.resolve("@cmfcmf/docusaurus-search-local"),
            {
                language: ['en', 'fr'],
                indexDocs: true,
                indexBlog: false,
                indexPages: false
            },
        ],
    ],

    themes: ["docusaurus-theme-openapi-docs"],
};

export default async function createConfig() {
    return config;
}

function createApiPlugin(appName: string): OpenApiPlugin.Options {
    return {
        specPath: `docs/_${appName}/_schema.json`,
        outputDir: `docs/${appName}_api`,
        sidebarOptions: {
            groupPathsBy: "tag",
            categoryLinkSource: "tag",
        },
    } satisfies OpenApiPlugin.Options;
}
