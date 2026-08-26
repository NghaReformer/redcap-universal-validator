"""Site configuration for the Universal Field Validator documentation site.

Everything the generator needs lives here. To add a page, append a Page(...)
entry and drop the matching fragment in site/content/<slug>.html — the nav,
sitemap, JSON-LD and cross-links all follow automatically.
"""

from dataclasses import dataclass, field

# --- global ----------------------------------------------------------------

BASE_URL = "https://nghareformer.github.io/redcap-universal-validator"
REPO_URL = "https://github.com/NghaReformer/redcap-universal-validator"
RAW_DOCS = f"{REPO_URL}/blob/main/docs"

SITE_NAME = "Universal Field Validator"
TAGLINE = "Live, as-you-type field validation for REDCap"
AUTHOR = "Bamenda Center for Health Promotion and Research (CHPR)"
LICENSE_URL = f"{REPO_URL}/blob/main/LICENSE"

# --- search-engine plumbing ------------------------------------------------

# Google Search Console meta-tag verification. Paste the `content` value from
# the tag GSC gives you (Settings -> Ownership verification -> HTML tag).
# Leave empty and no tag is emitted. The HTML-file method works too: drop
# Google's google<hash>.html into site/static/ instead.
GOOGLE_SITE_VERIFICATION = ""

# IndexNow (Bing, Yandex, Seznam, Naver — not Google). The key is published at
# /<key>.txt so those engines can confirm we own the site; that is the design,
# it is not a secret. Submit with: python site/submit_indexnow.py
INDEXNOW_KEY = "67913efd98c5b057d096b9d82c92a6b4"


@dataclass
class Page:
    slug: str            # output path without .html; "index" is the site root
    title: str           # <title>; put the distinctive term FIRST
    heading: str         # <h1>
    description: str     # <meta name="description">, 140-160 chars is the sweet spot
    nav_label: str       # short label for the site nav
    keywords: tuple = ()
    in_nav: bool = True
    priority: str = "0.8"
    # Structured-data type: TechArticle for docs pages, SoftwareApplication for home
    schema_type: str = "TechArticle"
    related: tuple = ()  # slugs cross-linked in the "Related" strip


# --- pages -----------------------------------------------------------------
# Ordered as they appear in the nav.

TAG_SLUGS = ("uvalidate", "uvassert", "uvrequired", "uvunique", "uvchoices")

PAGES = [
    Page(
        slug="index",
        title=f"{SITE_NAME} — REDCap external module for check-character IDs, cross-field rules and uniqueness",
        heading="Universal Field Validator",
        description=(
            "A REDCap external module that validates fields as they are typed: "
            "check-character IDs, regex formats, cross-field constraints, conditional "
            "required fields, cross-record uniqueness and dynamic choice filtering."
        ),
        nav_label="Overview",
        keywords=("REDCap", "external module", "field validation", "check digit",
                  "ISO 7064", "action tag", "clinical data management"),
        priority="1.0",
        schema_type="SoftwareApplication",
        related=TAG_SLUGS,
    ),
    Page(
        slug="uvalidate",
        title="@UVALIDATE — check-character and regex field validation in REDCap",
        heading="@UVALIDATE",
        description=(
            "@UVALIDATE is a REDCap action tag that validates a field against a check "
            "character (ISO/IEC 7064, Damm, Verhoeff, Luhn) or a regex format pattern, "
            "live as the value is typed."
        ),
        nav_label="@UVALIDATE",
        keywords=("@UVALIDATE", "REDCap action tag", "check character", "check digit",
                  "ISO/IEC 7064", "Damm", "Verhoeff", "Luhn", "regex validation"),
        related=("uvassert", "uvunique", "index"),
    ),
    Page(
        slug="uvassert",
        title="@UVASSERT — cross-field constraint validation in REDCap",
        heading="@UVASSERT",
        description=(
            "@UVASSERT is a REDCap action tag that blocks a field unless a condition "
            "across other fields holds — date ordering, dose ceilings, confirm-a-value "
            "pairs — checked live and enforced at save."
        ),
        nav_label="@UVASSERT",
        keywords=("@UVASSERT", "REDCap action tag", "cross-field validation",
                  "constraint", "conditional logic", "confirm value"),
        related=("uvrequired", "uvalidate", "index"),
    ),
    Page(
        slug="uvrequired",
        title="@UVREQUIRED — conditional required fields in REDCap",
        heading="@UVREQUIRED",
        description=(
            "@UVREQUIRED is a REDCap action tag that makes a field required only while "
            "a condition is true, and can actually block the save instead of only "
            "warning like REDCap's native required flag."
        ),
        nav_label="@UVREQUIRED",
        keywords=("@UVREQUIRED", "REDCap action tag", "conditional required",
                  "required field", "block save"),
        related=("uvassert", "uvchoices", "index"),
    ),
    Page(
        slug="uvunique",
        title="@UVUNIQUE — enforce unique field values across REDCap records",
        heading="@UVUNIQUE",
        description=(
            "@UVUNIQUE is a REDCap action tag that checks a value against every other "
            "record as it is typed, with project, DAG or event scope and optional "
            "composite keys. REDCap has no native field-level uniqueness."
        ),
        nav_label="@UVUNIQUE",
        keywords=("@UVUNIQUE", "REDCap action tag", "unique field", "duplicate check",
                  "cross-record uniqueness", "data access group"),
        related=("uvalidate", "uvassert", "index"),
    ),
    Page(
        slug="uvchoices",
        title="@UVCHOICES — dynamic choice filtering for REDCap radio, dropdown and checkbox fields",
        heading="@UVCHOICES",
        description=(
            "@UVCHOICES is a REDCap action tag that shows or hides individual choices of "
            "a radio, dropdown or checkbox field based on the live values of other "
            "fields — cascading country to region to site in a single field."
        ),
        nav_label="@UVCHOICES",
        keywords=("@UVCHOICES", "REDCap action tag", "dynamic choices", "cascading dropdown",
                  "HIDECHOICE", "choice filtering"),
        related=("uvrequired", "uvunique", "index"),
    ),
    Page(
        slug="install",
        title="Install the Universal Field Validator REDCap external module",
        heading="Installing the module",
        description=(
            "How to install and enable the Universal Field Validator external module on a "
            "REDCap instance, and where the per-project rules are configured."
        ),
        nav_label="Install",
        keywords=("REDCap module install", "external module", "REDCap administrator"),
        priority="0.6",
        related=("index",) + TAG_SLUGS[:2],
    ),
]

PAGES_BY_SLUG = {p.slug: p for p in PAGES}
