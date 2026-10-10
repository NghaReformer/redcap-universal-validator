# Growth references

`@UVRANGE` can judge a measurement by its z-score against a growth reference
instead of fixed limits:

```
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",
          "age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-6,5]}
```

This folder holds the references the module ships with, and the tools to add
more. The WHO tables are in `who/`; see `who/README.md` for their source and
licence.

## The references that ship

| id | Measure | Axis | Valid for |
|---|---|---|---|
| `who-wfa` | weight (kg) | age | 0 days to under 60 months |
| `who-lhfa` | length or height (cm) | age | 0 days to under 60 months |
| `who-bfa` | BMI (kg/m2) | age | 0 days to under 60 months |
| `who-hcfa` | head circumference (cm) | age | 0 days to under 60 months |
| `who-acfa` | mid-upper arm circumference (cm) | age | 91 days to under 60 months |
| `who-ssfa` | subscapular skinfold (mm) | age | 91 days to under 60 months |
| `who-tsfa` | triceps skinfold (mm) | age | 91 days to under 60 months |
| `who-wfl` | weight (kg) | length, lying | 45 to 110 cm |
| `who-wfh` | weight (kg) | height, standing | 65 to 120 cm |
| `who2007-wfa` | weight (kg) | age | 60 to under 121 months |
| `who2007-hfa` | height (cm) | age | 60 to under 229 months |
| `who2007-bfa` | BMI (kg/m2) | age | 60 to under 229 months |

A value outside "Valid for" is not checked; the page tells staff why.

## Adding a reference

Any reference published as L, M and S values per sex (WHO, CDC 2000, a
national reference) can be added without changing the module's code.

1. **Convert the table.** Save it as CSV or tab-separated text with a header
   row, then run `convert.php` from this folder:

   ```
   php convert.php --in wtage.csv --out cdc-wfa.json --x Agemos --x-offset 0.5 --min-x 24.5 --max-x 239.5
   ```

   `--x` names the age, length or height column. `--scale` is the number of
   rows per unit (10 for a table with a row every 0.1 cm). `--x-offset`,
   `--min-x` and `--max-x` handle tables whose rows sit between whole units:
   CDC 2000 ages are half months, where row 24.5 covers ages from 24 up to 25
   months, and the whole-month rows at 24 and 240 are left out. A line that
   repeats the header row, as some CDC files do before the female rows, is
   skipped. The
   sex column defaults to `sex` with 1 for male and 2 for female; change it
   with `--sex`, `--male` and `--female`. The script prints the file's SHA-256
   and an index entry to start from, covering the rows both sexes have: with
   `round` for a table on whole units, and with `floor` and an exclusive
   `below` for one converted with `--x-offset`, checked against the module's
   own row lookup. Edit `axisUnit` in that entry when x is not in days
   (`months` for CDC). It refuses a row with an S below 0.0001, or with an L that
   is not 0 but smaller than 0.000001 in size (write an L of 0 as 0); the module
   refuses such tables too. Check the first and last rows of the file you
   convert; the numbers here are an example.

2. **Describe it in an index.json.** Put the table and an `index.json` in a
   folder on the REDCap server, outside the module folder so that an upgrade
   does not remove it:

   ```json
   {
     "format": "uv-references-1",
     "references": {
       "cdc-wfa": {
         "title": "Weight-for-age, CDC 2000 (2 to 20 years)",
         "file": "cdc-wfa.json",
         "sha256": "the value convert.php printed",
         "measure": "weight", "unit": "kg",
         "axis": "age", "axisUnit": "months", "lookup": "floor",
         "valid": {"min": "24", "below": "240"},
         "adjust": "none"
       }
     }
   }
   ```

3. **Point the module at the folder.** In Control Center, External Modules,
   Universal Field Validator, Configure, set "@UVRANGE growth references —
   folder of extra growth references" to that folder's full path. Its references are added to the
   ones that ship; one with the same id as a shipped reference replaces it.
   The folder fails closed. If its index.json cannot be read, no growth
   reference is used at all, the shipped WHO ones included; if one of its
   entries is broken, that id is not used, even where a shipped reference has
   the same id. Every rule naming such a reference shows a configuration error
   on its form and in the Validation scan; the check after saving skips it.

4. **Check a value.** Before a rule uses the reference, work out one z-score
   with the module's own code and compare it with the source's published
   value or a z-score you trust:

   ```
   php convert.php --check cdc-wfa --folder /var/redcap/growth --sex male --x 30 --y 12.5
   ```

   `--x` is the age, length or height in the reference's axis unit (months
   here) and `--y` the measurement in its unit. The script prints the row's
   L, M and S and the z-score, or why there is none: the id is unknown, the
   entry is broken, the table does not match its SHA-256, or x lies outside
   `valid`. Without `--folder` it reads the shipped references only, so
   `--check who-wfa --sex male --x 365 --y 6.6` prints -3.40, the worked
   example of the action tag guide.

### The index entry

| Key | Meaning |
|---|---|
| `title` | Shown to staff in the notes on the form, and printed by `convert.php --check` |
| `file` | The table, relative to the folder |
| `sha256` | The table's SHA-256. A table that does not match is not used, and every rule naming it reports a configuration error |
| `measure`, `unit` | What is measured, for the notes |
| `axis` | `age`, `length` or `height` |
| `axisUnit` | `days` or `months` for age, `cm` for length and height |
| `lookup` | How a position on the axis finds its row: `round` to the nearest row (WHO 0 to 5 years, by day), `floor` to the row at or below (CDC half months, after the offset), `linear` between the two rows around it (WHO weight-for-length and weight-for-height, WHO 5 to 19 years) |
| `valid` | `min`, and `max` (inclusive) or `below` (exclusive), in the axis unit, as text, within -1000000 to 1000000. Outside it nothing is checked |
| `adjust` | `who-restricted` measures a z beyond ±3 in units of the distance between 2 and 3 SD, as WHO does for weight-based indicators; `none` uses the LMS formula throughout |

An age given in days and a reference by months (or the other way round) are
converted with 30.4375 days to the month, as WHO does.

`pageTables` at the top of an index.json (0 to 50, default 4) sets how many
tables one page may carry. A table sent whole counts 1. When the sex is a
value saved on another form, only that sex's rows travel, and the table
counts 0.5. When the age or length is a saved value, only the few rows
around it travel, and the table counts nothing. A rule whose `when` saved
values already make false carries nothing. A rule past the cap is checked
after saving instead of on the page, and the page still checks that the
measurement is a number above 0. To raise the cap, set `pageTables` in
the index.json of your folder; each whole table adds tens of kilobytes to the
page. A rule whose sex, date or axis field is on a form the viewer cannot see
(or a survey does not show) gets no table and does not count: the page cannot
work out its z-score, so it only checks that the measurement is a number
above 0.

Some rows cap the z-score a measurement can get. With `adjust` `none`, a row
whose L is below 0 never scores above 1/(|L| × S), and one whose L is above 0
never below -1/(L × S); with `who-restricted`, a measurement near 0 never
scores below a floor a few SD under -3. A CDC BMI row with L -2 and S 0.13
gives 3.85 even for a BMI of a million. A rule whose `soft` or `hard` limit
lies beyond such a bound anywhere in `valid` is a configuration error that
names the row and the value the limit must clear, since an absurd value
would otherwise pass that limit.

## Tables that are not WHO-shaped

**A reference with one set of rows for both sexes.** The module needs male and
female rows. Add a sex column and write every row twice, once with 1 and once
with 2, then convert as usual. A rule still names a sex field and its codes.
On a project without one, a calculated field whose equation is `1` serves,
with `"male":"1","female":"2"`.

**A reference given as a mean and a standard deviation.** Write L as 1, M as
the mean and S as the SD divided by the mean; the LMS formula then gives
(y − mean) / SD. For a log-normal reference, write L as 0, M as the median
and S as the SD of the natural log of the measurement. Work the columns out
in a spreadsheet, save it as CSV and convert it.

**Gestational age.** A reference by gestational age (birth weight for
gestational age, for example) uses `"axis": "age"` and `"axisUnit": "days"`,
with `valid` in days of gestation, such as `{"min": "168", "max": "300"}`.
The rule gives the gestational age in days with `"age":{"days":"[ga_days]"}`,
where `ga_days` is a calculated field such as `[ga_weeks]*7 + [ga_extra_days]`.
`dob` and `at` give the age since birth, so leave them out here.

**References that are not L, M and S.** Curves fitted with four parameters
(Box-Cox t, Box-Cox power exponential or skew t, as used for some
INTERGROWTH-21st standards) cannot be written as L, M and S, and the module
does not support them. A standard published as a mean and SD for each week
or day can be added as a normal table, as above.

**CDC 2000.**

- Ages are half months; use `--x-offset 0.5` and the `floor` lookup, as in
  step 1.
- The infant tables (birth to 36 months) start with a row at Agemos 0, which
  is not on the half-month grid. Skip it with `--min-x 0.5`, skip the
  whole-month row at the end with `--max-x 35.5`, and set `valid` to
  `{"min": "0", "below": "36"}`.
- Use `"adjust": "none"`. The module computes the ordinary LMS z-score. CDC's
  flags for biologically implausible values use modified z-scores, which the
  module does not compute, so their cut-offs do not carry over as `hard`
  limits.
- Every CDC BMI-for-age row has an L below 0, so its z-scores have a ceiling
  (see the last section). A `hard` or `soft` high limit above the lowest
  ceiling within `valid` is refused with a message that names the row and the
  value the limit must stay under. Set the limit below that value, or narrow
  `valid`.

## Limits

- A table file is at most 4 MB, with a `scale` of 1 to 1000 rows per unit.
- `valid` bounds lie within -1000000 to 1000000.
- S is at least 0.0001, and an L that is not 0 is at least 0.000001 in size.
- index.json and the tables may start with a byte-order mark, as Windows
  Notepad saves UTF-8. The SHA-256 is that of the file as saved, mark included;
  `convert.php` prints the right one.

Two rule keys help with a custom table. `"measureScale"` multiplies the typed
value before the z-score, for a field in other units than the reference
(0.001 for grams typed into a reference in kg). `"plausible": [lo, hi]`, in
the reference's unit, judges the value when no z-score can be worked out (the
date of birth is blank, say). Both are described in Level 5 of
`docs/action_tag_validation_examples.md`.
