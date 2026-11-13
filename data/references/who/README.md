# WHO growth tables

The tables in this folder are the LMS parameters of two World Health
Organization growth references, converted to the module's table format with
`../convert.php`. The numbers are unchanged; only the layout differs.

| Files | Source | Licence |
|---|---|---|
| `who-wfa`, `who-lhfa`, `who-bfa`, `who-hcfa`, `who-acfa`, `who-ssfa`, `who-tsfa`, `who-wfl`, `who-wfh` | WHO Child Growth Standards (2006), birth to 5 years: `data-raw/growthstandards/*.txt` of the WHO `anthro` R package, [github.com/WorldHealthOrganization/anthro](https://github.com/WorldHealthOrganization/anthro) at commit `b776d8a12b1c97369c748b561159fd2ec4f4db58` | GPL-3 |
| `who2007-wfa`, `who2007-hfa`, `who2007-bfa` | WHO Growth Reference for 5 to 19 years (2007): `data-raw/growthstandards/*.txt` of the WHO `anthroplus` R package, [github.com/WorldHealthOrganization/anthroplus](https://github.com/WorldHealthOrganization/anthroplus) at commit `7cfcdb39026e9a55de55732bc3cf14c82261bcf7` | GPL (>= 3) |

Copyright of the tables: World Health Organization. Both packages are
distributed under the GNU General Public License version 3 (anthroplus: version
3 or later); `LICENSE` in this folder is that licence. The tables in this folder
stay under it, and so do the copies of them that the module ships as scripts
(`js/growth/*.js`, written from these files by `tools/build_growth_rows.php`
so that a browser can cache the rows; each names its source and licence in its
first lines). The rest of the module is under the MIT licence in the
repository root. `data/NOTICE.md` lists every part of the module that is not
under the MIT licence and what its owner still has to confirm.

## What was converted

| Source file | Table | Rows per sex |
|---|---|---|
| `weianthro.txt` | `who-wfa.json`, weight-for-age | days 0 to 1826 |
| `lenanthro.txt` | `who-lhfa.json`, length/height-for-age | days 0 to 1826 |
| `bmianthro.txt` | `who-bfa.json`, BMI-for-age | days 0 to 1826 |
| `hcanthro.txt` | `who-hcfa.json`, head circumference-for-age | days 0 to 1826 |
| `acanthro.txt` | `who-acfa.json`, arm circumference-for-age | days 91 to 1826 |
| `ssanthro.txt` | `who-ssfa.json`, subscapular skinfold-for-age | days 91 to 1826 |
| `tsanthro.txt` | `who-tsfa.json`, triceps skinfold-for-age | days 91 to 1826 |
| `wflanthro.txt` | `who-wfl.json`, weight-for-length | 45.0 to 110.0 cm, every 0.1 cm |
| `wfhanthro.txt` | `who-wfh.json`, weight-for-height | 65.0 to 120.0 cm, every 0.1 cm |
| `wfawho2007.txt` | `who2007-wfa.json`, weight-for-age | months 60 to 121 |
| `hfawho2007.txt` | `who2007-hfa.json`, height-for-age | months 60 to 229 |
| `bfawho2007.txt` | `who2007-bfa.json`, BMI-for-age | months 60 to 229 |

The age tables were converted with `--x age`, the length and height tables
with `--x length` or `--x height` and `--scale 10`. The table's `sex` column
codes 1 for male and 2 for female. `tests/who_golden.json` holds z-scores
computed by WHO's own R code from these packages (`tools/who_golden.R` in
the source repository writes it; the release package leaves `tools/` out),
and the module's tests check that both of its runtimes give the same two
decimals.

## How the module uses them

The method follows WHO's own code, with z = ((y/M)^L - 1)/(S·L). For weight, BMI,
arm circumference and skinfolds, a z beyond ±3 is measured in units of the
distance between 2 and 3 SD at that point ("restricted application"). Length,
height and head circumference use the plain formula. The z-score is rounded to
two decimals.

Two adjustments WHO's software makes:

- **Lying or standing.** WHO's software adds 0.7 cm to a height measured
  standing before age 2 and takes 0.7 cm off a length measured lying from age
  2. The module does the same when the rule names the field that says how the
  child was measured (`"measured"`): `who-lhfa` corrects the measurement, and
  `who-wfl` and `who-wfh` correct the length or height in `by` (the `position`
  of each entry in `../index.json`). The age that decides is the day whose row
  the table reads, so 730.5 days counts as day 731. Without `"measured"`, or
  when its field is blank or holds another code, the length or height is used
  as entered: record it the way the table expects, lying (length) for
  `who-wfl` and before 731 days, standing (height) for `who-wfh` and from 731
  days.
- **Oedema.** WHO's software gives no weight-based z-score for a child with
  oedema, and the module does not apply that. Leave such records out with
  `when`, for example `"when":"[oedema]<>'1'"`.
