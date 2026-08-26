#!/usr/bin/env python3
"""Check the generated site before it ships.

Run after site/build.py. Fails on a dead internal link, malformed JSON-LD,
a missing SEO tag, a broken sitemap, or an action-tag page whose <title> has
lost its tag — the one thing the whole site exists to rank for.
"""

import json
import re
import sys
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import pages as cfg  # noqa: E402

OUT = HERE / "_site"
SM_NS = "{http://www.sitemaps.org/schemas/sitemap/0.9}"
errors = []


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.hrefs = []

    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == "a" and "href" in d:
            self.hrefs.append(d["href"])
        if tag == "link" and d.get("rel") == "stylesheet" and "href" in d:
            self.hrefs.append(d["href"])


def static_names() -> set:
    """Files copied verbatim from site/static/ (search-engine ownership files).

    They are not pages and must not be held to the page metadata rules.
    """
    d = HERE / "static"
    if not d.is_dir():
        return set()
    return {f.name for f in d.iterdir() if f.is_file() and f.name != ".gitkeep"}


def check_pages() -> None:
    skip = static_names()
    for f in sorted(OUT.rglob("*.html")):
        rel = f.relative_to(OUT)
        if rel.as_posix() in skip:
            continue
        txt = f.read_text(encoding="utf-8")

        for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', txt, re.S):
            try:
                json.loads(block)
            except json.JSONDecodeError as e:
                errors.append(f"{rel}: malformed JSON-LD: {e}")

        for needed in ("<title>", 'name="description"', 'rel="canonical"', 'property="og:title"'):
            if needed not in txt:
                errors.append(f"{rel}: missing {needed}")

        parser = Links()
        parser.feed(txt)
        for href in parser.hrefs:
            if href.startswith(("http://", "https://", "#", "data:", "mailto:")):
                continue
            target = (f.parent / href).resolve()
            if target.is_dir() or href.endswith("/"):
                target = target / "index.html"
            if not target.exists():
                errors.append(f"{rel}: dead link -> {href}")


def check_sitemap() -> None:
    try:
        locs = {e.text for e in ET.parse(OUT / "sitemap.xml").getroot().iter(SM_NS + "loc")}
    except (ET.ParseError, OSError) as e:
        errors.append(f"sitemap.xml: {e}")
        return
    for p in cfg.PAGES:
        want = f"{cfg.BASE_URL}/" if p.slug == "index" else f"{cfg.BASE_URL}/{p.slug}/"
        if want not in locs:
            errors.append(f"sitemap.xml: missing {want}")


def check_required_files() -> None:
    for req in ("robots.txt", ".nojekyll", "assets/style.css", "index.html"):
        if not (OUT / req).exists():
            errors.append(f"missing {req}")


def check_titles_carry_their_tag() -> None:
    """Every action-tag page must keep its tag in the <title>."""
    for slug in cfg.TAG_SLUGS:
        f = OUT / slug / "index.html"
        if not f.exists():
            errors.append(f"missing page {slug}/")
            continue
        m = re.search(r"<title>(.*?)</title>", f.read_text(encoding="utf-8"), re.S)
        tag = "@" + slug.upper()
        if not m or tag not in m.group(1):
            errors.append(f"{slug}: <title> no longer contains {tag}")


def main() -> None:
    if not OUT.exists():
        raise SystemExit("site/_site does not exist — run `python site/build.py` first")

    check_pages()
    check_sitemap()
    check_required_files()
    check_titles_carry_their_tag()

    if errors:
        print(f"FAIL — {len(errors)} problem(s):")
        for e in errors:
            print("  -", e)
        raise SystemExit(1)

    n = len(list(OUT.rglob("*.html")))
    print(f"OK — {n} pages: links resolve, JSON-LD parses, sitemap complete, titles intact")


if __name__ == "__main__":
    main()
