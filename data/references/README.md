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
   months, and the whole-month rows at 24 and 240 are left out. The
   sex column defaults to `sex` with 1 for male and 2 for female; change it
   with `--sex`, `--male` and `--female`. The script prints the file's SHA-256
   and an index entry to start from, covering the rows both sexes have: with
   `round` for a table on whole units, and with `floor` and an exclusive
   `below` for one converted with `--x-offset`, checked against the module's
   own row lookup. It refuses a row with an S below 0.0001, or with an L that
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

### The index entry

| Key | Meaning |
|---|---|
| `title` | Shown to staff in notes and in the data dictionary check |
| `file` | The table, relative to the folder |
| `sha256` | The table's SHA-256. A table that does not match is not used, and every rule naming it reports a configuration error |
| `measure`, `unit` | What is measured, for the notes |
| `axis` | `age`, `length` or `height` |
| `axisUnit` | `days` or `months` for age, `cm` for length and height |
| `lookup` | How a position on the axis finds its row: `round` to the nearest row (WHO 0 to 5 years, by day), `floor` to the row at or below (CDC half months, after the offset), `linear` between the two rows around it (WHO length and height, WHO 5 to 19 years) |
| `valid` | `min`, and `max` (inclusive) or `below` (exclusive), in the axis unit, as text, within -1000000 to 1000000. Outside it nothing is checked |
| `adjust` | `who-restricted` measures a z beyond ±3 in units of the distance between 2 and 3 SD, as WHO does for weight-based indicators; `none` uses the LMS formula throughout |

An age given in days and a reference by months (or the other way round) are
converted with 30.4375 days to the month, as WHO does. `pageTables` at the top
of an index.json sets how many references one page may carry (default 4); a
rule over that number is checked after saving instead of on the page. A rule
whose sex, date or axis field is on a form the viewer cannot see (or a survey
does not show) gets no table and does not count: the page cannot work out its
z-score, so it only checks that the measurement is a number above 0.

Some rows cap the z-score a measurement can get. With `adjust` `none`, a row
whose L is below 0 never scores above 1/(|L| × S), and one whose L is above 0
never below -1/(L × S); with `who-restricted`, a measurement near 0 never
scores below a floor a few SD under -3. A CDC BMI row with L -2 and S 0.13
gives 3.85 even for a BMI of a million. A rule whose `soft` or `hard` limit
lies beyond such a bound anywhere in `valid` is a configuration error that
names the row and the value the limit must clear, since an absurd value
would otherwise pass that limit.
