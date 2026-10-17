# Writes tests/growth_normal_golden.json: R's pnorm() and qnorm(), which
# GrowthReference::normalCdf / normalQuantile and their engine twins port step
# for step (Cody's algorithm 715 and Wichura's AS241).
#
# Usage: Rscript tools/normal_golden.R tests/growth_normal_golden.json
#
# The points: a grid of z-scores from -9 to 9 by 0.01, the edges between the
# algorithm's branches, values out to the far tails, and random z-scores; for
# qnorm a grid of probabilities from 0.001 to 0.999 by 0.001, the percentiles
# growth charts draw, the tails and random probabilities. The seed is fixed,
# so a re-run gives the same file. Each value is written with 17 significant
# digits, enough to rebuild the double.
args <- commandArgs(trailingOnly = TRUE)
if (length(args) < 1) stop("usage: Rscript tools/normal_golden.R <out.json>")
out <- args[1]
set.seed(20261016)

z <- c(seq(-9, 9, by = 0.01),
       0, 1e-17, -1e-17, 1.1102230246251565e-16,
       0.67448975, -0.67448975, 0.6744897500000001, -0.6744897500000001,
       5.656854249492380, -5.656854249492380, 5.656854249492381, -5.656854249492381,
       8.2923, 8.2924, 8.2925, -8.2924, -20, -30, -37.5192, -37.5194, 38, -38, 1e300, -1e300,
       runif(2000, -12, 12), rnorm(1000))
p <- c(seq(0.001, 0.999, by = 0.001),
       c(0.1, 0.4, 1, 2, 2.28, 3, 5, 10, 15, 16, 25, 50, 75, 84, 85, 90, 95, 97, 97.72, 98, 99, 99.6, 99.9) / 100,
       0.075, 0.925, 0.07499999999999999, 0.07500000000000001,
       1e-5, 1e-10, 1.3888e-11, 1e-12, 1e-20, 1e-300, 1 - 1e-10,
       runif(2000), runif(500, 0, 0.01), runif(500, 0.99, 1))

g <- function(v) sprintf("%.17g", v)
num <- function(v) ifelse(is.finite(v), g(v), ifelse(v > 0, '"Infinity"', '"-Infinity"'))
lines_p <- sprintf('[%s,%s]', g(z), num(pnorm(z)))
lines_q <- sprintf('[%s,%s]', g(p), num(qnorm(p)))
con <- file(out, "w")
writeLines(c(
  "{",
  sprintf('  "_comment": "R %s pnorm() and qnorm(); written by tools/normal_golden.R. Each pair is [argument, value].",',
          paste(R.version$major, R.version$minor, sep = ".")),
  '  "pnorm": [',
  paste0("    ", lines_p, c(rep(",", length(lines_p) - 1), "")),
  "  ],",
  '  "qnorm": [',
  paste0("    ", lines_q, c(rep(",", length(lines_q) - 1), "")),
  "  ]",
  "}"
), con)
close(con)
cat(sprintf("wrote %d pnorm and %d qnorm points to %s\n", length(z), length(p), out))
