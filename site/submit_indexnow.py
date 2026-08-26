#!/usr/bin/env python3
"""Tell Bing, Yandex, Seznam and Naver that the site's pages exist.

Google does not participate in IndexNow — for Google the only equivalent is
Search Console (verify the site, submit sitemap.xml, then Request Indexing on
the URL Inspection tool). This covers everyone else, and takes one call.

Run it after a deploy:  python site/submit_indexnow.py
Add --dry-run to print the payload without sending it.
"""

import json
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import pages as cfg  # noqa: E402

ENDPOINT = "https://api.indexnow.org/indexnow"


def urls() -> list[str]:
    """Every page in the sitemap, which is the same list the site publishes."""
    return [
        f"{cfg.BASE_URL}/" if p.slug == "index" else f"{cfg.BASE_URL}/{p.slug}/"
        for p in cfg.PAGES
    ]


def main() -> None:
    if not cfg.INDEXNOW_KEY:
        raise SystemExit("INDEXNOW_KEY is empty in site/pages.py — nothing to submit")

    host = urllib.parse.urlparse(cfg.BASE_URL).netloc
    payload = {
        "host": host,
        "key": cfg.INDEXNOW_KEY,
        "keyLocation": f"{cfg.BASE_URL}/{cfg.INDEXNOW_KEY}.txt",
        "urlList": urls(),
    }

    print(f"host        {host}")
    print(f"keyLocation {payload['keyLocation']}")
    print(f"urls        {len(payload['urlList'])}")
    for u in payload["urlList"]:
        print("   ", u)

    if "--dry-run" in sys.argv:
        print("\n--dry-run: not submitted")
        return

    # The key file must be live before submitting, or the endpoint rejects the
    # whole batch as unverified.
    try:
        with urllib.request.urlopen(payload["keyLocation"], timeout=15) as r:
            served = r.read().decode("utf-8").strip()
    except urllib.error.URLError as e:
        raise SystemExit(f"key file not reachable ({e}) — deploy the site first")
    if served != cfg.INDEXNOW_KEY:
        raise SystemExit(f"key file serves {served!r}, expected {cfg.INDEXNOW_KEY!r}")
    print("\nkey file verified")

    req = urllib.request.Request(
        ENDPOINT,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json; charset=utf-8"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            code, body = r.status, r.read().decode("utf-8", "replace").strip()
    except urllib.error.HTTPError as e:
        code, body = e.code, e.read().decode("utf-8", "replace").strip()

    # 200 accepted, 202 accepted but key still pending validation.
    print(f"IndexNow -> HTTP {code} {body or '(empty body, which is normal)'}")
    if code not in (200, 202):
        raise SystemExit(1)


if __name__ == "__main__":
    main()
