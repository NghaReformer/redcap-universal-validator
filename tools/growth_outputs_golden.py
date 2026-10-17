"""Writes tests/growth_outputs_golden.json: the @UVRANGE values that are not
z-scores (a measurement's percentile, the measurement at a z-score, the
measurement at a percentile), worked out with mpmath at 50 digits from the
bundled LMS tables, so GrowthReference and its engine twins are checked
against arithmetic that is not their own.

Usage: python tools/growth_outputs_golden.py tests/growth_outputs_golden.json [points per kind per reference]

Every point sits on a table row (x is a row key divided by the table's
"scale"), so each lookup ("round", "floor", "linear") reads that row as it is.
The WHO method is followed: z = ((y/M)^L - 1) / (S*L), or ln(y/M)/S when L is
0, and for "who-restricted" references the distance beyond +-3 SD is measured
in units of the distance between 2 and 3 SD. A percentile is 100 times the
normal distribution function of the unrounded z, rounded half away from zero
and kept inside the scale (0.1 to 99.9 at one decimal); a measurement is
rounded half away from zero. A point within 1e-9 of a rounding tie is left
out: there the last bit of the double arithmetic decides, in any code.

The seed is fixed, so a re-run gives the same file. Needs mpmath.
"""
import json
import math
import random
import sys
from pathlib import Path

import mpmath as mp

mp.mp.dps = 50
ROOT = Path(__file__).resolve().parent.parent
REF_DIR = ROOT / "data" / "references"
Z_PARAMS = ["-4", "-3.5", "-3", "-2.5", "-2", "-1", "-0.5", "0", "0.67", "1", "1.5", "2", "2.5", "3", "3.25", "4"]
P_PARAMS = ["0.1", "0.4", "1", "2.28", "3", "5", "10", "15", "25", "50", "75", "85", "90", "95", "97", "97.72", "99", "99.6", "99.9"]


def sd_value(lms, z, restricted):
    """The measurement at z-score z (exact), or None when none above 0 scores it."""
    l, m, s = (mp.mpf(v) for v in lms)

    def sd(k):
        if l == 0:
            return m * mp.e ** (s * k)
        b = 1 + l * s * k
        if b <= 0:
            return None
        return m * b ** (1 / l)

    z = mp.mpf(z)
    if restricted and z > 3:
        a, b = sd(3), sd(2)
        if a is None or b is None:
            return None
        y = a + (z - 3) * (a - b)
    elif restricted and z < -3:
        a, b = sd(-3), sd(-2)
        if a is None or b is None:
            return None
        y = a + (z + 3) * (b - a)
    else:
        y = sd(z)
    return y if (y is not None and y > 0) else None


def z_of(lms, y, restricted):
    l, m, s = (mp.mpf(v) for v in lms)
    y = mp.mpf(y)
    z = mp.log(y / m) / s if l == 0 else ((y / m) ** l - 1) / (s * l)
    if restricted and z > 3:
        a, b = sd_value(lms, 3, False), sd_value(lms, 2, False)
        z = 3 + (y - a) / (a - b)
    elif restricted and z < -3:
        a, b = sd_value(lms, -3, False), sd_value(lms, -2, False)
        z = -3 + (y - a) / (b - a)
    return z


def rounded(units):
    """floor(units + 0.5) as an int, or None within 1e-9 of a tie."""
    frac = units - mp.floor(units)
    if abs(frac - mp.mpf("0.5")) < mp.mpf("1e-9"):
        return None
    return int(mp.floor(units + mp.mpf("0.5")))


def units_text(n, d):
    if d == 0:
        return str(n)
    p = 10 ** d
    return str(n // p) + "." + str(n % p).rjust(d, "0")


def main():
    out = sys.argv[1]
    per = int(sys.argv[2]) if len(sys.argv) > 2 else 8
    rng = random.Random(20261016)
    index = json.loads((REF_DIR / "index.json").read_text(encoding="utf-8"))
    points = []
    for rid, e in index["references"].items():
        t = json.loads((REF_DIR / e["file"]).read_text(encoding="utf-8"))
        restricted = e["adjust"] == "who-restricted"
        for sex in ("male", "female"):
            rows = t[sex]
            for kind in ("percentile", "valueAtZ", "valueAtPercentile"):
                made = 0
                tries = 0
                while made < per and tries < per * 20:
                    tries += 1
                    k = rng.randrange(len(rows))
                    lms = rows[k]
                    key = t["first"] + k
                    x = key / t["scale"]
                    if kind == "percentile":
                        d = rng.choice([0, 1, 1, 1, 2, 3])
                        y = "%.2f" % (lms[1] * rng.uniform(0.55, 1.6))
                        cum = mp.ncdf(z_of(lms, y, restricted))
                        top = 10 ** (d + 2)
                        n = rounded(cum * top)
                        if n is None:
                            continue
                        want = units_text(max(1, min(top - 1, n)), d)
                        points.append({"ref": rid, "sex": sex, "x": x, "kind": kind, "arg": y, "places": d, "want": want})
                    else:
                        d = rng.choice([0, 1, 1, 2, 2, 3])
                        arg = rng.choice(Z_PARAMS if kind == "valueAtZ" else P_PARAMS)
                        if kind == "valueAtZ":
                            z = mp.mpf(arg)
                        else:
                            z = mp.sqrt(2) * mp.erfinv(2 * mp.mpf(arg) / 100 - 1)
                        y = sd_value(lms, z, restricted)
                        if y is None:
                            continue
                        n = rounded(y * 10 ** d)
                        if n is None:
                            continue
                        points.append({"ref": rid, "sex": sex, "x": x, "kind": kind, "arg": arg, "places": d,
                                       "want": units_text(n, d)})
                    made += 1
    doc = {
        "_comment": "Percentiles and measurements at a z-score or percentile on the bundled tables, worked out with "
                    "mpmath " + mp.__version__ + " at 50 digits by tools/growth_outputs_golden.py.",
        "points": points,
    }
    Path(out).write_text(json.dumps(doc, indent=1) + "\n", encoding="utf-8")
    print("wrote %d points to %s" % (len(points), out))


if __name__ == "__main__":
    main()
