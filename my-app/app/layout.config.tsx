import { i18n } from '@/lib/i18n';
import type { BaseLayoutProps } from 'fumadocs-ui/layouts/shared';
import * as console from 'node:console';

/**
 * Shared layout configurations
 *
 * you can customise layouts individually from:
 * Home Layout: app/(home)/layout.tsx
 * Docs Layout: app/docs/layout.tsx
 */
// Make `baseOptions` a function:
export function baseOptions(locale: string): BaseLayoutProps {
    console.log(`baseOptions called with locale: ${locale}`);
    return {
        i18n,
        // different props based on `locale`
        nav: {
            title: (
                <>
                    <svg
                        width="24"
                        height="24"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-label="Logo"
                    >
                        <circle cx={12} cy={12} r={12} fill="currentColor" />
                    </svg>
                    My App
                </>
            ),
        },
        // see https://fumadocs.dev/docs/ui/navigation/links
        links: [],
    };
}



export const nobaseOptions: BaseLayoutProps = {
  nav: {
    title: (
      <>
        <svg
          width="24"
          height="24"
          xmlns="http://www.w3.org/2000/svg"
          aria-label="Logo"
        >
          <circle cx={12} cy={12} r={12} fill="currentColor" />
        </svg>
        My App
      </>
    ),
  },
  // see https://fumadocs.dev/docs/ui/navigation/links
  links: [],
};

