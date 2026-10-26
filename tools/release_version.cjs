#!/usr/bin/env node
/*
 * tools/release_version.cjs - the version a build is released under, read from
 * CHANGELOG.md, and the rules a release tag must meet.
 *
 * External Modules takes a module's version from its folder name, so the git
 * tag, the first version heading of CHANGELOG.md, the zip and the folder an
 * administrator unzips must all carry one number. Up to 2.3.0-rc.3 the tags
 * had a "-rc.N" suffix, the test server's administrator renamed each folder to
 * a plain number by hand (rc.1 to rc.3 became v2.3.0 to v2.3.2), and no tag
 * matched what REDCap showed. A release is therefore tagged vX.Y.Z:
 *
 *   - digits only, no suffix;
 *   - equal to the first version heading of CHANGELOG.md ("## Unreleased" and
 *     other headings that are not versions are skipped);
 *   - above every version heading below it, compared number by number with any
 *     suffix dropped, because REDCap orders folders by number and a lower or
 *     equal one installs as an older build;
 *   - not a number tools/release_reserved_versions.json lists, which a server
 *     already uses for other code.
 *
 * Without --tag (a branch build) the same checks run when the first version
 * heading is a plain number. A heading with a suffix, as the 2.3.0 candidates
 * have, still builds, with a warning on stderr, but cannot be tagged.
 *
 * Usage: node tools/release_version.cjs [--tag vX.Y.Z] [--changelog PATH] [--reserved PATH]
 * Prints the version on stdout. Exits 1, with the reason on stderr, when a
 * rule fails.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const VERSION = /^(\d+)\.(\d+)\.(\d+)(-[0-9A-Za-z.-]+)?$/;
const TAG = /^v(\d+)\.(\d+)\.(\d+)$/;

/** Every "## <version> ..." heading, newest first, as {text, core: [x, y, z], suffix}. */
function versionHeadings(changelog) {
  const out = [];
  for (const line of changelog.split(/\r?\n/)) {
    const m = line.match(/^## (\S+)/);
    if (!m) continue;
    const v = m[1].match(VERSION);
    if (v) out.push({ text: m[1], core: [+v[1], +v[2], +v[3]], suffix: v[4] || '' });
  }
  return out;
}

function compareCore(a, b) {
  for (let i = 0; i < 3; i++) if (a[i] !== b[i]) return a[i] < b[i] ? -1 : 1;
  return 0;
}

/**
 * Check a changelog (and a tag, when one is given) against the rules above.
 * Returns {version, errors, warnings}; the build may go ahead only when errors is empty.
 */
function check(changelog, reservedList, tag) {
  const errors = [], warnings = [];
  const heads = versionHeadings(changelog);
  if (!heads.length) return { version: '', errors: ['CHANGELOG.md has no "## X.Y.Z" heading'], warnings };
  const top = heads[0];

  if (tag !== undefined) {
    if (!TAG.test(tag)) {
      errors.push('release tags are plain vX.Y.Z, with no suffix; "' + tag + '" is not one, and REDCap would '
        + 'show its folder under another number');
    } else if (tag !== 'v' + top.text) {
      errors.push('tag ' + tag + ' and the first CHANGELOG.md version ' + top.text + ' differ');
    }
  }
  if (top.suffix) {
    if (tag === undefined) {
      warnings.push('the first CHANGELOG.md version ' + top.text + ' has a suffix: it builds, but it cannot be '
        + 'tagged; give the next build a plain X.Y.Z');
    }
    return { version: top.text, errors, warnings };
  }

  const below = heads.slice(1).find((h) => compareCore(top.core, h.core) <= 0);
  if (below) {
    errors.push(top.text + ' is not above ' + below.text + ', an earlier CHANGELOG.md version; REDCap orders module '
      + 'folders by number, so this build would install as an older version');
  }
  const taken = (reservedList || []).find((r) => r.version === top.text);
  if (taken) {
    errors.push(top.text + ' is taken: the folder universal_validator_v' + top.text + ' on ' + taken.server
      + ' holds ' + taken.holds + ' (tools/release_reserved_versions.json); give this build a higher number');
  }
  return { version: top.text, errors, warnings };
}

if (require.main === module) {
  const args = process.argv.slice(2);
  const opt = (name) => { const i = args.indexOf(name); return i >= 0 ? args[i + 1] : undefined; };
  const changelog = fs.readFileSync(opt('--changelog') || path.join(root, 'CHANGELOG.md'), 'utf8');
  const reserved = JSON.parse(fs.readFileSync(opt('--reserved') || path.join(__dirname, 'release_reserved_versions.json'), 'utf8')).reserved;
  const tagAt = args.indexOf('--tag');
  const r = check(changelog, reserved, tagAt >= 0 ? (args[tagAt + 1] || '') : undefined);
  for (const w of r.warnings) console.error('warning: ' + w);
  for (const e of r.errors) console.error('error: ' + e);
  if (r.errors.length) process.exit(1);
  console.log(r.version);
}

module.exports = { versionHeadings, check };
