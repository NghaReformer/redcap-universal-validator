# Live test results: @UVWRITEONCE on chpr-redcap.org pid 149, v2.4.0 (partial)

Date: 2026-10-30. Server: REDCap 17.0.6, module folder `universal_validator_v2.4.0`
(tag `v2.4.0`, commit c5220d3). Browser: the Claude desktop app's built-in pane, signed in
as ngha.ndze (administrator). Run sheet:
[`docs/testbed/WRITEONCE_LIVE_ACCEPTANCE.md`](../../docs/testbed/WRITEONCE_LIVE_ACCEPTANCE.md),
fixture `uvwriteonce_test_fields.csv`.

Setup done: `uv_writeonce_test` designated at `visit_1_arm_1` (380) and
`unscheduled_arm_1` (382), and enabled as a survey with one page per section.

Fixes: every defect in this report is fixed in commit 7934dd6 on branch `fix/live-v240` (not
pushed or deployed yet, 2026-10-31); see "Unreleased" in `CHANGELOG.md`. A live re-test is owed.

## Status: stopped at A2

The run stopped at A2, which creates the role "UV Data manager" on the User Rights page.
Creating or assigning a role grants rights, so the project owner does it by hand. From
Section B on, `uw_arm` and `uw_site` name that role. Until it exists they show the A1
configuration error and fail open, so Sections B to I cannot give the sheet's results.

| # | Result | Seen |
|---|---|---|
| A1 | pass | Under `uw_arm` and `uw_site`: "⚠ Validator configuration error: @UVWRITEONCE on "uw_arm": "UV Data manager" is not a role of this project — use the role name the User Rights page shows, or its unique role name." |
| F | pass | All five Section F errors under their fields, word for word |
| A2 to I7 | not run | Wait for the role |

## What the project owner needs to do

1. User Rights on pid 149: create the role "UV Data manager" with edit rights to
   `uv_writeonce_test`, the Data Import Tool, User Rights, Project Design and Setup, and
   read access to every form. Assign nobody to it yet.
2. Ask for the run to go on from B1. D1 and D4 need the tester moved into the role and
   back out again; that is also a User Rights change for the owner.
3. These steps delete data and are the owner's to run or skip: H1 ("Delete data for THIS
   FORM only" on XE-4), I5 and I5b (on a copy of pid 149 only) and I6.
