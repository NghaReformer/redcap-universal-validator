# Writes tests/who_golden.json: z-scores computed by WHO's own R code, which the
# module's PHP and JavaScript must reproduce to two decimals.
#
# Usage: Rscript tools/who_golden.R <who dir> tests/who_golden.json [points per indicator per sex]
#
# <who dir> holds files from the two WHO packages (GPL-3, see
# data/references/who/README.md), copied unchanged:
#   anthro_R/      the R/ folder of github.com/WorldHealthOrganization/anthro
#                  at commit b776d8a12b1c97369c748b561159fd2ec4f4db58
#   anthro/        data-raw/growthstandards/*.txt of that commit
#   anthroplus_R/  zscores.R from the R/ folder of
#                  github.com/WorldHealthOrganization/anthroplus
#                  at commit 7cfcdb39026e9a55de55732bc3cf14c82261bcf7
#   anthroplus/    data-raw/growthstandards/*.txt of that commit
#
# The seed is fixed, so a re-run with the default 120 points gives the same file.
# A point within 1e-7 of a rounding tie is left out: there the last bit of pow()
# decides the second decimal, in WHO's code as much as in the module's.
args <- commandArgs(trailingOnly = TRUE)
if (length(args) < 2) stop("usage: Rscript tools/who_golden.R <who dir> <out.json> [n]")
dl <- args[1]; out <- args[2]
n <- if (length(args) >= 3) as.integer(args[3]) else 120L
for (f in c("anthro-package.R", "assertions.R", "utils.R", "z-score-helper.R",
            "z-score-weight-for-age.R", "z-score-length-for-age.R", "z-score-bmi-for-age.R",
            "z-score-head-circumference-for-age.R", "z-score-arm-circumference-for-age.R",
            "z-score-subscapular-skinfold-for-age.R", "z-score-triceps-skinfold-for-age.R",
            "z-score-weight-for-lenhei.R")) {
  sys.source(file.path(dl, "anthro_R", f), envir = globalenv())
}
anthro_api_compute_zscore <- compute_zscore
anthro_api_compute_zscore_adjusted <- compute_zscore_adjusted
sys.source(file.path(dl, "anthroplus_R", "zscores.R"), envir = globalenv())

std <- function(dir, name, int_age = TRUE) {
  d <- read.delim(file.path(dl, dir, paste0(name, ".txt")), stringsAsFactors = FALSE)
  d$sex <- as.integer(d$sex)
  if (int_age && "age" %in% names(d)) d$age <- as.integer(d$age)
  d
}
for (nm in c("weianthro", "lenanthro", "bmianthro", "hcanthro", "acanthro", "tsanthro", "ssanthro")) {
  assign(paste0("growthstandards_", nm), std("anthro", nm))
}
growthstandards_wflanthro <- std("anthro", "wflanthro", int_age = FALSE)
growthstandards_wfhanthro <- std("anthro", "wfhanthro", int_age = FALSE)
wfa_growth_standards <- std("anthroplus", "wfawho2007")
hfa_growth_standards <- std("anthroplus", "hfawho2007")
bfa_growth_standards <- std("anthroplus", "bfawho2007")

set.seed(20261010)
rows <- list()
add <- function(ref, sex, x, y, z_raw) {
  z <- round(z_raw, digits = 2L)
  for (i in seq_along(x)) {
    zr <- z_raw[i]
    tie <- !is.na(zr) && abs((abs(zr) * 100) %% 1 - 0.5) < 1e-7
    if (tie) next
    rows[[length(rows) + 1L]] <<- sprintf('{"ref":"%s","sex":%d,"x":"%s","y":"%s","z":%s}',
      ref, sex[i], x[i], y[i], if (is.na(z[i])) "null" else sprintf('"%.2f"', z[i]))
  }
}
# typed measure from a z drawn across the whole table, with the given decimals
measure_at <- function(tab, sex, key, keycol, zs, digits) {
  idx <- match(paste(sex, key), paste(tab$sex, tab[[keycol]]))
  l <- tab$l[idx]; m <- tab$m[idx]; s <- tab$s[idx]
  base <- ifelse(is.na(m), 10, m * pmax(1 + l * s * zs, 0.05)^(1 / l))
  base[!is.finite(base)] <- 1
  sprintf(paste0("%.", digits, "f"), pmax(round(base, digits), 10^-digits))
}
# The indicator functions round internally; recompute unrounded z the same way
# (same merge, same formula) so ties can be dropped, and check it rounds to theirs.
unrounded <- function(zfun, tab) function(y, days, months, sex) {
  days[days < 0] <- NA_real_
  d <- as.integer(round_up(days))
  idx <- match(paste(sex, d), paste(tab$sex, tab$age))
  y[y <= 0] <- NA_real_
  z <- zfun(y, tab$m[idx], tab$l[idx], tab$s[idx])
  min_age <- min(tab$age)
  z[!(d >= min_age & d <= 1856 & months < 60)] <- NA_real_
  z
}
anthro <- list(
  list("who-wfa", "weianthro", compute_zscore_adjusted, 2, function(y, d, m, s) anthro_zscore_weight_for_age(y, d, m, s, rep("n", length(y)))$zwei),
  list("who-lhfa", "lenanthro", compute_zscore, 1, function(y, d, m, s) anthro_zscore_length_for_age(y, d, m, s)$zlen),
  list("who-bfa", "bmianthro", compute_zscore_adjusted, 2, function(y, d, m, s) anthro_zscore_bmi_for_age(y, d, m, s, rep("n", length(y)))$zbmi),
  list("who-hcfa", "hcanthro", compute_zscore, 1, function(y, d, m, s) anthro_zscore_head_circumference_for_age(y, d, m, s)$zhc),
  list("who-acfa", "acanthro", compute_zscore_adjusted, 1, function(y, d, m, s) anthro_zscore_arm_circumference_for_age(y, d, m, s)$zac),
  list("who-ssfa", "ssanthro", compute_zscore_adjusted, 1, function(y, d, m, s) anthro_zscore_subscapular_skinfold_for_age(y, d, m, s)$zss),
  list("who-tsfa", "tsanthro", compute_zscore_adjusted, 1, function(y, d, m, s) anthro_zscore_triceps_skinfold_for_age(y, d, m, s)$zts)
)
mismatch <- 0L
for (a in anthro) {
  tab <- std("anthro", a[[2]])
  uf <- unrounded(a[[3]], tab)
  for (sx in 1:2) {
    days <- sample(-5:1840, n, replace = TRUE)
    zs <- runif(n, -7, 7)
    y <- measure_at(tab, sx, pmin(pmax(days, min(tab$age)), 1826), "age", zs, a[[4]])
    official <- a[[5]](as.numeric(y), days, days / ANTHRO_DAYS_OF_MONTH, rep(sx, n))
    raw <- uf(as.numeric(y), days, days / ANTHRO_DAYS_OF_MONTH, rep(sx, n))
    chk <- round(raw, 2L)
    mismatch <- mismatch + sum(xor(is.na(chk), is.na(official)) | (!is.na(chk) & !is.na(official) & chk != official))
    add(a[[1]], rep(sx, n), as.character(days), y, ifelse(is.na(official), NA_real_, raw))
  }
}
# weight-for-length / -height: age passed so anthro picks the table (< 731 days = length)
for (spec in list(list("who-wfl", "wflanthro", "length", 100, 40, 115), list("who-wfh", "wfhanthro", "height", 1000, 60, 125))) {
  tab <- std("anthro", spec[[2]], int_age = FALSE)
  for (sx in 1:2) {
    lh <- sprintf("%.1f", runif(n, spec[[5]], spec[[6]]))
    lh2 <- ifelse(runif(n) < 0.3, sprintf("%.2f", as.numeric(lh) + runif(n, 0, 0.09)), lh)
    key <- trunc(as.numeric(lh2) * 10) / 10
    zs <- runif(n, -7, 7)
    y <- measure_at(tab, sx, pmin(pmax(key, min(tab[[spec[[3]]]])), max(tab[[spec[[3]]]])), spec[[3]], zs, 2)
    official <- anthro_zscore_weight_for_lenhei(as.numeric(y), as.numeric(lh2), rep(NA_character_, n),
      rep(spec[[4]], n), rep(spec[[4]] / ANTHRO_DAYS_OF_MONTH, n), rep(sx, n), rep("n", n))$zwfl
    # unrounded twin of the interpolation, for tie detection
    x <- as.numeric(lh2); lo <- trunc(x * 10) / 10; up <- trunc(x * 10 + 1) / 10; df <- (x - lo) / 0.1
    i1 <- match(paste(sx, lo), paste(tab$sex, tab[[spec[[3]]]])); i2 <- match(paste(sx, up), paste(tab$sex, tab[[spec[[3]]]]))
    ip <- function(c) ifelse(df > 0, tab[[c]][i1] + df * (tab[[c]][i2] - tab[[c]][i1]), tab[[c]][i1])
    raw <- compute_zscore_adjusted(as.numeric(y), ip("m"), ip("l"), ip("s"))
    chk <- round(raw, 2L)
    mismatch <- mismatch + sum(!is.na(official) & (is.na(chk) | chk != official))
    add(spec[[1]], rep(sx, n), lh2, y, ifelse(is.na(official), NA_real_, raw))
  }
}
# WHO 2007 (5-19 y): age in months = days / 30.4375, interpolated between months
plus <- list(
  list("who2007-wfa", wfa_growth_standards, function(s, m, y) zscore_weight_for_age(s, m, rep("n", length(y)), y), 2),
  list("who2007-hfa", hfa_growth_standards, function(s, m, y) zscore_height_for_age(s, m, y), 1),
  list("who2007-bfa", bfa_growth_standards, function(s, m, y) zscore_bmi_for_age(s, m, rep("n", length(y)), y), 2)
)
for (p in plus) {
  tab <- p[[2]]
  for (sx in 1:2) {
    days <- sample(1790:7000, n, replace = TRUE)
    months <- days / ANTHRO_DAYS_OF_MONTH
    zs <- runif(n, -7, 7)
    y <- measure_at(tab, sx, pmin(pmax(trunc(months), 60), max(tab$age)), "age", zs, p[[4]])
    raw <- p[[3]](rep(sx, n), months, as.numeric(y))
    add(p[[1]], rep(sx, n), as.character(days), y, raw)
  }
}
comment <- c(
  "z-scores computed by WHO's own R code: anthro (commit b776d8a1) for the 2006",
  "standards and anthroplus (commit 7cfcdb39) for the 2007 reference, run by",
  "tools/who_golden.R. x is the age in days (WHO's code turns it into months",
  "for who2007-*) or the length/height in cm; y the measurement; z WHO's value",
  "rounded to 2 decimals (null where WHO gives none). Points within 1e-7 of a rounding tie are left out.",
  "tests/growth_php.php and tests/growth_js.cjs must reproduce every point.")
con <- file(out, open = "wb")   # "\n" line ends on every platform
writeLines(c("{", '  "_comment": [', paste0('    "', comment, '"', collapse = ",\n"), "  ],", '  "points": [',
             paste0("    ", unlist(rows), collapse = ",\n"), "  ]", "}"), con)
close(con)
cat("points:", length(rows), " anthro rounding mismatches vs official:", mismatch, "\n")
if (mismatch > 0L) quit(status = 1L)
