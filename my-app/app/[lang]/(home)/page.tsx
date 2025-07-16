import Link from 'next/link';
import { DynamicLink } from 'fumadocs-core/dynamic-link';
import * as console from 'node:console';
import type {ReactNode} from 'react';

export default async function HomePage({
    params,
    // searchParams,
}: {
    params: Promise<{lang: string}>;
    // searchParams: Promise<any>;
}) {
    const lang = (await params).lang;
    console.log('Rendering home page in language:', lang);

    return (
        <main className="flex flex-1 flex-col justify-center text-center">
            <h1 className="mb-4 text-2xl font-bold">Hello World lang={lang}</h1>
            <p className="text-fd-muted-foreground">
                You can open{' '}
                <DynamicLink
                    href="/[lang]/docs"
                    className="text-fd-foreground font-semibold underline"
                >
                    /docs
                </DynamicLink>{' '}
                and see the documentation.
            </p>
        </main>
    );
}
