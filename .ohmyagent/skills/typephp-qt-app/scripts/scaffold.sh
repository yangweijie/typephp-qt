#!/usr/bin/env bash
# Scaffold a minimal, runnable TypePHP + Qt project.
#
#   scaffold.sh [--tray] <target-dir> <app_name> [qt_root]
#
# Options:
#   --tray   generate a system-tray app instead of a window app
#   -h       show this help
#
# Window app (default) creates:
#   <target-dir>/main.php
#   <target-dir>/app/AppStore.php        (demo data layer — replace it)
#   <target-dir>/app/AppController.php   (event handling + view refresh)
#   <target-dir>/php-src/<app_name>.stub.php
#   <target-dir>/cpp-src/<app_name>.cc
#
# Tray app (--tray) creates:
#   <target-dir>/main.php
#   <target-dir>/app/TrayController.php
#   <target-dir>/php-src/tray.stub.php
#   <target-dir>/cpp-src/tray.cc
#   <target-dir>/assets/icon.png         (placeholder — replace it)
#
# Both also create project.yml, runtime.ini, assets/README.txt, build.bat,
# run.bat and package.bat. The generated project compiles and runs as-is; only
# the UI, the data layer and the assets are meant to be replaced.
#
# <app_name> must be a lowercase identifier (letters, digits, underscore).

set -euo pipefail

TRAY=0
POSITIONAL=()

for arg in "$@"; do
  case "$arg" in
    --tray)   TRAY=1 ;;
    -h|--help)
      sed -n '2,32p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) POSITIONAL+=("$arg") ;;
  esac
done

if [ "${#POSITIONAL[@]}" -lt 2 ]; then
  echo "usage: scaffold.sh [--tray] <target-dir> <app_name> [qt_root]" >&2
  exit 2
fi

TARGET="${POSITIONAL[0]}"
APP="${POSITIONAL[1]}"
QT_ROOT="${POSITIONAL[2]:-<QT_ROOT>}"

# Where the TypePHP package lives. Substituted into runtime.ini; keep it in
# sync with the value baked into the generated .bat files.
PHP_HOME_DIR="${PHP_HOME_DIR:-D:/git/php/tpc_v0.9.4_windows_x64}"

if ! printf '%s' "$APP" | grep -Eq '^[a-z][a-z0-9_]*$'; then
  echo "error: app_name must match ^[a-z][a-z0-9_]*\$ (got: $APP)" >&2
  exit 2
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TPL="$HERE/../assets/templates"

if [ ! -d "$TPL" ]; then
  echo "error: templates not found at $TPL" >&2
  exit 1
fi

if [ -e "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null || true)" ]; then
  echo "error: $TARGET already exists and is not empty" >&2
  exit 1
fi

mkdir -p "$TARGET/app" "$TARGET/php-src" "$TARGET/cpp-src" "$TARGET/assets"

# Copy a template and substitute the placeholders. The window templates use
# <APP> inside function names so the PHP stub and the C++ php_<APP>_* symbols
# stay in sync automatically. The tray templates use fixed tray_* names, which
# the tray stub and tray bridge both agree on.
render() {
  local src="$1" dst="$2"
  sed -e "s|<APP>|$APP|g" \
      -e "s|<QT_ROOT>|$QT_ROOT|g" \
      -e "s|<PHP_HOME>|$PHP_HOME_DIR|g" \
      "$src" > "$dst"
}

if [ "$TRAY" -eq 1 ]; then
  render "$TPL/tray-main.skeleton.php"       "$TARGET/main.php"
  render "$TPL/tray-controller.skeleton.php" "$TARGET/app/TrayController.php"
  render "$TPL/tray.stub.skeleton.php"       "$TARGET/php-src/tray.stub.php"
  render "$TPL/tray.skeleton.cc"             "$TARGET/cpp-src/tray.cc"
  base64 -d < "$TPL/placeholder-icon.b64" > "$TARGET/assets/icon.png"
  SHOT_VAR="TRAY_SCREENSHOT"
  KIND="tray app"
else
  render "$TPL/main.skeleton.php"        "$TARGET/main.php"
  render "$TPL/store.skeleton.php"       "$TARGET/app/AppStore.php"
  render "$TPL/controller.skeleton.php"  "$TARGET/app/AppController.php"
  render "$TPL/stub.skeleton.php"        "$TARGET/php-src/$APP.stub.php"
  render "$TPL/bridge.skeleton.cc"       "$TARGET/cpp-src/$APP.cc"
  SHOT_VAR="${APP}_SCREENSHOT"
  KIND="window app"
fi

render "$TPL/project.windows.yml" "$TARGET/project.yml"
render "$TPL/runtime.ini"         "$TARGET/runtime.ini"

cat > "$TARGET/assets/README.txt" <<'EOF'
Runtime files for this app.

Anything in this folder is copied next to the executable by package.bat.
windeployqt handles Qt and nothing else — your own files are exactly what it
does NOT copy, which is why this folder exists.

The folder structure is preserved: a file at assets/icon.png ends up at
<app-dir>/assets/icon.png, so reference it from PHP as 'assets/icon.png'.

Paths are resolved relative to the executable's folder, not the process CWD,
so they keep working no matter where the app is launched from.
EOF

cat > "$TARGET/build.bat" <<EOF
@echo off
REM Build the ${APP} TypePHP + Qt app. Edit VCVARS / PHP_HOME / QT_ROOT first.
setlocal
set "VCVARS=C:\\Program Files (x86)\\Microsoft Visual Studio\\2022\\BuildTools\\VC\\Auxiliary\\Build\\vcvars64.bat"
if not exist "%VCVARS%" (
  echo error: vcvars64.bat not found - install MSVC 2022 or fix VCVARS above
  exit /b 1
)
call "%VCVARS%" >nul

set "PHP_HOME=D:\\git\\php\\tpc_v0.9.4_windows_x64"
set "PHPX_HOME=%PHP_HOME%\\phpx"
set "QT_ROOT=${QT_ROOT}"
set "PATH=%QT_ROOT%\\bin;%PHP_HOME%;%PATH%"
cd /d "%~dp0"

"%PHP_HOME%\\tpc.exe" project.yml --job 2 --no-progress
set "RC=%errorlevel%"
echo BUILD_EXITCODE=%RC%
REM A bare endlocal would report success no matter what the build did.
endlocal & exit /b %RC%
EOF

# run.bat takes an optional screenshot path: \`run.bat shot.png\` renders one
# frame, saves the PNG and exits. Passing it as an argument avoids the fragile
# nested-quoting you get from \`set VAR=... && call run.bat\` on the command line.
cat > "$TARGET/run.bat" <<EOF
@echo off
REM Deploy Qt deps and run. Optional arg 1: a .png path -> render once and exit.
setlocal
set "PHP_HOME=D:\\git\\php\\tpc_v0.9.4_windows_x64"
set "PHPX_HOME=%PHP_HOME%\\phpx"
set "QT_ROOT=${QT_ROOT}"
set "PATH=%QT_ROOT%\\bin;%PHP_HOME%;%PHPX_HOME%\\lib;%PATH%"
cd /d "%~dp0"

if not exist "${APP}.exe" (
  echo error: ${APP}.exe not found - run build.bat first
  exit /b 1
)

REM >nul hides the verbose "Updating ..." lines; windeployqt's warnings go to
REM stderr and stay visible.
"%QT_ROOT%\\bin\\windeployqt.exe" --release --no-translations "${APP}.exe" >nul
set "PHPRC=%~dp0runtime.ini"
set "PHP_INI_SCAN_DIR="
if not "%~1"=="" set "${SHOT_VAR}=%~f1"

"${APP}.exe"
set "RC=%errorlevel%"
echo RUN_EXITCODE=%RC%
REM A bare endlocal would report success even if the app crashed.
endlocal & exit /b %RC%
EOF

# package.bat is the one that matters for shipping. windeployqt covers Qt and
# only Qt; the PHP/PHPX runtime DLLs and your own assets/ have to be copied by
# hand, and forgetting either is the classic "works here, broken there" bug.
cat > "$TARGET/package.bat" <<EOF
@echo off
REM Assemble a redistributable folder in dist\\.
REM   dist\\${APP}.exe
REM   dist\\                Qt DLLs + plugins            (windeployqt)
REM   dist\\                TypePHP / PHPX runtime DLLs  (windeployqt does NOT do these)
REM   dist\\assets\\         your own runtime files       (nor these)
REM   dist\\runtime.ini     PHP extension config, if the app uses one
REM
REM It then runs the packaged app with a crippled PATH to prove nothing is
REM missing. Pass --no-verify to skip that (e.g. on a headless CI box).
setlocal
set "PHP_HOME=D:\\git\\php\\tpc_v0.9.4_windows_x64"
set "PHPX_HOME=%PHP_HOME%\\phpx"
set "QT_ROOT=${QT_ROOT}"
set "PATH=%QT_ROOT%\\bin;%PHP_HOME%;%PHPX_HOME%\\lib;%PATH%"
cd /d "%~dp0"

set "APP=${APP}"
set "DIST=%~dp0dist"

if not exist "%APP%.exe" (
  echo error: %APP%.exe not found - run build.bat first
  exit /b 1
)

echo [1/5] clean dist
if exist "%DIST%" rmdir /S /Q "%DIST%"
mkdir "%DIST%"

echo [2/5] executable
copy /Y "%APP%.exe" "%DIST%\\" >nul

echo [3/5] Qt runtime
"%QT_ROOT%\\bin\\windeployqt.exe" --release --no-translations "%DIST%\\%APP%.exe" >nul

echo [4/5] TypePHP/PHPX runtime + assets
REM The non-Qt DLLs the executable needs. Derived from the import table;
REM add more if you load extra PHP extensions (e.g. libsqlite3.dll for pdo_sqlite).
for %%D in (phpx.dll php8ts.dll libmpdec++-4.0.1.dll libmpdec-4.0.1.dll gmp-10.dll mpfr-6.dll) do (
  copy /Y "%PHP_HOME%\\%%D" "%DIST%\\" >nul
)
if exist "assets" (
  xcopy /E /I /Y /Q "assets" "%DIST%\\assets" >nul
  echo       assets\\ copied
) else (
  echo       no assets\\ folder - nothing to copy
)
if exist "runtime.ini" (
  copy /Y "runtime.ini" "%DIST%\\" >nul
  echo       runtime.ini copied - launch with PHPRC pointing at it if the
  echo       app loads PHP extensions (e.g. pdo_sqlite)
)

REM ---- self-containment check --------------------------------------------
REM Run the packaged app with a crippled PATH so nothing can leak in from the
REM build machine. This is the only check that actually catches a missing DLL
REM or an asset that never made it into dist\\.
if /I "%~1"=="--no-verify" (
  echo [5/5] self-containment check - SKIPPED
  goto :packaged
)

echo [5/5] self-containment check ^(clean PATH^)
set "CHECK_SHOT=%DIST%\\selfcheck.png"
if exist "%CHECK_SHOT%" del /Q "%CHECK_SHOT%"
set "SAVED_PATH=%PATH%"
set "PATH=C:\\Windows\\System32;C:\\Windows"
set "PHP_INI_SCAN_DIR="
if exist "%DIST%\\runtime.ini" set "PHPRC=%DIST%\\runtime.ini"
set "${SHOT_VAR}=%CHECK_SHOT%"
set "CHECK_OUT=%DIST%\\selfcheck.out"
set "CHECK_ERR=%DIST%\\selfcheck.err"
if exist "%CHECK_OUT%" del /Q "%CHECK_OUT%"
if exist "%CHECK_ERR%" del /Q "%CHECK_ERR%"
pushd "%DIST%"
REM Capture both streams: the embedded PHP runtime writes its startup warnings
REM to stdout, not to stderr.
"%APP%.exe" >"%CHECK_OUT%" 2>"%CHECK_ERR%"
set "RC=%errorlevel%"
popd
set "PATH=%SAVED_PATH%"
set "${SHOT_VAR}="

if not "%RC%"=="0" (
  echo.
  echo   FAILED - the packaged app exited with %RC%.
  type "%CHECK_OUT%" 2>nul
  type "%CHECK_ERR%" 2>nul
  echo   The message above names the missing file. Check the runtime DLL list
  echo   and the assets\\ copy step against it.
  exit /b 1
)

REM A clean exit code is not enough: the embedded PHP runtime reports a missing
REM extension as a warning and carries on, so the app "works" while quietly
REM missing a feature. Treat that as a packaging failure too.
findstr /I /C:"PHP Startup" /C:"Fatal error" "%CHECK_OUT%" "%CHECK_ERR%" >nul 2>&1
if not errorlevel 1 (
  echo.
  echo   FAILED - the PHP runtime reported a startup problem:
  type "%CHECK_OUT%" 2>nul
  type "%CHECK_ERR%" 2>nul
  echo   Usually a PHP extension that was enabled in runtime.ini but not
  echo   shipped. See the notes at the top of runtime.ini.
  exit /b 1
)

if not exist "%CHECK_SHOT%" (
  echo.
  echo   FAILED - the app exited cleanly but rendered nothing.
  echo   It probably started without its assets, or there is no display.
  exit /b 1
)
type "%CHECK_OUT%" 2>nul
type "%CHECK_ERR%" 2>nul
if exist "%CHECK_SHOT%" del /Q "%CHECK_SHOT%"
if exist "%CHECK_OUT%" del /Q "%CHECK_OUT%"
if exist "%CHECK_ERR%" del /Q "%CHECK_ERR%"
set "VERIFIED=1"
echo   OK - started and rendered with only System32 on PATH.

:packaged
echo.
echo packaged to %DIST%
if defined VERIFIED (
  echo verified self-contained - ship the whole folder.
) else (
  echo NOT verified - run package.bat without --no-verify before shipping.
)
endlocal
EOF

# cmd.exe needs CRLF. With LF-only files it tolerates plain commands but fails
# on labels with "The system cannot find the batch label specified" — which is
# exactly what package.bat's --no-verify path would hit.
for bat in "$TARGET"/*.bat; do
  [ -f "$bat" ] || continue
  sed -i 's/\r$//' "$bat"
  sed -i 's/$/\r/' "$bat"
done

# Lint before handing over. cmd has no dry-run or syntax-check mode, so these
# static rules stand in for one — they encode the cmd behaviours that have
# actually bitten this scaffold (see references/build-and-deploy.md).
if ! bash "$HERE/lint-bats.sh" "$TARGET"; then
  echo "error: generated .bat files failed linting - fix the templates in $TPL" >&2
  exit 1
fi

echo "scaffolded $KIND '$APP' into $TARGET"
echo "next:"
echo "  1. run $TARGET/build.bat      (edit QT_ROOT / PHP_HOME inside it first)"
echo "  2. run $TARGET/run.bat        (or: run.bat shot.png to render once and exit)"
echo "  3. run $TARGET/package.bat    to assemble dist/ for shipping"
if [ "$TRAY" -eq 1 ]; then
  echo "  4. replace assets/icon.png with your own icon (PNG / ICO / SVG)."
else
  echo "  4. then replace app/AppStore.php with your real data layer"
fi
