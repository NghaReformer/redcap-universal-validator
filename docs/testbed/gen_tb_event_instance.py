#!/usr/bin/env python3
"""
gen_tb_event_instance.py - build the live REDCap test bed for the event and
repeating-instance references feature (2.1.0-rc.1) on chpr-redcap.org pid 149.

Everything the live project needs comes out of the tables below, so a new case
is one row, not new code:

    ARMS / EVENTS          -> arms.csv, events.csv       (Define My Events upload)
    DESIGNATIONS           -> mappings.csv               (Designate Instruments upload)
    REPEATING              -> repeating.csv              (set by hand in Project Setup)
    FORMS (fields + tags)  -> dictionary_additions.csv   (APPEND to the live dictionary)
    SEED                   -> seed_data.csv              (Data Import Tool)
    SCALE                  -> seed_scale.csv             (optional large repeat bucket)

Pid 149 was a classic project before this test bed. Enabling longitudinal
collection puts every existing instrument in the auto-created event_1_arm_1, so
that event is the enrolment event here and the legacy test forms keep working.

Each field's Field Note says what to type and what must happen. Section headers
name the feature under test. Fields on xe_negative must each show a
configuration error while the feature is ON; read their notes for the text.

Run:  python docs/testbed/gen_tb_event_instance.py [--scale N]
Out:  docs/testbed/event_instance/*.csv
"""
import argparse
import csv
import json
import os

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "event_instance")

# ------------------------------------------------------------------ structure --
ARMS = [(1, "Main"), (2, "Substudy")]

# (unique_event_name, label, arm, day_offset). REDCap derives the unique name
# from the label; labels are chosen so the derived name equals the first column.
EVENTS = [
    ("event_1_arm_1", "Event 1", 1, 0),
    ("baseline_arm_1", "Baseline", 1, 5),
    ("visit_1_arm_1", "Visit 1", 1, 30),
    ("visit_2_arm_1", "Visit 2", 1, 60),
    ("unscheduled_arm_1", "Unscheduled", 1, 70),
    ("followup_arm_1", "Followup", 1, 90),
    ("sub_enrol_arm_2", "Sub enrol", 2, 0),
    ("sub_visit_arm_2", "Sub visit", 2, 30),
]

LEGACY_FORMS = ["id_validation_test", "staff_review", "uv_choices_test",
                "uv_hidden_test", "uv_modes_test", "wb_test"]

DESIGNATIONS = {
    "event_1_arm_1": LEGACY_FORMS + ["xe_enrol", "xe_negative"],
    "baseline_arm_1": ["xe_visit", "xr_specimen"],
    "visit_1_arm_1": ["xe_visit", "xr_specimen", "xr_result"],
    # xr_specimen deliberately NOT here: relative selectors must skip this event.
    "visit_2_arm_1": ["xe_visit", "xr_result"],
    # xe_visit deliberately NOT here: previous-event-name from followup = visit_2.
    "unscheduled_arm_1": ["xe_unsched"],
    "followup_arm_1": ["xe_visit", "xe_close"],
    "sub_enrol_arm_2": ["xe_enrol"],
    "sub_visit_arm_2": ["xe_visit", "xe_sub"],
}

# (event, form) -> repeating instrument; (event, None) -> repeating event.
REPEATING = [
    ("baseline_arm_1", "xr_specimen"),
    ("visit_1_arm_1", "xr_specimen"),
    ("visit_1_arm_1", "xr_result"),
    ("visit_2_arm_1", "xr_result"),
    ("unscheduled_arm_1", None),
]

# ------------------------------------------------------------- dictionary --
COLUMNS = [
    "Variable / Field Name", "Form Name", "Section Header", "Field Type",
    "Field Label", "Choices, Calculations, OR Slider Labels", "Field Note",
    "Text Validation Type OR Show Slider Number", "Text Validation Min",
    "Text Validation Max", "Identifier?", "Branching Logic (Show field only if...)",
    "Required Field?", "Custom Alignment", "Question Number (surveys only)",
    "Matrix Group Name", "Matrix Ranking?", "Field Annotation",
]

YN = "1, Yes | 0, No"


def tag(kind, rule):
    """One action tag. A dict becomes compact JSON; a string is used as-is."""
    if isinstance(rule, dict):
        return "@%s=%s" % (kind, json.dumps(rule, separators=(",", ":")))
    return "@%s%s" % (kind, rule)


def ref(field, **kw):
    """A binding for the 'references' object."""
    b = {"field": field}
    b.update(kw)
    return b


def assert_(expr, message=None, refs=None, block=None, **extra):
    r = {"assert": expr}
    if refs:
        r["references"] = refs
    if message:
        r["message"] = message
    if block:
        r["blockSave"] = block
    r.update(extra)
    return tag("UVASSERT", r)


# Each form: list of (name, type, label, choices, validation, note, tags, section).
# 'tags' is a list joined by spaces into the annotation.
def F(name, ftype, label, note="", tags=(), choices="", valid="", section=""):
    return dict(name=name, ftype=ftype, label=label, note=note, tags=list(tags),
                choices=choices, valid=valid, section=section)


CONSENT_DATE = ref("xe_consent_date", event="event_1_arm_1", type="date")
SPEC_EVENTS = ["baseline_arm_1", "visit_1_arm_1"]

FORMS = {}

FORMS["xe_enrol"] = [
    F("xe_consent_date", "text", "Consent date", valid="date_ymd",
      section="Enrolment sources (no rules here: later events read these values)",
      note="Seed: XE-1 2026-01-10. Later visits compare against this."),
    F("xe_consent_dmy", "text", "Consent date (D-M-Y display)", valid="date_dmy",
      note="Seed: 10-01-2026. xv_date_dmy compares against this with a typed binding."),
    F("xe_consent_dt", "text", "Consent date and time", valid="datetime_ymd",
      note="Seed: 2026-01-10 09:00. xv_dt measures elapsed hours from this."),
    F("xe_consent", "checkbox", "Consent given for", choices="1, Data | 2, Specimens | 3, Future research",
      note="Tick Specimens: xv_kit_check on visits then validates (Damm)."),
    F("xe_site", "dropdown", "Enrolment site", choices="1, North | 2, South | 3, East",
      note="Drives the xv_clinic choice filter on every visit."),
    F("xe_code", "text", "Participant code", note="Seed: PX-0001. Visits ask for it again (xv_code_confirm)."),
    F("xe_limit", "text", "Total specimen volume limit (mL)", valid="number",
      note="Seed: 50. Sum of xs_volume across arm 1 must stay at or below it."),
    F("xe_ae", "yesno", "Adverse event at enrolment?", choices=YN,
      note="Yes makes xv_ae_note REQUIRED on every visit."),
    F("xe_weight", "text", "Enrolment weight (kg)", valid="number",
      note="Arm 2: xsub_prev compares against the sub_enrol_arm_2 value."),
]

FORMS["xe_visit"] = [
    F("xv_date", "text", "Visit date", valid="date_ymd",
      section="A. Named event + typed date",
      note="Must be on or after consent (event_1_arm_1). Type 2026-01-01: 'Visit is before consent'. ADVISORY: never blocks although the tag says hard.",
      tags=[assert_("{visit}>={consent}", "Visit is before consent",
                    {"visit": ref("xv_date", type="date"), "consent": CONSENT_DATE}, "hard")]),
    F("xv_weight", "text", "Weight (kg)", valid="number",
      section="B. Relative events (previous/next/first/last-event-name)",
      note="Must be >= previous visit's weight. Previous of Followup = Visit 2 (Unscheduled skipped: xe_visit not designated there). On Baseline: unresolved, no verdict.",
      tags=[assert_("[xv_weight]>=[previous-event-name][xv_weight]", "Weight dropped since the previous visit", block="confirm")]),
    F("xv_temp", "text", "Temperature (C)", valid="number",
      note="Must be <= the NEXT visit's temperature. On Followup (last): unresolved.",
      tags=[assert_("[xv_temp]<=[next-event-name][xv_temp]", "Higher than the next visit")]),
    F("xv_height", "text", "Height (cm)", valid="number",
      note="Must be >= the FIRST designated event's height (Baseline in arm 1, Sub visit in arm 2).",
      tags=[assert_("[xv_height]>=[first-event-name][xv_height]", "Shorter than at the first visit")]),
    F("xv_status", "dropdown", "Visit status", choices="1, Ongoing | 2, Completed",
      note="Set 2 on Followup, save: xv_close_note becomes required on every visit."),
    F("xv_close_note", "notes", "Close-out comment",
      note="REQUIRED while [last-event-name][xv_status]='2' (the Followup visit).",
      tags=[tag("UVREQUIRED", {"when": "[last-event-name][xv_status]='2'", "message": "Close-out comment needed once the final visit is complete"})]),
    F("xv_prevdate", "text", "Visit date again (legacy string compare)", valid="date_ymd",
      note="Plain (untyped) compare against the previous event's xv_date.",
      tags=[assert_("[xv_prevdate]>=[previous-event-name][xv_date]", "Dated before the previous visit")]),
    F("xv_ae_note", "notes", "Adverse-event follow-up",
      section="C. Cross-event gates (checkbox, yes/no, choices, case)",
      note="REQUIRED when enrolment said AE = Yes.",
      tags=[tag("UVREQUIRED", {"when": "[event_1_arm_1][xe_ae]='1'", "message": "Document follow-up of the enrolment adverse event"})]),
    F("xv_kit_check", "text", "Specimen kit number (Damm)",
      note="Validated (Damm) ONLY when enrolment consent ticked Specimens: 57240 valid / 57241 bad. Otherwise inert.",
      tags=[tag("UVALIDATE", {"algorithm": "damm", "when": "[event_1_arm_1][xe_consent(2)]='1'"})]),
    F("xv_clinic", "dropdown", "Clinic",
      choices="N1, North clinic A | N2, North clinic B | S1, South clinic A | S2, South clinic B | E1, East clinic A",
      note="Options filtered by the ENROLMENT site: North shows N1/N2, South S1/S2, otherwise E1.",
      tags=[tag("UVCHOICES", {"when": "[event_1_arm_1][xe_site]='1'", "show": ["N1", "N2"]}),
            tag("UVCHOICES", {"when": "[event_1_arm_1][xe_site]='2'", "show": ["S1", "S2"]}),
            tag("UVCHOICES", {"show": ["E1"]})]),
    F("xv_code_confirm", "text", "Participant code (retype)",
      note="Must equal the enrolment code. px-0001 passes (text compare ignores case by default); PX-0002 fails.",
      tags=[assert_("[xv_code_confirm]=[event_1_arm_1][xe_code]", "Does not match the enrolment code")]),
    F("xv_code_exact", "text", "Participant code (case-sensitive retype)",
      note="Same as above with caseSensitive: px-0001 FAILS.",
      tags=[assert_("[xv_code_exact]=[event_1_arm_1][xe_code]", "Does not match exactly", caseSensitive=True)]),
    F("xv_window", "text", "Visit date for window check", valid="date_ymd",
      section="D. Elapsed time and display formats",
      note="Elapsed calendar days from consent must be 0..120. XE-1: 2026-02-01 passes, 2026-06-01 fails, 2025-12-01 fails (negative).",
      tags=[assert_("{d}>=0 and {d}<=120", "Outside the 120-day window",
                    {"d": ref("xv_window", type="date", elapsedFrom="[event_1_arm_1][xe_consent_date]", unit="days")})]),
    F("xv_date_dmy", "text", "Visit date (D-M-Y display)", valid="date_dmy",
      note="Typed compare against D-M-Y consent. XE-1: 11-01-2026 passes, 09-01-2026 fails. A string compare would get this wrong.",
      tags=[assert_("{v}>={c}", "Before consent (D-M-Y)",
                    {"v": ref("xv_date_dmy", type="date"), "c": ref("xe_consent_dmy", event="event_1_arm_1", type="date")})]),
    F("xv_dt", "text", "Visit date-time (M-D-Y display)", valid="datetime_mdy",
      note="Hours since consent date-time must be >= 0. XE-1: 01-10-2026 08:59 fails, 01-10-2026 09:00 passes.",
      tags=[assert_("{h}>=0", "Before the consent time",
                    {"h": ref("xv_dt", type="datetime", elapsedFrom="[event_1_arm_1][xe_consent_dt]", unit="hours")})]),
    F("xv_dmy_legacy", "text", "Visit date, D-M-Y, UNTYPED compare", valid="date_dmy",
      note="Untyped [x]>=[previous-event-name][x] on a D-M-Y field. Seed passes on the server (audit/scan silent) but the page compares 10-02-2026 with 2026-01-15 as text and shows a violation on Visit 1. Page and audit must agree.",
      tags=[assert_("[xv_dmy_legacy]>=[previous-event-name][xv_dmy_legacy]", "Dated before the previous visit (D-M-Y)")]),
    F("xv_dose", "text", "Dose given this visit (mg)", valid="number",
      section="E. Aggregates across events",
      note="Sum of xv_dose over every arm-1 visit (this one live) must be <= enrolment xe_limit.",
      tags=[assert_("{total}<=[event_1_arm_1][xe_limit]", "Total dose exceeds the enrolment limit",
                    {"total": ref("xv_dose", events="arm", arm=1, aggregate="sum")})]),
    F("xv_hr", "text", "Heart rate", valid="integer",
      note="Must not exceed the maximum heart rate of the OTHER arm-1 visits (excludeCurrent).",
      tags=[assert_("[xv_hr]<={hi}", "Highest heart rate so far",
                    {"hi": ref("xv_hr", events="arm", arm=1, aggregate="maximum", excludeCurrent=True)})]),
    F("xv_kit", "text", "Kit number",
      section="F. Uniqueness within the record",
      note="Must not repeat on another visit of THIS record. Another record may reuse it.",
      tags=[tag("UVUNIQUE", "=record")]),
    F("xv_tube_type", "dropdown", "Tube type", choices="1, EDTA | 2, Serum"),
    F("xv_tube", "text", "Tube label",
      note="Unique within the record per tube type (composite with xv_tube_type).",
      tags=[tag("UVUNIQUE", {"scope": "record", "with": ["xv_tube_type"], "message": "Tube label already used for this tube type"})]),
    F("xv_arm_ref", "text", "Weight vs arm-1 baseline", valid="number",
      section="G. Named event in another arm",
      note="Must be >= [baseline_arm_1][xv_weight]. Opened in arm 2 (Sub visit) this reads the arm-1 Baseline of the same record.",
      tags=[assert_("[xv_arm_ref]>=[baseline_arm_1][xv_weight]", "Below the arm-1 baseline weight")]),
]

FORMS["xr_specimen"] = [
    F("xs_id", "text", "Specimen ID",
      section="Repeating instrument on Baseline and Visit 1",
      note="Unique across ALL events and instances of this record. Also: at most 5 specimens per event bucket (count).",
      tags=[tag("UVUNIQUE", "=record"),
            assert_("{n}<=5", "More than five specimens in this event", {"n": ref("xs_id", aggregate="count")})]),
    F("xs_type", "dropdown", "Specimen type", choices="1, Sputum | 2, Blood | 3, Urine",
      note="At most two DIFFERENT types per event bucket (distinct-count).",
      tags=[assert_("{d}<=2", "More than two specimen types in this event", {"d": ref("xs_type", aggregate="distinct-count")})]),
    F("xs_barcode", "text", "Barcode",
      note="Unique within the record per specimen type (composite).",
      tags=[tag("UVUNIQUE", {"scope": "record", "with": ["xs_type"]})]),
    F("xs_date", "text", "Collection date", valid="date_ymd",
      section="Instance selectors",
      note="Must be on or after the PREVIOUS instance's date (current-1). Instance 1: unresolved. XE-3 instance 3 (2 missing): unresolved.",
      tags=[assert_("{d}>={p}", "Earlier than the previous specimen",
                    {"d": ref("xs_date", type="date"), "p": ref("xs_date", instance="previous-instance", type="date")})]),
    F("xs_time", "text", "Collection date-time", valid="datetime_ymd",
      note="Source for xr_time elapsed-hours check (first instance at Baseline)."),
    F("xs_first_cmp", "text", "Value vs first instance", valid="number",
      note="Must be >= the first-instance value.",
      tags=[assert_("[xs_first_cmp]>=[xs_first_cmp][first-instance]", "Below the first specimen")]),
    F("xs_next_cmp", "text", "Value vs next instance", valid="number",
      note="Must be <= the next-instance value (current+1). On the last instance: unresolved.",
      tags=[assert_("[xs_next_cmp]<=[xs_next_cmp][next-instance]", "Above the next specimen")]),
    F("xs_n2", "text", "Value vs instance 2", valid="number",
      note="Must be >= instance 2 of this event.",
      tags=[assert_("[xs_n2]>=[xs_n2][2]", "Below instance 2")]),
    F("xs_last_cmp", "text", "Value vs Baseline last instance", valid="number",
      note="Must be <= the LAST Baseline instance value (named event + last-instance).",
      tags=[assert_("[xs_last_cmp]<=[baseline_arm_1][xs_last_cmp][last-instance]", "Above the last Baseline specimen")]),
    F("xs_volume", "text", "Volume (mL)", valid="number",
      section="Aggregates over the repeat bucket",
      note="Sum of xs_volume over Baseline + Visit 1, all instances (this one live) must be <= enrolment xe_limit (50).",
      tags=[assert_("{total}<=[event_1_arm_1][xe_limit]", "Total volume exceeds the enrolment limit",
                    {"total": ref("xs_volume", events=SPEC_EVENTS, aggregate="sum")})]),
    F("xs_hb", "text", "Haemoglobin (g/dL)", valid="number",
      note="Must be <= the exact AVERAGE of the other instances in this event (excludeCurrent). No rounding.",
      tags=[assert_("[xs_hb]<={mean}", "Above the mean of the other specimens",
                    {"mean": ref("xs_hb", aggregate="average", excludeCurrent=True)})]),
    F("xs_threshold", "text", "Result threshold", valid="number",
      note="Source for xr_value (matched by specimen ID)."),
    F("xs_positive", "yesno", "Smear positive?", choices=YN),
    F("xs_any_note", "notes", "Positive follow-up",
      note="REQUIRED when ANY instance in this event bucket is positive.",
      tags=[tag("UVREQUIRED", {"when": "[xs_positive][any-instance]='1'", "message": "At least one specimen is positive"})]),
    F("xs_pop", "text", "Answered-threshold check", valid="integer",
      note="Type the number of instances with a threshold entered (populated-count); anything else fails.",
      tags=[assert_("[xs_pop]={p}", "Does not match the populated-threshold count", {"p": ref("xs_threshold", aggregate="populated-count")})]),
]

MATCH_ID = {"xs_id": "[xr_spec_id]"}
FORMS["xr_result"] = [
    F("xr_spec_id", "text", "Specimen ID tested",
      section="Repeating instrument on Visit 1 and Visit 2 - matched to specimens by ID",
      note="Must match a specimen on Baseline or Visit 1 (count > 0). Case and leading zeros matter.",
      tags=[assert_("{m}>=1", "No specimen with this ID",
                    {"m": ref("xs_id", events=SPEC_EVENTS, match=MATCH_ID, aggregate="count")})]),
    F("xr_value", "text", "Result value", valid="number",
      note="Must be >= the matched Baseline specimen's threshold. Unmatched/blank key: unresolved.",
      tags=[assert_("[xr_value]>={thr}", "Below the matched specimen threshold",
                    {"thr": ref("xs_threshold", event="baseline_arm_1", match=MATCH_ID)})]),
    F("xr_value_any", "text", "Result value (specimen on either event)", valid="number",
      note="Same, matching across Baseline and Visit 1 (events list).",
      tags=[assert_("[xr_value_any]>={thr}", "Below the matched threshold",
                    {"thr": ref("xs_threshold", events=SPEC_EVENTS, match=MATCH_ID)})]),
    F("xr_date", "text", "Result date", valid="date_ymd",
      note="Must be on or after the matched Baseline collection date (typed).",
      tags=[assert_("{r}>={s}", "Result before collection",
                    {"r": ref("xr_date", type="date"), "s": ref("xs_date", event="baseline_arm_1", match=MATCH_ID, type="date")})]),
    F("xr_time", "text", "Result date-time", valid="datetime_ymd",
      note="0..48 hours after the FIRST Baseline specimen's collection time.",
      tags=[assert_("{h}>=0 and {h}<=48", "Not within 48 hours of first collection",
                    {"h": ref("xr_time", type="datetime", elapsedFrom="[baseline_arm_1][xs_time][first-instance]", unit="hours")})]),
    F("xr_type", "dropdown", "Specimen type tested", choices="1, Sputum | 2, Blood | 3, Urine"),
    F("xr_comp", "text", "Composite-key threshold check", valid="number",
      note="Match on specimen ID AND type (composite key).",
      tags=[assert_("[xr_comp]>={thr}", "Below the composite-matched threshold",
                    {"thr": ref("xs_threshold", event="baseline_arm_1", match={"xs_id": "[xr_spec_id]", "xs_type": "[xr_type]"})})]),
    F("xr_absent_note", "notes", "Why is there no matching specimen?",
      note="REQUIRED when no Baseline/Visit-1 specimen matches the ID.",
      tags=[tag("UVREQUIRED", {"when": "{m}=0", "references": {"m": ref("xs_id", events=SPEC_EVENTS, match=MATCH_ID, aggregate="count")},
                               "message": "Explain the missing specimen"})]),
    F("xr_summary", "notes", "Lab summary",
      note="REQUIRED when any Visit-1 specimen exists (exists aggregate).",
      tags=[tag("UVREQUIRED", {"when": "{e}=1", "references": {"e": ref("xs_id", event="visit_1_arm_1", aggregate="exists")},
                               "message": "Summarise the Visit 1 specimens"})]),
]

FORMS["xe_unsched"] = [
    F("xu_date", "text", "Unscheduled visit date", valid="date_ymd",
      section="Non-repeating instrument inside a REPEATING EVENT",
      note="Must be on or after the previous instance of this repeating event. Instance 1: unresolved.",
      tags=[assert_("{d}>={p}", "Earlier than the previous unscheduled visit",
                    {"d": ref("xu_date", type="date"), "p": ref("xu_date", instance="previous-instance", type="date")})]),
    F("xu_after_base", "text", "Date vs Baseline visit", valid="date_ymd",
      note="Must be on or after the Baseline xv_date (typed, other instrument, other event).",
      tags=[assert_("{d}>={b}", "Before the Baseline visit",
                    {"d": ref("xu_after_base", type="date"), "b": ref("xv_date", event="baseline_arm_1", type="date")})]),
    F("xu_weight", "text", "Weight (kg)", valid="number",
      note="Must be <= the maximum xv_weight across arm-1 visits.",
      tags=[assert_("[xu_weight]<={hi}", "Above every scheduled visit weight",
                    {"hi": ref("xv_weight", events="arm", arm=1, aggregate="maximum")})]),
    F("xu_code", "text", "Unscheduled visit code",
      note="Unique across the repeating-event instances of this record.",
      tags=[tag("UVUNIQUE", "=record")]),
    F("xu_reason", "notes", "Reason for repeat",
      note="REQUIRED from instance 2 on (previous instance has a date).",
      tags=[tag("UVREQUIRED", {"when": "[xu_date][previous-instance]<>''", "message": "Explain the repeat unscheduled visit"})]),
    F("xu_last", "text", "Value vs last instance", valid="number",
      note="Must be <= the last existing instance's value.",
      tags=[assert_("[xu_last]<=[xu_last][last-instance]", "Above the last unscheduled value")]),
]

FORMS["xe_close"] = [
    F("xc_complete", "yesno", "Study complete?", choices=YN,
      section="Close-out: aggregates over the whole arm (enable this form as a SURVEY for the leak test)"),
    F("xc_visits", "text", "Number of scheduled visit rows", valid="integer",
      note="Must equal the count of xv_date rows across arm 1 (saved blanks count).",
      tags=[assert_("[xc_visits]={n}", "Does not match the visit count", {"n": ref("xv_date", events="arm", arm=1, aggregate="count")})]),
    F("xc_total_vol", "text", "Total specimen volume (mL)", valid="number",
      note="Must equal the exact sum of xs_volume on Baseline + Visit 1.",
      tags=[assert_("[xc_total_vol]={t}", "Does not match the total volume", {"t": ref("xs_volume", events=SPEC_EVENTS, aggregate="sum")})]),
    F("xc_mean_weight", "text", "Mean visit weight", valid="number",
      note="Must equal the exact average of xv_weight across arm 1.",
      tags=[assert_("[xc_mean_weight]={a}", "Does not match the mean weight", {"a": ref("xv_weight", events="arm", arm=1, aggregate="average")})]),
    F("xc_kits", "text", "Distinct kit numbers", valid="integer",
      note="Must equal distinct-count of xv_kit across arm 1.",
      tags=[assert_("[xc_kits]={k}", "Does not match the distinct kit count", {"k": ref("xv_kit", events="arm", arm=1, aggregate="distinct-count")})]),
    F("xc_min_hb", "text", "Lowest haemoglobin", valid="number",
      note="Must equal the minimum xs_hb over Baseline + Visit 1.",
      tags=[assert_("[xc_min_hb]={m}", "Does not match the lowest haemoglobin", {"m": ref("xs_hb", events=SPEC_EVENTS, aggregate="minimum")})]),
    F("xc_any_pos", "yesno", "Any positive smear at Baseline?", choices=YN,
      note="Yes is valid only if a Baseline specimen is positive (any-instance).",
      tags=[assert_("[xc_any_pos]='0' or [baseline_arm_1][xs_positive][any-instance]='1'", "No positive Baseline specimen")]),
    F("xc_all_neg", "yesno", "All Baseline smears negative?", choices=YN,
      note="Yes is valid only if every Baseline specimen is negative (all-instances; empty = unresolved).",
      tags=[assert_("[xc_all_neg]='0' or [baseline_arm_1][xs_positive][all-instances]='0'", "Not every Baseline specimen is negative")]),
]

FORMS["xe_sub"] = [
    F("xsub_weight", "text", "Sub-study weight", valid="number",
      section="Arm 2 sub-study: named arm-1 event, relative event in arm 2",
      note="Must be >= the arm-1 Baseline weight of the same record.",
      tags=[assert_("[xsub_weight]>=[baseline_arm_1][xv_weight]", "Below the arm-1 baseline weight")]),
    F("xsub_prev", "text", "Weight vs arm-2 enrolment", valid="number",
      note="previous-event-name stays in arm 2: compares with xe_weight at Sub enrol.",
      tags=[assert_("[xsub_prev]>=[previous-event-name][xe_weight]", "Below the arm-2 enrolment weight")]),
]


def neg(name, label, expect, rule_tag):
    return F(name, "text", label, note="Expect CONFIG ERROR: " + expect, tags=[rule_tag])


TWENTY_ONE = {"a%d" % i: ref("xe_weight", event="event_1_arm_1") for i in range(21)}
FORMS["xe_negative"] = [
    neg("xn_unknown_event", "Unknown event name", "unknown event nosuch_arm_1",
        assert_("[xn_unknown_event]=[nosuch_arm_1][xe_code]")),
    neg("xn_inst_nonrep", "Instance on a non-repeating target", "instance selector on a non-repeating target",
        assert_("[xn_inst_nonrep]=[event_1_arm_1][xe_code][2]")),
    neg("xn_rel_nonrep", "Relative instance from non-repeating context", "relative instance needs a repeating source",
        assert_("[xn_rel_nonrep]=[xe_code][previous-instance]")),
    neg("xn_ambiguous", "Repeat bucket without selector", "ambiguous repeating reference",
        assert_("[xn_ambiguous]=[baseline_arm_1][xs_id]")),
    neg("xn_two_coll", "Two collection operands", "only one collection operand per comparison",
        assert_("[baseline_arm_1][xs_hb][any-instance]=[baseline_arm_1][xs_volume][any-instance]")),
    neg("xn_smartvar", "Unsupported smart variable", "unsupported smart variable",
        assert_("[xn_smartvar]=[record-dag-name]")),
    neg("xn_instance_zero", "Instance 0", "instance must be a positive integer",
        assert_("[xn_instance_zero]=[baseline_arm_1][xs_id][0]")),
    neg("xn_four_groups", "Four bracket groups", "too many bracket groups",
        assert_("[xn_four_groups]=[baseline_arm_1][xs_id][1][2]")),
    neg("xn_alias_bad", "Alias with a hyphen", "invalid alias name",
        assert_("[xn_alias_bad]={bad-alias}", refs={"bad-alias": ref("xe_code", event="event_1_arm_1")})),
    neg("xn_alias_undef", "Alias not declared", "undeclared alias",
        assert_("[xn_alias_undef]={nope}", refs={"other": ref("xe_code", event="event_1_arm_1")})),
    neg("xn_event_both", "event and events together", "event or events, not both",
        assert_("[xn_event_both]={x}", refs={"x": ref("xe_code", event="event_1_arm_1", events=["event_1_arm_1"])})),
    neg("xn_arm_empty", "events arm with an arm that has no such instrument", "arm selects no event",
        assert_("[xn_arm_empty]<={x}", refs={"x": ref("xs_volume", events="arm", arm=9, aggregate="sum")})),
    neg("xn_elapsed_any", "elapsedFrom with any-instance", "elapsedFrom must be scalar",
        assert_("{h}>=0", refs={"h": ref("xn_elapsed_any", type="date", elapsedFrom="[baseline_arm_1][xs_date][any-instance]", unit="days")})),
    neg("xn_typed_agg", "Typed binding with aggregate", "typed binding cannot aggregate",
        assert_("[xn_typed_agg]>={x}", refs={"x": ref("xs_date", event="baseline_arm_1", type="date", aggregate="maximum")})),
    neg("xn_match_inst", "match plus instance", "match cannot also specify an instance",
        assert_("[xn_match_inst]={x}", refs={"x": ref("xs_id", event="baseline_arm_1", instance=1, match={"xs_id": "[xn_match_inst]"})})),
    neg("xn_unit_only", "unit without elapsedFrom", "unit needs elapsedFrom",
        assert_("{x}>=0", refs={"x": ref("xn_unit_only", type="date", unit="days")})),
    neg("xn_21", "21 bindings", "at most 20 bindings",
        assert_("[xn_21]>={a0}", refs=TWENTY_ONE)),
    neg("xn_sql", "SQL-looking field name in a binding", "unknown field (text must never reach SQL)",
        assert_("[xn_sql]={x}", refs={"x": ref("xe_code' OR 1=1 --", event="event_1_arm_1")})),
    neg("xn_proto", "__proto__ as alias", "invalid alias name; no prototype pollution in the browser",
        assert_("[xn_proto]={__proto__}", refs={"__proto__": ref("xe_code", event="event_1_arm_1")})),
    neg("xn_cb_nocode", "Cross-event checkbox without a code", "checkbox needs a (code)",
        assert_("[xn_cb_nocode]=[event_1_arm_1][xe_consent]")),
    neg("xn_function", "Function call", "functions are not supported",
        assert_("datediff([xn_function],[event_1_arm_1][xe_consent_date],'d')>0")),
    F("xn_xss", "text", "Valid rule with an HTML message",
      note="NOT an error. Type anything but 'safe': the message must render as literal text, no image, no script.",
      tags=[assert_("[xn_xss]='safe'", "<img src=x onerror=alert('uv-xss')><b>bold?</b>")]),
]

FORM_ORDER = ["xe_enrol", "xe_visit", "xr_specimen", "xr_result", "xe_unsched",
              "xe_close", "xe_sub", "xe_negative"]


def dictionary_rows():
    rows = []
    for form in FORM_ORDER:
        for fd in FORMS[form]:
            rows.append({
                "Variable / Field Name": fd["name"], "Form Name": form,
                "Section Header": fd["section"], "Field Type": fd["ftype"],
                "Field Label": fd["label"],
                "Choices, Calculations, OR Slider Labels": fd["choices"],
                "Field Note": fd["note"],
                "Text Validation Type OR Show Slider Number": fd["valid"],
                "Text Validation Min": "", "Text Validation Max": "",
                "Identifier?": "", "Branching Logic (Show field only if...)": "",
                "Required Field?": "", "Custom Alignment": "",
                "Question Number (surveys only)": "", "Matrix Group Name": "",
                "Matrix Ranking?": "", "Field Annotation": " ".join(fd["tags"]),
            })
    return rows


# ------------------------------------------------------------------- seed --
# One dict per saved row. 'ev' = unique event name, 'rf' = repeating form (''
# for a repeating event), 'ri' = instance. Checkbox values use field___code.
def row(rec, ev, rf=None, ri=None, **vals):
    r = {"record_id": rec, "redcap_event_name": ev,
         "redcap_repeat_instrument": rf or "", "redcap_repeat_instance": "" if ri is None else str(ri)}
    r.update({k: str(v) for k, v in vals.items()})
    return r


def enrol(rec, ev="event_1_arm_1", ae="0", consent=("1", "2"), site="1", code="PX-0001", weight="70"):
    cb = {"xe_consent___%s" % c: ("1" if c in consent else "0") for c in ("1", "2", "3")}
    return row(rec, ev, xe_consent_date="2026-01-10", xe_consent_dmy="2026-01-10",
               xe_consent_dt="2026-01-10 09:00", xe_site=site, xe_code=code, xe_limit="50",
               xe_ae=ae, xe_weight=weight, xe_enrol_complete="2", **cb)


def visit(rec, ev, date, weight, temp, height, kit, dose="5", hr="70", status="1", **extra):
    vals = dict(xv_date=date, xv_weight=weight, xv_temp=temp, xv_height=height, xv_status=status,
                xv_kit=kit, xv_dose=dose, xv_hr=hr, xe_visit_complete="2")
    vals.update(extra)
    return row(rec, ev, **vals)


def spec(rec, ev, inst, sid, stype, date, vol, hb, thr, pos="0", **extra):
    vals = dict(xs_id=sid, xs_type=stype, xs_barcode="BC-" + sid, xs_date=date,
                xs_time=date + " 08:00", xs_volume=vol, xs_hb=hb, xs_threshold=thr,
                xs_positive=pos, xs_first_cmp="10", xs_next_cmp=str(10 + inst), xs_n2="10",
                xs_last_cmp="1", xr_specimen_complete="2")
    vals.update(extra)
    return row(rec, ev, "xr_specimen", inst, **vals)


def seed_rows():
    R = []
    # XE-1: every rule passes. A scan must report ZERO extended violations here.
    R += [enrol("XE-1"),
          visit("XE-1", "baseline_arm_1", "2026-01-15", "70", "36.5", "170", "K-001",
                xv_dmy_legacy="2026-01-15"),
          visit("XE-1", "visit_1_arm_1", "2026-02-10", "72", "36.6", "171", "K-002", hr="70",
                xv_dmy_legacy="2026-02-10"),
          visit("XE-1", "visit_2_arm_1", "2026-03-10", "74", "36.7", "171", "K-003", hr="70"),
          visit("XE-1", "followup_arm_1", "2026-04-10", "76", "36.8", "172", "K-004", hr="70"),
          spec("XE-1", "baseline_arm_1", 1, "S-001", "1", "2026-01-15", "10", "12", "5"),
          spec("XE-1", "baseline_arm_1", 2, "S-002", "1", "2026-01-16", "10", "12", "5", xs_last_cmp="1"),
          spec("XE-1", "visit_1_arm_1", 1, "S-003", "2", "2026-02-10", "10", "11", "5"),
          row("XE-1", "visit_1_arm_1", "xr_result", 1, xr_spec_id="S-001", xr_value="6", xr_value_any="6",
              xr_date="2026-01-20", xr_time="2026-01-16 08:00", xr_type="1", xr_comp="6",
              xr_summary="ok", xr_result_complete="2"),
          row("XE-1", "unscheduled_arm_1", None, 1, xu_date="2026-03-20", xu_after_base="2026-03-20",
              xu_weight="73", xu_code="U-1", xu_last="1", xe_unsched_complete="2"),
          row("XE-1", "unscheduled_arm_1", None, 2, xu_date="2026-03-25", xu_after_base="2026-03-25",
              xu_weight="73", xu_code="U-2", xu_reason="repeat", xu_last="1", xe_unsched_complete="2"),
          row("XE-1", "followup_arm_1", xc_complete="1", xc_visits="4", xc_total_vol="30",
              xc_mean_weight="73", xc_kits="4", xc_min_hb="11", xc_any_pos="0", xc_all_neg="1",
              xe_close_complete="2")]
    # XE-2: each rule broken once (see EXPECTED_VIOLATIONS).
    R += [enrol("XE-2", ae="1", code="PX-0002"),
          visit("XE-2", "baseline_arm_1", "2026-01-01", "70", "37.0", "170", "K-100", dose="30", hr="90",
                xv_code_confirm="PX-9999", xv_window="2026-09-01"),
          visit("XE-2", "visit_1_arm_1", "2026-02-10", "65", "36.0", "160", "K-100", dose="30", hr="100"),
          spec("XE-2", "baseline_arm_1", 1, "S-100", "1", "2026-01-15", "30", "10", "5"),
          spec("XE-2", "baseline_arm_1", 2, "S-100", "2", "2026-01-14", "30", "15", "5"),
          spec("XE-2", "baseline_arm_1", 3, "S-101", "3", "2026-01-16", "30", "10", "5"),
          row("XE-2", "visit_1_arm_1", "xr_result", 1, xr_spec_id="S-999", xr_value="1",
              xr_date="2026-01-01", xr_time="2026-01-20 08:00", xr_result_complete="2"),
          row("XE-2", "followup_arm_1", xc_complete="1", xc_visits="9", xc_total_vol="1",
              xc_mean_weight="1", xc_kits="9", xc_min_hb="1", xc_any_pos="1", xc_all_neg="1",
              xe_close_complete="2")]
    # XE-3: gaps, blank rows and missing events.
    R += [enrol("XE-3", code="PX-0003"),
          spec("XE-3", "baseline_arm_1", 1, "S-301", "1", "2026-01-15", "5", "12", "5"),
          spec("XE-3", "baseline_arm_1", 3, "S-303", "1", "2026-01-10", "5", "12", "5"),
          # all-blank repeat row kept alive only by its completion marker
          row("XE-3", "baseline_arm_1", "xr_specimen", 4, xr_specimen_complete="0"),
          row("XE-3", "unscheduled_arm_1", None, 2, xu_date="2026-03-01", xu_code="U-9",
              xe_unsched_complete="2")]
    # XE-4: same record in both arms; arm-2 rules read arm-1 Baseline.
    R += [enrol("XE-4", code="PX-0004"),
          visit("XE-4", "baseline_arm_1", "2026-01-15", "80", "36.5", "175", "K-400"),
          enrol("XE-4", ev="sub_enrol_arm_2", code="PX-0004", weight="78"),
          visit("XE-4", "sub_visit_arm_2", "2026-02-15", "81", "36.5", "175", "K-401",
                xv_arm_ref="79"),
          row("XE-4", "sub_visit_arm_2", xsub_weight="79", xsub_prev="78", xe_sub_complete="2")]
    return R


# Which rule each XE-2 / XE-4 row is meant to break. The local harness and the
# live scan are both compared against this list; a difference is a finding.
EXPECTED_VIOLATIONS = [
    ("XE-2", "baseline_arm_1", 1, "xv_date", "before consent"),
    ("XE-2", "visit_1_arm_1", 1, "xv_weight", "65 < previous 70"),
    ("XE-2", "baseline_arm_1", 1, "xv_temp", "37.0 > next 36.0"),
    ("XE-2", "visit_1_arm_1", 1, "xv_height", "160 < first 170"),
    ("XE-2", "baseline_arm_1", 1, "xv_code_confirm", "PX-9999 vs PX-0002"),
    ("XE-2", "baseline_arm_1", 1, "xv_window", "234 days"),
    ("XE-2", "baseline_arm_1", 1, "xv_dose", "sum 60 > 50"),
    ("XE-2", "visit_1_arm_1", 1, "xv_dose", "sum 60 > 50"),
    ("XE-2", "visit_1_arm_1", 1, "xv_hr", "100 > max other 90"),
    ("XE-2", "baseline_arm_1", 1, "xv_kit", "K-100 twice"),
    ("XE-2", "visit_1_arm_1", 1, "xv_kit", "K-100 twice"),
    ("XE-2", "baseline_arm_1", 1, "xv_ae_note", "required: enrol AE=1"),
    ("XE-2", "visit_1_arm_1", 1, "xv_ae_note", "required: enrol AE=1"),
    ("XE-2", "baseline_arm_1", 1, "xs_id", "S-100 twice (unique)"),
    ("XE-2", "baseline_arm_1", 2, "xs_id", "S-100 twice (unique)"),
    ("XE-2", "baseline_arm_1", 1, "xs_type", "3 distinct types"),
    ("XE-2", "baseline_arm_1", 2, "xs_type", "3 distinct types"),
    ("XE-2", "baseline_arm_1", 3, "xs_type", "3 distinct types"),
    ("XE-2", "baseline_arm_1", 2, "xs_date", "01-14 < previous 01-15"),
    ("XE-2", "baseline_arm_1", 1, "xs_volume", "sum 90 > 50"),
    ("XE-2", "baseline_arm_1", 2, "xs_volume", "sum 90 > 50"),
    ("XE-2", "baseline_arm_1", 3, "xs_volume", "sum 90 > 50"),
    ("XE-2", "baseline_arm_1", 2, "xs_hb", "15 > mean(10,10)"),
    ("XE-2", "visit_1_arm_1", 1, "xr_spec_id", "S-999 matches nothing"),
    ("XE-2", "visit_1_arm_1", 1, "xr_absent_note", "required: no match"),
    ("XE-2", "visit_1_arm_1", 1, "xr_time", "120 h > 48"),
    ("XE-2", "followup_arm_1", 1, "xc_visits", "9 vs 2"),
    ("XE-2", "followup_arm_1", 1, "xc_total_vol", "1 vs 90"),
    ("XE-2", "followup_arm_1", 1, "xc_mean_weight", "1 vs 67.5"),
    ("XE-2", "followup_arm_1", 1, "xc_kits", "9 vs 1"),
    ("XE-2", "followup_arm_1", 1, "xc_min_hb", "1 vs 10"),
    ("XE-2", "followup_arm_1", 1, "xc_any_pos", "no positive"),
    ("XE-3", "baseline_arm_1", 1, "xs_next_cmp", "instance 2 missing: UNRESOLVED, not a violation"),
    ("XE-3", "baseline_arm_1", 3, "xs_date", "instance 2 missing: UNRESOLVED, not a violation"),
    ("XE-3", "unscheduled_arm_1", 2, "xu_date", "instance 1 missing: UNRESOLVED"),
    ("XE-4", "sub_visit_arm_2", 1, "xsub_weight", "79 < arm-1 baseline 80"),
    ("XE-4", "sub_visit_arm_2", 1, "xv_arm_ref", "79 < arm-1 baseline 80"),
]


def scale_rows(n):
    """One record with n Baseline specimen instances: page payload and audit cost."""
    R = [enrol("XE-SCALE", code="PX-SCALE")]
    for i in range(1, n + 1):
        R.append(spec("XE-SCALE", "baseline_arm_1", i, "SC-%05d" % i, str(1 + i % 2),
                      "2026-01-15", "0.01", str(10 + (i % 7)), "5"))
    return R


def write_csv(name, header, rows):
    path = os.path.join(OUT, name)
    with open(path, "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=header, extrasaction="raise")
        w.writeheader()
        for r in rows:
            w.writerow({k: r.get(k, "") for k in header})
    return path


def seed_header(rows):
    seen = ["record_id", "redcap_event_name", "redcap_repeat_instrument", "redcap_repeat_instance"]
    for r in rows:
        for k in r:
            if k not in seen:
                seen.append(k)
    return seen


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--scale", type=int, default=1500, help="instances in seed_scale.csv")
    a = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    write_csv("arms.csv", ["arm_num", "name"], [{"arm_num": n, "name": l} for n, l in ARMS])
    write_csv("events.csv", ["event_name", "arm_num", "unique_event_name", "day_offset"],
              [{"event_name": l, "arm_num": a_, "unique_event_name": u, "day_offset": d}
               for u, l, a_, d in EVENTS])
    write_csv("mappings.csv", ["arm_num", "unique_event_name", "form"],
              [{"arm_num": next(e[2] for e in EVENTS if e[0] == ev), "unique_event_name": ev, "form": f}
               for ev, forms in DESIGNATIONS.items() for f in forms])
    write_csv("repeating.csv", ["event_name", "form_name"],
              [{"event_name": ev, "form_name": f or ""} for ev, f in REPEATING])
    write_csv("dictionary_additions.csv", COLUMNS, dictionary_rows())
    seed = seed_rows()
    write_csv("seed_data.csv", seed_header(seed), seed)
    sc = scale_rows(a.scale)
    write_csv("seed_scale.csv", seed_header(sc), sc)
    write_csv("expected_violations.csv", ["record", "event", "instance", "field", "why"],
              [dict(zip(["record", "event", "instance", "field", "why"], v)) for v in EXPECTED_VIOLATIONS])
    n_fields = sum(len(FORMS[f]) for f in FORM_ORDER)
    print("forms=%d fields=%d seed_rows=%d scale_rows=%d -> %s" % (len(FORM_ORDER), n_fields, len(seed), len(sc), OUT))


if __name__ == "__main__":
    main()
