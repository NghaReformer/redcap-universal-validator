#!/usr/bin/env python3
"""
gen_tb_crossform_dictionary.py — build the REDCap data dictionary for the
cross-form @UVASSERT test bed, modelled on a TB treatment cohort.

Four instruments forming a real referral chain, so every kind of cross-form
relationship is exercised:

    tb_screening  ->  tb_diagnosis  ->  tb_treatment  ->  tb_outcome

Each downstream form carries @UVASSERT rules that reference fields on an
UPSTREAM form. Field types are deliberately mixed — ISO dates, decimals,
integers, free text, coded dropdowns, yes/no — because the condition dialect
compares numerically when both sides look numeric and as strings otherwise,
and ISO dates only sort correctly because of that string path.

Every form also carries a SAME-FORM control pair. Those always validate live,
whatever the module version or the viewer's rights, so they are the baseline to
compare the cross-form fields against.

Run:  python docs/testbed/gen_tb_crossform_dictionary.py
Out:  docs/testbed/tb_crossform_dictionary.csv
"""
import csv
import os

COLUMNS = [
    "Variable / Field Name", "Form Name", "Section Header", "Field Type",
    "Field Label", "Choices, Calculations, OR Slider Labels", "Field Note",
    "Text Validation Type OR Show Slider Number", "Text Validation Min",
    "Text Validation Max", "Identifier?", "Branching Logic (Show field only if...)",
    "Required Field?", "Custom Alignment", "Question Number (surveys only)",
    "Matrix Group Name", "Matrix Ranking?", "Field Annotation",
]


def f(name, form, ftype, label, choices="", note="", valid="", vmin="", vmax="",
      section="", annotation=""):
    return {
        "Variable / Field Name": name, "Form Name": form, "Section Header": section,
        "Field Type": ftype, "Field Label": label,
        "Choices, Calculations, OR Slider Labels": choices, "Field Note": note,
        "Text Validation Type OR Show Slider Number": valid,
        "Text Validation Min": vmin, "Text Validation Max": vmax,
        "Identifier?": "", "Branching Logic (Show field only if...)": "",
        "Required Field?": "", "Custom Alignment": "",
        "Question Number (surveys only)": "", "Matrix Group Name": "",
        "Matrix Ranking?": "", "Field Annotation": annotation,
    }


def assert_tag(cond, message, block="hard", when=None):
    """@UVASSERT as compact JSON — the CSV writer handles quote escaping."""
    parts = ['"assert":"%s"' % cond]
    if when:
        parts.append('"when":"%s"' % when)
    parts.append('"message":"%s"' % message)
    parts.append('"blockSave":"%s"' % block)
    return "@UVASSERT={%s}" % ",".join(parts)


SITES = "1, Bamenda Regional Hospital | 2, Douala Laquintinie | 3, Yaounde Jamot"
rows = []

# ---------------------------------------------------------------- screening --
F = "tb_screening"
rows += [
    f("record_id", F, "text", "Record ID",
      note="Auto-assigned. Enrol on this form FIRST — every other form compares against it.",
      section="Baseline screening (the reference form — no rules of its own)"),
    f("scr_participant_id", F, "text", "Participant ID (study code)",
      note="e.g. TB-2026-0042. The Diagnosis form makes you retype this and must match."),
    f("scr_screen_date", F, "text", "Screening date", valid="date_ymd",
      note="The first date in the chain. Every later date must be on or after this one."),
    f("scr_age", F, "text", "Age (years)", valid="integer", vmin="0", vmax="120",
      note="Whole years. The Diagnosis form re-asks this and must match exactly (numeric)."),
    f("scr_sex", F, "radio", "Sex", choices="1, Male | 2, Female"),
    f("scr_weight_kg", F, "text", "Baseline weight (kg)", valid="number", vmin="1", vmax="300",
      note="Decimal allowed, e.g. 54.5. The Outcome form compares final weight against this."),
    f("scr_hiv_status", F, "radio", "HIV status",
      choices="1, Positive | 2, Negative | 3, Unknown",
      note="Set this to Positive to switch ON the conditional rules on Diagnosis and Treatment."),
    f("scr_site", F, "dropdown", "Screening site", choices=SITES,
      note="Coded value. Treatment site must match this — shows that CODED fields compare on the CODE."),
    f("scr_max_daily_dose", F, "text", "Protocol maximum daily dose (mg)", valid="number",
      vmin="0", vmax="5000",
      note="e.g. 600. The Treatment form refuses a dose above this — a numeric cross-form ceiling."),
    f("scr_consent", F, "yesno", "Consent given?",
      note="Yes switches on the conditional (when-gated) rule on the Treatment form."),
    # same-form control
    f("scr_ctrl_min", F, "text", "CONTROL — local minimum", valid="number",
      section="Same-form control (always live — your baseline for comparison)",
      note="Type any number, e.g. 10. The field below is checked against THIS one, on the same form."),
    f("scr_ctrl_val", F, "text", "CONTROL — must be >= the local minimum", valid="number",
      note="Both fields are on THIS form, so this check is always live as you type, "
           "whatever the module version or your rights. Compare its behaviour with the cross-form fields.",
      annotation=assert_tag("[scr_ctrl_val]>=[scr_ctrl_min]",
                            "Below the local minimum (same-form control)")),
]

# ---------------------------------------------------------------- diagnosis --
F = "tb_diagnosis"
rows += [
    f("dx_participant_id_confirm", F, "text", "Re-enter the Participant ID",
      section="Identity check — TEXT equality across forms",
      note="Must equal scr_participant_id on the Screening form. Text compares exactly "
           "(case and punctuation included).",
      annotation=assert_tag("[dx_participant_id_confirm]=[scr_participant_id]",
                            "Does not match the Participant ID recorded at screening")),
    f("dx_age_confirm", F, "text", "Re-enter age (years)", valid="integer", vmin="0", vmax="120",
      note="Must equal scr_age. Both sides look numeric, so this is a NUMERIC comparison — "
           "007 and 7 are equal here, unlike the text field above.",
      annotation=assert_tag("[dx_age_confirm]=[scr_age]",
                            "Does not match the age recorded at screening")),
    f("dx_specimen_date", F, "text", "Specimen collection date", valid="date_ymd",
      section="Date chain — must not run backwards",
      note="Must be on or after the SCREENING date (a different form).",
      annotation=assert_tag("[dx_specimen_date]>=[scr_screen_date]",
                            "Specimen cannot be collected before the screening date")),
    f("dx_result_date", F, "text", "Result date", valid="date_ymd",
      note="Must be on or after the specimen date — both on THIS form, so this one is "
           "always live. Contrast it with the field above.",
      annotation=assert_tag("[dx_result_date]>=[dx_specimen_date]",
                            "Result cannot predate the specimen collection")),
    f("dx_xpert_result", F, "radio", "Xpert MTB/RIF result",
      choices="1, MTB detected | 2, MTB not detected | 3, Error / Invalid",
      section="Laboratory"),
    f("dx_smear_grade", F, "dropdown", "Smear microscopy grade",
      choices="0, Negative | 1, Scanty | 2, 1+ | 3, 2+ | 4, 3+"),
    f("dx_cd4", F, "text", "CD4 count (cells/mm3)", valid="integer", vmin="0", vmax="5000",
      section="Conditional rule — only enforced for HIV-positive participants",
      note="Only checked when scr_hiv_status = Positive on the Screening form. "
           "A CD4 of 0 is refused as implausible while that gate is on; set HIV status to "
           "Negative and the rule goes inert.",
      annotation=assert_tag("[dx_cd4]>'0'", "A CD4 count is implausible for an HIV-positive participant",
                            block="off", when="[scr_hiv_status]='1'")),
]

# ---------------------------------------------------------------- treatment --
F = "tb_treatment"
rows += [
    f("tx_start_date", F, "text", "Treatment start date", valid="date_ymd",
      section="Date chain — spans TWO forms back",
      note="Must be on or after the RESULT date on the Diagnosis form.",
      annotation=assert_tag("[tx_start_date]>=[dx_result_date]",
                            "Treatment cannot start before the diagnosis result date")),
    f("tx_regimen", F, "dropdown", "Regimen",
      choices="1, 2HRZE/4HR (new) | 2, Retreatment | 3, MDR-TB regimen"),
    f("tx_daily_dose_mg", F, "text", "Daily dose (mg)", valid="number", vmin="0", vmax="5000",
      section="Numeric ceiling — set on the Screening form",
      note="Must not exceed scr_max_daily_dose, recorded two forms back. Decimal comparison.",
      annotation=assert_tag("[tx_daily_dose_mg]<=[scr_max_daily_dose]",
                            "Daily dose exceeds the protocol maximum recorded at screening")),
    f("tx_site", F, "dropdown", "Treatment site", choices=SITES,
      section="Coded value — compares on the CODE, not the label",
      note="Must match the screening site. Codes are compared (1/2/3), so relabelling a "
           "choice does not change the rule.",
      annotation=assert_tag("[tx_site]=[scr_site]",
                            "Treatment site differs from the screening site", block="off")),
    f("tx_art_started", F, "yesno", "ART started?",
      section="Conditional rule — gated on a field two forms back",
      note="Only enforced once consent is Yes AND HIV status is Positive on the Screening "
           "form. Flip either and the rule goes inert.",
      annotation=assert_tag("[tx_art_started]='1'",
                            "ART must be started for a consented HIV-positive participant",
                            block="off", when="[scr_consent]='1' and [scr_hiv_status]='1'")),
    # same-form control
    f("tx_ctrl_floor", F, "text", "CONTROL — local floor", valid="number",
      section="Same-form control (always live)",
      note="Type any number. The field below is checked against THIS one, on the same form."),
    f("tx_ctrl_val", F, "text", "CONTROL — must be >= the local floor", valid="number",
      note="Always live as you type — the comparison never leaves this page.",
      annotation=assert_tag("[tx_ctrl_val]>=[tx_ctrl_floor]",
                            "Below the local floor (same-form control)")),
]

# ------------------------------------------------------------------ outcome --
F = "tb_outcome"
rows += [
    f("out_end_date", F, "text", "Treatment end date", valid="date_ymd",
      section="Final link in the date chain",
      note="Must be on or after the treatment start date on the Treatment form.",
      annotation=assert_tag("[out_end_date]>=[tx_start_date]",
                            "Treatment cannot end before it started")),
    f("out_result", F, "dropdown", "Treatment outcome",
      choices="1, Cured | 2, Treatment completed | 3, Treatment failed | 4, Died | 5, Lost to follow-up"),
    f("out_final_weight_kg", F, "text", "Final weight (kg)", valid="number", vmin="1", vmax="300",
      section="Numeric comparison against the baseline, only for a cure",
      note="Only checked when the outcome is Cured: a cured participant is not expected to "
           "weigh less than at screening. Warning only — it never blocks the save.",
      annotation=assert_tag("[out_final_weight_kg]>=[scr_weight_kg]",
                            "A cured participant weighs less than at screening — please confirm",
                            block="off", when="[out_result]='1'")),
    f("out_id_confirm", F, "text", "Re-enter the Participant ID (final check)",
      note="Must equal the Participant ID from Screening — the same text check as on "
           "Diagnosis, but now three forms away.",
      annotation=assert_tag("[out_id_confirm]=[scr_participant_id]",
                            "Does not match the Participant ID recorded at screening")),
]

out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tb_crossform_dictionary.csv")
with open(out, "w", newline="", encoding="utf-8") as fh:
    w = csv.DictWriter(fh, fieldnames=COLUMNS, quoting=csv.QUOTE_ALL)
    w.writeheader()
    for r in rows:
        w.writerow(r)

forms = []
for r in rows:
    if r["Form Name"] not in forms:
        forms.append(r["Form Name"])
tagged = sum(1 for r in rows if r["Field Annotation"])
print("wrote %s" % out)
print("  %d fields across %d instruments: %s" % (len(rows), len(forms), ", ".join(forms)))
print("  %d @UVASSERT rules (%d cross-form, %d same-form controls)"
      % (tagged,
         sum(1 for r in rows if r["Field Annotation"] and "ctrl" not in r["Variable / Field Name"]
             and "dx_result_date" not in r["Variable / Field Name"]),
         sum(1 for r in rows if r["Field Annotation"] and "ctrl" in r["Variable / Field Name"])))
