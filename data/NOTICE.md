# Notice: parts of this module that are not under its MIT licence

The module's own code is under the MIT licence in `LICENSE` at the module
root. This file lists what the module ships that comes from somewhere else,
where each part sits, and the terms its source states. It quotes only what the
sources and the files beside it say. It is not legal advice, and the last
section lists what the owner of the module still has to confirm.

## 1. WHO growth tables

| | WHO Child Growth Standards (2006) | WHO Growth Reference for 5 to 19 years (2007) |
|---|---|---|
| Tables | `who-wfa`, `who-lhfa`, `who-bfa`, `who-hcfa`, `who-acfa`, `who-ssfa`, `who-tsfa`, `who-wfl`, `who-wfh` | `who2007-wfa`, `who2007-hfa`, `who2007-bfa` |
| Taken from | `data-raw/growthstandards/*.txt` of the WHO `anthro` R package, https://github.com/WorldHealthOrganization/anthro, commit `b776d8a12b1c97369c748b561159fd2ec4f4db58` | `data-raw/growthstandards/*.txt` of the WHO `anthroplus` R package, https://github.com/WorldHealthOrganization/anthroplus, commit `7cfcdb39026e9a55de55732bc3cf14c82261bcf7` |
| Licence the package states | GPL-3 | GPL (>= 3) |
| Copyright | World Health Organization | World Health Organization |

Where the numbers sit in this module:

- `data/references/who/*.json`: the LMS parameters, converted to the module's
  table format with `data/references/convert.php`. The numbers are unchanged;
  only the layout differs. `data/references/who/README.md` lists each source
  file and its table.
- `js/growth/*.js`: one script per table, written from the JSON file by
  `tools/build_growth_rows.php` so that a browser can cache the rows. Each is a
  copy of its table and says so, with the source and the licence, in its first
  lines.

These files stay under the GNU General Public License version 3, the text of
which is `data/references/who/LICENSE`. The MIT licence of the module does not
apply to them. `data/references/index.json` records the source, the commit and
the licence of each table under `sources`.

`tests/who_golden.json`, in the source repository, holds z-scores that WHO's
own R code computed from these packages (`tools/who_golden.R` writes it).

## 2. The normal distribution functions

A percentile needs the standard normal distribution function and its inverse.
The module computes them in `GrowthReference::normalCdf()` and
`GrowthReference::normalQuantile()` (`php/GrowthReference.php`) and in
`QRID_growthNormalCdf` and `QRID_growthNormalQuantile` (`js/engine.js`,
`js/engine.min.js`).

The algorithms are published:

- W. J. Cody, "Rational Chebyshev approximations for the error function",
  Mathematics of Computation 23 (1969), 631-637, and the routine ANORM of
  W. J. Cody, "Algorithm 715: SPECFUN", ACM Transactions on Mathematical
  Software 19 (1993), 22-32.
- M. J. Wichura, "Algorithm AS 241: The percentage points of the normal
  distribution", Applied Statistics 37 (1988), 477-484 (routine PPND16).

The module's four functions were written to follow the way R computes
`pnorm()` and `qnorm()`, with the same constants, branches and cut-offs, and
the tests hold them to R's results (`tests/growth_normal_golden.json`, written
by `tools/normal_golden.R`). R is distributed under the GNU General Public
License, version 2 or version 3.

## 3. What the owner still has to confirm

Nobody has confirmed the points below. Until they are settled, treat the
module as MIT code shipped together with GPL-3 data, not as MIT throughout.

1. **GPL-3 tables inside an MIT module.** Whether shipping the WHO tables in
   the same archive as the MIT code is acceptable to the owner and to the
   REDCap External Module Repository, or whether the tables should be
   distributed apart from the module (an administrator can already load
   tables from a folder named in the module's settings).
2. **WHO's own terms.** This notice repeats the licence the two R packages
   state. Whether WHO publishes the growth standards themselves under other or
   further terms was not checked here.
3. **The normal distribution functions.** Whether code written to follow R's
   `pnorm()` and `qnorm()` step for step is a work derived from R, and so
   under R's licence. If it is, the choices are to say so for those four
   functions, or to write them again from the two papers without R's source.
   The terms under which the Royal Statistical Society published Algorithm
   AS 241 were not checked here either.
