#!/usr/bin/env bash
# Lint generated .bat files for the cmd pitfalls that fail silently.
#
#   lint-bats.sh <dir>
#
# Why static rather than "just run it through cmd": cmd has no dry-run or
# syntax-check mode, and it parses batch files lazily, line by line — so a
# syntax error later in a file is only reported once execution reaches it.
# Executing the scripts to find out is not an option either: they build,
# package and rmdir. So the checks below encode, as rules, the specific cmd
# behaviours that have actually bitten us.
#
# Exits non-zero if any file has a problem. scaffold.sh runs it automatically.

set -uo pipefail

DIR="${1:-.}"
fail=0

for f in "$DIR"/*.bat; do
  [ -f "$f" ] || continue
  name="$(basename "$f")"
  problems=()

  # --- 1. CRLF line endings -------------------------------------------------
  # cmd tolerates LF for plain commands, so the file looks fine — until a goto
  # or a label fails with "The system cannot find the batch label specified".
  total="$(wc -l < "$f" | tr -d ' ')"
  # Count CR bytes with od rather than a $'\r' pattern: escape sequences here
  # have a habit of silently degrading into a pattern that matches every line,
  # which is how this check first reported a clean LF-only file as all-CRLF.
  cr="$(od -An -tx1 -v "$f" | tr -s ' ' '\n' | grep -c '^0d$' || true)"
  if [ "${total:-0}" -gt 0 ] && [ "${cr:-0}" -lt "$total" ]; then
    problems+=("$((total - cr)) line(s) lack CRLF — cmd can fail on labels")
  fi

  # --- 2. Every goto/call target has a label --------------------------------
  defs="$(sed -n 's/^[[:space:]]*:\([A-Za-z0-9_]*\).*/\1/p' "$f" | sort -u)"
  refs="$(sed -n 's/^[[:space:]]*\(goto\|call\)[[:space:]]\+:\([A-Za-z0-9_]*\).*/\2/p' "$f" | sort -u)"
  for r in $refs; do
    [ "$r" = "eof" ] && continue          # :eof is built in
    if ! printf '%s\n' "$defs" | grep -qx "$r"; then
      problems+=("goto/call :$r has no matching label")
    fi
  done

  # --- 3. The ^& escape is only correct INSIDE a block ----------------------
  # `endlocal ^& exit /b 1` inside a block hands endlocal the literal arguments
  # "& exit /b 1"; at the top level the same caret makes & literal. Either way
  # the exit never happens. It is never the right thing to write.
  if grep -q '\^&' "$f"; then
    problems+=("contains '^&' — inside a block use bare 'exit /b N', at top level use bare '&'")
  fi

  # --- 4. A trailing bare endlocal swallows the exit code -------------------
  last="$(grep -v '^[[:space:]]*$' "$f" | tail -1 | tr -d '\r')"
  if printf '%s' "$last" | grep -qiE '^[[:space:]]*endlocal[[:space:]]*$'; then
    # Only a problem when the script has no other way to report failure. If the
    # error paths already `exit /b N`, a trailing endlocal just means "success
    # returns 0", which is correct.
    if ! grep -qiE '^[[:space:]]*exit /b [1-9]' "$f"; then
      problems+=("ends with a bare 'endlocal' and no 'exit /b N' — it always returns 0; use 'endlocal & exit /b %RC%'")
    fi
  fi

  # --- 5. Unquoted `set X=value && …` captures the space before && ----------
  if grep -qE '^[[:space:]]*set [A-Za-z_][A-Za-z0-9_]*=[^"]*[[:space:]]+&&' "$f"; then
    problems+=("unquoted 'set X=value &&' captures a trailing space — write set \"X=value\"")
  fi

  # --- 6. setlocal without endlocal ----------------------------------------
  sl="$(grep -ciE '^[[:space:]]*setlocal' "$f" || true)"
  el="$(grep -ci 'endlocal' "$f" || true)"
  if [ "$sl" -gt 0 ] && [ "$el" -eq 0 ]; then
    problems+=("has setlocal but no endlocal")
  fi

  if [ "${#problems[@]}" -gt 0 ]; then
    fail=1
    echo "LINT FAIL: $name"
    for p in "${problems[@]}"; do
      echo "  - $p"
    done
  fi
done

if [ "$fail" -eq 0 ]; then
  echo "lint: all .bat files clean"
fi

exit "$fail"
