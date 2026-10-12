#!/usr/bin/env sh
#
# Run the unit suite with coverage, enforce the coverage baseline and write
# clover/cobertura/html reports.
#
# Usage:
#   sh Build/Scripts/runCoverage.sh [--update-baseline]
#
#   --update-baseline  Rewrite the baseline file with the measured value
#                      instead of checking it (requires a coverage driver).
#
# A PHP coverage driver (pcov or xdebug) is required for the reports and
# the baseline check. Without one the suite still runs, just without
# coverage - so local environments without a driver are not blocked. CI
# provides pcov through setup-php.
#
# The baseline lives in Build/Quality/coverage-baseline.json as
# { "unit": <percent|null> }. A null entry reports the line coverage without
# enforcing it; a number fails the run when the line coverage drops below it
# by more than $COVERAGE_TOLERANCE (to absorb small drift between PHP
# versions).
#
# The metric is line coverage (executed lines / executable lines, rounded to
# two decimals), read from the project-level metrics element of the generated
# Clover XML - the same number Codecov reports as total coverage.
#
# Reports go to .Build/coverage/unit/ (git-ignored).
#
set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

BASELINE_FILE="$REPO_ROOT/Build/Quality/coverage-baseline.json"
OUT_DIR="$REPO_ROOT/.Build/coverage/unit"
FAILED=0

# Measured line coverage drifts slightly between PHP versions and dependency
# resolutions (observed around 0.2 points), so a drop only fails the run when
# it exceeds this tolerance.
COVERAGE_TOLERANCE=0.5

if [ ! -f "$BASELINE_FILE" ]; then
    echo "Baseline file not found: $BASELINE_FILE" >&2
    exit 1
fi

cd "$REPO_ROOT"

UPDATE_BASELINE=0
while [ $# -gt 0 ]; do
    case "$1" in
        --update-baseline)
            UPDATE_BASELINE=1
            ;;
        *)
            echo "Unknown argument: $1" >&2
            echo "Usage: $0 [--update-baseline]" >&2
            exit 1
            ;;
    esac
    shift
done

# Coverage driver: prefer pcov, fall back to xdebug.
DRIVER=''
for CANDIDATE in pcov xdebug; do
    if php -m | grep -qx "$CANDIDATE"; then
        DRIVER="$CANDIDATE"
        break
    fi
done

if [ -z "$DRIVER" ]; then
    if [ "$UPDATE_BASELINE" -eq 1 ]; then
        echo "error: --update-baseline requires a coverage driver (pcov or xdebug)" >&2
        exit 1
    fi
    echo "warning: no coverage driver (pcov/xdebug) available - running without coverage" >&2
fi

# Read the baseline entry; prints nothing for null (report-only).
baseline_value() {
    php -r '
        $value = json_decode(file_get_contents($argv[1]), true)["unit"] ?? null;
        echo is_null($value) ? "" : (string) $value;
    ' "$BASELINE_FILE"
}

# Line coverage percentage (two decimals) from the Clover report, or nothing
# if the report cannot be read.
coverage_percent() {
    php -r '
        $xml = simplexml_load_file($argv[1]);
        if ($xml === false) {
            exit(0);
        }
        $metrics = null;
        if (isset($xml->project->metrics)) {
            $metrics = $xml->project->metrics;
        }
        if ($metrics === null) {
            exit(0);
        }
        $covered = (int) $metrics["coveredstatements"];
        $total   = (int) $metrics["statements"];
        echo $total > 0 ? number_format(100 * $covered / $total, 2, ".", "") : "100.00";
    ' "$OUT_DIR/clover.xml"
}

# Rewrite the baseline entry with the measured value.
update_baseline() {
    php -r '
        $data = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($data)) {
            fwrite(STDERR, "error: cannot update baseline from invalid JSON: {$argv[1]}\n");
            exit(1);
        }
        $data["unit"] = (float) $argv[2];
        file_put_contents(
            $argv[1],
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    ' "$BASELINE_FILE" "$1"
    echo "  unit         $1%  (baseline updated)"
}

if [ -n "$DRIVER" ]; then
    rm -rf "$OUT_DIR"
    mkdir -p "$OUT_DIR"
    # 1G: coverage collection on top of the testing-framework bootstrap
    # exceeds the default 128M memory limit.
    php -d memory_limit=1G .Build/bin/phpunit -c Build/Testing/UnitTests.xml \
        "--coverage-clover=$OUT_DIR/clover.xml" \
        "--coverage-cobertura=$OUT_DIR/cobertura.xml" \
        "--coverage-html=$OUT_DIR/html"

    ACTUAL="$(coverage_percent)"
    BASELINE="$(baseline_value)"

    if [ -z "$ACTUAL" ]; then
        echo "error: could not read unit coverage from $OUT_DIR/clover.xml" >&2
        FAILED=1
    elif [ "$UPDATE_BASELINE" -eq 1 ]; then
        update_baseline "$ACTUAL"
    elif [ -z "$BASELINE" ]; then
        echo "  unit         $ACTUAL%  (baseline not set - reported only)"
    else
        BELOW="$(php -r 'echo (float) $argv[1] + (float) $argv[2] < (float) $argv[3] ? "1" : "0";' "$ACTUAL" "$COVERAGE_TOLERANCE" "$BASELINE")"
        if [ "$BELOW" = "1" ]; then
            echo "  unit         $ACTUAL%  (baseline $BASELINE% - below baseline)" >&2
            FAILED=1
        else
            echo "  unit         $ACTUAL%  (baseline $BASELINE% - ok)"
        fi
    fi
else
    # No driver: still run the suite, so the check verifies the tests pass.
    php -d memory_limit=1G .Build/bin/phpunit -c Build/Testing/UnitTests.xml
fi

if [ "$FAILED" -ne 0 ]; then
    echo "Coverage baseline violated" >&2
    exit 1
fi
