# Example references (made-up numbers)

This folder is a worked example of a folder of extra growth references. The
numbers in it are invented to show the formats. They describe no real
population, so never point the module's "folder of extra growth references"
setting at this folder for real data.

The walkthrough in `docs/action_tag_validation_examples.md` (`@UVRANGE`,
Level 7) builds every file here, and the tests rebuild them:
`tests/convert_php.php` runs each conversion the guide prints and checks that
it gives the committed table byte for byte, and `tests/docs_examples_php.php`
reads this folder as a folder of extra references to test the guide's rules.

| Source | What it shows | Table | Reference |
|---|---|---|---|
| `hfa-national.csv` | L, M and S per whole month (60 to 72 months), read between months | `example-hfa.json` | `example-hfa` |
| `wfl-national.csv` | L, M and S every 0.5 cm (45 to 50 cm), so `--scale 2` | `example-wfl.json` | `example-wfl` |
| `bwga-weekly.csv` | a mean and a standard deviation per completed week of gestation | | |
| `bwga-daily.csv` | the same written as L, M and S, one row per day | `example-bwga.json` | `example-bwga` |

`index.json` describes the three references.
