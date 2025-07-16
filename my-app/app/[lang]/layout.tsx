import '@/app/global.css';
import { RootProvider } from 'fumadocs-ui/provider';
import { Inter } from 'next/font/google';
import type { ReactNode } from 'react';
import type { Translations } from 'fumadocs-ui/i18n';

// translations
const fr: Partial<Translations> = {
    search: 'Recherche',
};
// available languages that will be displayed on UI
// make sure `locale` is consistent with your i18n config
const locales = [
    {
        name: 'English',
        locale: 'en',
    },
    {
        name: 'Français',
        locale: 'fr',
    },
];

const inter = Inter({
  subsets: ['latin'],
});

export default async function RootLayout({
    params,
    children
}: {
    params: Promise<{ lang: string }>;
    children: ReactNode;
}) {
    const lang = (await params).lang;
  return (
    <html lang={lang} className={inter.className} suppressHydrationWarning>
      <body className="flex flex-col min-h-screen">
        <RootProvider i18n={{
            locale: lang,
            locales,
            translations: { fr }[lang],
        }}>
            {children}
        </RootProvider>
      </body>
    </html>
  );
}
