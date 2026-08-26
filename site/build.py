#!/usr/bin/env python3
"""Generate the static documentation site into site/_site/.

Standard library only — no pip install, no Jekyll. Run it locally with
`python site/build.py` and open site/_site/index.html, or let the Pages
workflow run it. Page metadata lives in site/pages.py; the prose for each
page is the matching fragment in site/content/<slug>.html.
"""

import html
import json
import shutil
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import pages as cfg  # noqa: E402

OUT = HERE / "_site"
CONTENT = HERE / "content"
ASSETS = HERE / "assets"


def canonical(slug: str) -> str:
    return f"{cfg.BASE_URL}/" if slug == "index" else f"{cfg.BASE_URL}/{slug}/"


def out_path(slug: str) -> Path:
    return OUT / "index.html" if slug == "index" else OUT / slug / "index.html"


def asset_prefix(slug: str) -> str:
    """Relative path back to the site root from this page's directory."""
    return "" if slug == "index" else "../"


def nav_html(current: str, prefix: str) -> str:
    items = []
    for p in cfg.PAGES:
        if not p.in_nav:
            continue
        href = f"{prefix}" if p.slug == "index" else f"{prefix}{p.slug}/"
        cls = ' class="here" aria-current="page"' if p.slug == current else ""
        items.append(f'<a href="{href or "./"}"{cls}>{html.escape(p.nav_label)}</a>')
    return "\n      ".join(items)


def related_html(page, prefix: str) -> str:
    if not page.related:
        return ""
    cards = []
    for slug in page.related:
        rel = cfg.PAGES_BY_SLUG.get(slug)
        if rel is None or rel.slug == page.slug:
            continue
        href = prefix if rel.slug == "index" else f"{prefix}{rel.slug}/"
        cards.append(
            f'<a class="card" href="{href or "./"}">'
            f"<strong>{html.escape(rel.nav_label)}</strong>"
            f"<span>{html.escape(rel.description[:105].rsplit(' ', 1)[0])}…</span></a>"
        )
    if not cards:
        return ""
    return '<section class="related"><h2>Related</h2><div class="cards">' + "".join(cards) + "</div></section>"


def jsonld(page) -> str:
    url = canonical(page.slug)
    if page.schema_type == "SoftwareApplication":
        data = {
            "@context": "https://schema.org",
            "@type": "SoftwareApplication",
            "name": cfg.SITE_NAME,
            "applicationCategory": "HealthApplication",
            "applicationSubCategory": "REDCap external module",
            "operatingSystem": "REDCap 13.7.0 or later, PHP 7.4 or later",
            "url": url,
            "codeRepository": cfg.REPO_URL,
            "description": page.description,
            "keywords": ", ".join(page.keywords),
            "license": cfg.LICENSE_URL,
            "author": {"@type": "Organization", "name": cfg.AUTHOR},
            "offers": {"@type": "Offer", "price": "0", "priceCurrency": "USD"},
        }
    else:
        data = {
            "@context": "https://schema.org",
            "@type": "TechArticle",
            "headline": page.heading,
            "name": page.title,
            "description": page.description,
            "url": url,
            "keywords": ", ".join(page.keywords),
            "author": {"@type": "Organization", "name": cfg.AUTHOR},
            "isPartOf": {"@type": "WebSite", "name": cfg.SITE_NAME, "url": cfg.BASE_URL + "/"},
            "about": {"@type": "SoftwareApplication", "name": cfg.SITE_NAME,
                      "codeRepository": cfg.REPO_URL},
        }
    return json.dumps(data, indent=2, ensure_ascii=False)


SHELL = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<meta name="description" content="{description}">
{keywords_meta}<link rel="canonical" href="{canonical}">
<meta property="og:type" content="{og_type}">
<meta property="og:title" content="{title}">
<meta property="og:description" content="{description}">
<meta property="og:url" content="{canonical}">
<meta property="og:site_name" content="{site_name}">
<meta name="twitter:card" content="summary">
<link rel="stylesheet" href="{prefix}assets/style.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='14' font-size='14'>&#9989;</text></svg>">
<script type="application/ld+json">
{jsonld}
</script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site">
  <div class="wrap">
    <a class="brand" href="{prefix}">{site_name}</a>
    <nav aria-label="Main">
      {nav}
    </nav>
  </div>
</header>
<main id="main" class="wrap">
  <h1>{heading}</h1>
  <p class="lede">{description}</p>
{body}
{related}
</main>
<footer class="site">
  <div class="wrap">
    <p>{site_name} — a REDCap external module by {author}. Released under the
    <a href="{license_url}">MIT licence</a>.</p>
    <p><a href="{repo_url}">Source on GitHub</a> ·
       <a href="{repo_url}/releases">Releases</a> ·
       <a href="{docs}/USER_GUIDE.md">User guide</a> ·
       <a href="{docs}/action_tag_validation_examples.md">Action-tag examples</a> ·
       <a href="{docs}/TESTING.md">Testing</a></p>
    <p class="fine">REDCap is a product of Vanderbilt University. This module is an
    independent community project and is not affiliated with or endorsed by Vanderbilt.</p>
  </div>
</footer>
</body>
</html>
"""


def render(page) -> str:
    fragment = CONTENT / f"{page.slug}.html"
    if not fragment.exists():
        raise SystemExit(f"missing content fragment: {fragment}")
    prefix = asset_prefix(page.slug)
    kw = page.keywords
    return SHELL.format(
        title=html.escape(page.title),
        description=html.escape(page.description),
        keywords_meta=(f'<meta name="keywords" content="{html.escape(", ".join(kw))}">\n' if kw else ""),
        canonical=canonical(page.slug),
        og_type="website" if page.slug == "index" else "article",
        site_name=html.escape(cfg.SITE_NAME),
        prefix=prefix or "./",
        jsonld=jsonld(page),
        nav=nav_html(page.slug, prefix),
        heading=html.escape(page.heading),
        body=fragment.read_text(encoding="utf-8").strip(),
        related=related_html(page, prefix),
        author=html.escape(cfg.AUTHOR),
        license_url=cfg.LICENSE_URL,
        repo_url=cfg.REPO_URL,
        docs=cfg.RAW_DOCS,
    )


def sitemap() -> str:
    urls = "".join(
        f"  <url><loc>{canonical(p.slug)}</loc>"
        f"<changefreq>monthly</changefreq>"
        f"<priority>{p.priority}</priority></url>\n"
        for p in cfg.PAGES
    )
    return ('<?xml version="1.0" encoding="UTF-8"?>\n'
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
            f"{urls}</urlset>\n")


def main() -> None:
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir(parents=True)

    for page in cfg.PAGES:
        target = out_path(page.slug)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(render(page), encoding="utf-8")
        print(f"  {target.relative_to(OUT)}")

    shutil.copytree(ASSETS, OUT / "assets")
    (OUT / "sitemap.xml").write_text(sitemap(), encoding="utf-8")
    (OUT / "robots.txt").write_text(
        f"User-agent: *\nAllow: /\n\nSitemap: {cfg.BASE_URL}/sitemap.xml\n", encoding="utf-8")
    (OUT / ".nojekyll").write_text("", encoding="utf-8")
    print(f"\n{len(cfg.PAGES)} pages -> {OUT}")


if __name__ == "__main__":
    main()
