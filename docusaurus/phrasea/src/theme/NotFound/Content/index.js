import React, {useEffect, useMemo} from "react";
import Translate from "@docusaurus/Translate";
import Heading from "@theme/Heading";
import Layout from "@theme/Layout";
import {useLocation} from "@docusaurus/router";
import ExecutionEnvironment from "@docusaurus/ExecutionEnvironment";

const STATIC_FILE_RE = /\.[a-z0-9]+$/i;

function shouldSkipRedirect(pathname) {
  if (!pathname.startsWith("/fr")) {
    return true;
  }

  // Do not rewrite static asset-like paths if they somehow land on NotFound.
  return STATIC_FILE_RE.test(pathname);
}

function getEnglishFallback(pathname) {
  if (pathname === "/fr" || pathname === "/fr/") {
    return "/";
  }

  if (!pathname.startsWith("/fr/")) {
    return null;
  }

  const candidate = pathname.replace(/^\/fr/, "");
  return candidate || "/";
}

export default function NotFoundContent() {
  const {pathname, search, hash} = useLocation();

  const englishFallback = useMemo(() => {
    if (shouldSkipRedirect(pathname)) {
      return null;
    }

    return getEnglishFallback(pathname);
  }, [pathname]);

  useEffect(() => {
    if (!ExecutionEnvironment.canUseDOM || !englishFallback) {
      return;
    }

    const target = `${englishFallback}${search}${hash}`;
    window.location.replace(target);
  }, [englishFallback, hash, search]);

  if (englishFallback) {
    return (
      <Layout
        title="Redirection"
        description="Redirection vers la version anglaise"
      >
        <main className="container margin-vert--xl">
          <Heading as="h1" className="hero__title">
            <Translate id="theme.NotFound.frFallbackTitle" description="Title shown while redirecting to EN page">
              Redirection vers la version anglaise...
            </Translate>
          </Heading>
        </main>
      </Layout>
    );
  }

  return (
    <Layout title="Page Not Found" description="Page Not Found">
      <main className="container margin-vert--xl">
        <div className="row">
          <div className="col col--6 col--offset-3">
            <Heading as="h1" className="hero__title">
              <Translate id="theme.NotFound.title" description="The title of the 404 page">
                Page Not Found
              </Translate>
            </Heading>
            <p>
              <Translate id="theme.NotFound.p1" description="The first paragraph of the 404 page">
                We could not find what you were looking for.
              </Translate>
            </p>
            <p>
              <Translate id="theme.NotFound.p2" description="The 2nd paragraph of the 404 page">
                Please contact the owner of the site that linked you to the original URL and let them know their link is broken.
              </Translate>
            </p>
          </div>
        </div>
      </main>
    </Layout>
  );
}
