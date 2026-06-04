#!/usr/bin/env bash
# Rumble release script — safe PHP updater, friendly status, self-healing git.
set -euo pipefail

# ==== CONFIG ==================================================================
OWNER="emkowale"
REPO="rumble"
PLUGIN_SLUG="rumble"       # top-level folder name inside the zip
MAIN_FILE="rumble.php"     # plugin main file (your repo keeps it at root)
REMOTE_URL="git@github.com:${OWNER}/${REPO}.git"

# ==== UI HELPERS ==============================================================
C_RESET=$'\033[0m'; C_CYAN=$'\033[1;36m'; C_YEL=$'\033[1;33m'; C_RED=$'\033[1;31m'; C_GRN=$'\033[1;32m'
step(){ printf "${C_CYAN}🔷 %s${C_RESET}\n" "$*"; }
ok(){   printf "${C_GRN}✅ %s${C_RESET}\n" "$*"; }
warn(){ printf "${C_YEL}⚠️  %s${C_RESET}\n" "$*"; }
die(){  printf "${C_RED}❌ %s${C_RESET}\n" "$*"; exit 1; }
trap 'printf "${C_RED}❌ Release failed at line %s${C_RESET}\n" "$LINENO"' ERR

# ==== ARGS / TOOL CHECKS ======================================================
BUMP_TYPE="${1:-patch}"
[[ "$BUMP_TYPE" =~ ^(major|minor|patch)$ ]] || die "Usage: ./release.sh {major|minor|patch}"

command -v git >/dev/null || die "git not found"
command -v php >/dev/null || die "php not found"
command -v zip >/dev/null || die "zip not found"

# ==== LOCATE REPO ROOT & MAIN FILE ===========================================
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

is_git_repo(){ git rev-parse --is-inside-work-tree >/dev/null 2>&1; }
is_empty_dir(){ [[ -d "$1" ]] && ! find "$1" -mindepth 1 -print -quit | grep -q .; }

if ! is_git_repo; then
  if [[ -d ".git" ]] && ! is_empty_dir ".git"; then
    die ".git exists but is not a valid Git repository. Fix or remove it before releasing."
  elif [[ -d "../.git" ]] && ( cd .. && is_git_repo ); then
    cd ..
  elif [[ -d "../.git" ]] && ! is_empty_dir "../.git"; then
    die "../.git exists but is not a valid Git repository. Fix or remove it before releasing."
  else
    step "Initializing git repository"
    git init -b main >/dev/null 2>&1 || { git init >/dev/null; git branch -M main >/dev/null; }
    ok "Initialized git repository at $(pwd)"
  fi
fi

is_git_repo || die "Run from the git repo root (or one level under)."

if [[ -f "${PLUGIN_SLUG}/${MAIN_FILE}" ]]; then
  SRC_DIR="${PLUGIN_SLUG}"
  MAIN_PATH="${PLUGIN_SLUG}/${MAIN_FILE}"
elif [[ -f "${MAIN_FILE}" ]]; then
  SRC_DIR="."
  MAIN_PATH="${MAIN_FILE}"
else
  die "Cannot find ${MAIN_FILE} at repo root or under ${PLUGIN_SLUG}/"
fi

# ==== GIT: SELF-HEAL & SYNC ===================================================
step "Preparing git state"
if git remote get-url origin >/dev/null 2>&1; then
  git remote set-url origin "$REMOTE_URL" >/dev/null
else
  git remote add origin "$REMOTE_URL" >/dev/null
fi
git rebase --abort >/dev/null 2>&1 || true
git merge  --abort >/dev/null 2>&1 || true
git reset  --merge >/dev/null 2>&1 || true

if ! git rev-parse --abbrev-ref HEAD | grep -q '^main$'; then
  git show-ref --verify --quiet refs/heads/main || git branch main >/dev/null 2>&1 || true
  git switch -C main >/dev/null
fi
git rev-parse --abbrev-ref --symbolic-full-name @{u} >/dev/null 2>&1 || git branch --set-upstream-to=origin/main main >/dev/null 2>&1 || true

step "Fetching remote branch & tags"
git fetch origin main --tags || true
git merge --allow-unrelated-histories -s ours --no-edit origin/main -m "stitch: adopt origin/main (prefer local files)" >/dev/null 2>&1 || true
ok "Git ready"

# ==== VERSION DISCOVERY =======================================================
step "Reading current version from ${MAIN_PATH}"
read_versions_php=$(cat <<'PHP'
$path = $argv[1];
$src = file_get_contents($path);
if ($src === false) { fwrite(STDERR, "read fail\n"); exit(1); }

$versions = [];
if (preg_match_all('/(?mi)^\s*(?:\*\s*)?Version\s*:\s*([0-9]+\.[0-9]+\.[0-9]+)/', $src, $mh)) {
  foreach ($mh[1] as $v) $versions[] = $v;
}
if (preg_match("/define\(\s*'RUMBLE_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)\s*;/", $src, $mc)) {
  $versions[] = $mc[1];
}
if (empty($versions)) { echo "0.0.0"; exit; }
usort($versions, 'version_compare');
echo end($versions);
PHP
)
BASE_VER="$(php -r "$read_versions_php" "$MAIN_PATH")"
[[ -n "$BASE_VER" ]] || BASE_VER="0.0.0"

latest_tag="$(git tag | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sed 's/^v//' | sort -V | tail -n1 || true)"
ver_ge(){ printf '%s\n%s\n' "$1" "$2" | sort -V -r | head -n1 | grep -qx "$1"; }
if [[ -n "$latest_tag" ]] && ver_ge "$latest_tag" "$BASE_VER"; then
  BASE_VER="$latest_tag"
fi
ok "Base version: $BASE_VER"

IFS='.' read -r MAJ MIN PAT <<<"$BASE_VER"
case "$BUMP_TYPE" in
  major) ((MAJ+=1)); MIN=0; PAT=0 ;;
  minor) ((MIN+=1)); PAT=0 ;;
  patch) ((PAT+=1)) ;;
esac
NEXT="${MAJ}.${MIN}.${PAT}"

tag_exists(){ git rev-parse -q --verify "refs/tags/v$1" >/dev/null 2>&1; }
while tag_exists "$NEXT"; do
  ((PAT+=1)); NEXT="${MAJ}.${MIN}.${PAT}"
done
printf "${C_CYAN}🚀 Preparing release v%s${C_RESET}\n" "$NEXT"

# ==== SAFE PHP UPDATER (FIXED) ===============================================
step "Updating ${MAIN_PATH} safely"
fixer_php=$(cat <<'PHP'
$path = $argv[1];
$ver  = $argv[2];

$src = file_get_contents($path);
if ($src === false) { fwrite(STDERR, "read fail\n"); exit(1); }

/* Normalize line endings */
$src = preg_replace("/\r\n?/", "\n", $src);

/* Remove trivial dangling garbage */
$src = preg_replace("/^[ \t]*\.[0-9]+\.[0-9]+\.[0-9]+'\)?;?\s*$/m", "", $src);

/* --- Ensure a single header Version line --- */
$lines = preg_split("/\n/", $src);
$limit = min(400, count($lines));
$start=-1;$end=-1;
for($i=0;$i<$limit;$i++){ if(preg_match("/^\s*\/\*/",$lines[$i])){$start=$i;break;} }
if($start>=0){
  for($j=$start;$j<min($start+120,count($lines));$j++){ if(preg_match("/\*\//",$lines[$j])){$end=$j;break;} }
}
if($start<0||$end<0){
  array_splice($lines,0,0,["/*"," * Plugin Name: rumble"," * Version: ".$ver," */"]);
} else {
  // remove all Version lines inside header
  for($k=$start;$k<=$end;$k++){
    if(preg_match("/^\s*(?:\*\s*)?Version\s*:/i",$lines[$k])){ $lines[$k] = null; }
  }
  $tmp=[]; foreach($lines as $ln){ if($ln!==null)$tmp[]=$ln; } $lines=$tmp;

  // recompute header end after removals
  $end=-1; for($j=$start;$j<min($start+120,count($lines));$j++){ if(preg_match("/\*\//",$lines[$j])){$end=$j;break;} }

  // insert Version after Plugin Name if present, else at start+1
  $ins=$start+1;
  for($k=$start;$k<=$end;$k++){ if(preg_match("/^\s*\*\s*Plugin\s+Name\s*:/i",$lines[$k])){$ins=$k+1;break;} }
  array_splice($lines,$ins,0," * Version: ".$ver);
}
$src = implode("\n",$lines);

/* --- Ensure exactly one define('RUMBLE_VERSION','X.Y.Z'); --- */
$defRe = "/^\\s*define\\(\\s*'RUMBLE_VERSION'\\s*,\\s*'[^']*'\\s*\\)\\s*;\\s*$/m";
$src = preg_replace($defRe, '', $src); // remove all existing defines first

$inserted = 0;
$src = preg_replace_callback(
  "/(if\\s*\\(\\s*!\\s*defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\)\\s*exit;\\s*)/m",
  function($m) use ($ver, &$inserted){
    $inserted = 1;
    return $m[1]."\n\ndefine('RUMBLE_VERSION', '".$ver."');\n";
  },
  $src,
  1
);

if(!$inserted){
  // fallback: insert after opening tag
  $src = preg_replace("/(<\\?php\\s*)/","$1\ndefine('RUMBLE_VERSION', '".$ver."');\n", $src, 1, $c);
  if($c===0){
    // absolute last resort: prepend
    $src = "<?php\ndefine('RUMBLE_VERSION', '".$ver."');\n?>\n".$src;
  }
}

/* Soft sanity (warn only) */
if (!preg_match('/(?mi)^\s*(?:\*\s*)?Version\s*:\s*'.preg_quote($ver,'/').'\b/',$src)) {
  fwrite(STDERR,"warn: header missing Version after update; continuing\n");
}
if (!preg_match("/define\\(\\s*'RUMBLE_VERSION'\\s*,\\s*'".preg_quote($ver,'/')."'\\s*\\)\\s*;/",$src)) {
  fwrite(STDERR,"warn: define missing after update; continuing\n");
}

if (file_put_contents($path,$src)===false){ fwrite(STDERR,"write fail\n"); exit(1); }
PHP
)
php -r "$fixer_php" "$MAIN_PATH" "$NEXT"
ok "Updated ${MAIN_PATH} to v${NEXT}"


# ==== CHANGELOG AUTO-UPDATE ===================================================
step "Updating CHANGELOG.md"
CHANGELOG="CHANGELOG.md"
TODAY="$(date +%Y-%m-%d)"

# 1) Ensure the file exists and create the new version heading
if [[ ! -f "$CHANGELOG" ]]; then
  printf "# Changelog\n\n## [${NEXT}] - %s\n\n" "$TODAY" > "$CHANGELOG"
  ok "Created new CHANGELOG.md"
else
  if grep -qE '^## \[Unreleased\]' "$CHANGELOG"; then
    # Insert new version section immediately after [Unreleased]
    tmp=$(mktemp)
    awk -v ver="$NEXT" -v today="$TODAY" '
      /^## \[Unreleased\]/ { print; print ""; print "## ["ver"] - "today; next }
      { print }
    ' "$CHANGELOG" >"$tmp" && mv "$tmp" "$CHANGELOG"
    ok "Added section [${NEXT}] under [Unreleased]"
  else
    # Prepend new section under top header
    tmp=$(mktemp)
    awk -v ver="$NEXT" -v today="$TODAY" '
      NR==1 { print; print ""; print "## ["ver"] - "today; next }
      { print }
    ' "$CHANGELOG" >"$tmp" && mv "$tmp" "$CHANGELOG"
    ok "Prepended section [${NEXT}]"
  fi
fi

# 2) Collect commit summaries since previous tag and inject as bullets
#    (no merges; conventional-commit prefixes kept as-is)
PREV_TAG="$(git tag -l 'v[0-9]*' | sort -V | tail -n2 | head -n1)"
if [[ -n "$PREV_TAG" ]]; then
  RANGE="${PREV_TAG}..HEAD"
else
  RANGE=""
fi

LOG_FILE="$(mktemp)"
if [[ -n "$RANGE" ]]; then
  git log --no-merges --pretty=format:'* %s (%h)' "$RANGE" > "$LOG_FILE" || true
else
  git log --no-merges --pretty=format:'* %s (%h)' --max-count=100 > "$LOG_FILE" || true
fi

# Fallback line if empty
if [[ ! -s "$LOG_FILE" ]]; then
  echo "* Internal updates" > "$LOG_FILE"
fi

# Insert under the just-created "## [NEXT]" header
tmp=$(mktemp)
awk -v ver="$NEXT" -v lf="$LOG_FILE" '
  {
    print
    if (!done && $0 ~ "^## \\[" ver "\\]") {
      print ""
      print "### Changes"
      while ((getline line < lf) > 0) print line
      close(lf)
      print ""
      done=1
    }
  }
' "$CHANGELOG" > "$tmp" && mv "$tmp" "$CHANGELOG"
rm -f "$LOG_FILE"

git add "$CHANGELOG"




# ==== COMMIT / TAG / PUSH =====================================================
step "Committing & tagging"
if git rev-parse --verify HEAD >/dev/null 2>&1; then
  git add "$MAIN_PATH" "$CHANGELOG"
else
  git add -A -- . ':!artifacts' ':!package'
fi
git commit -m "chore(release): v${NEXT}" >/dev/null 2>&1 || warn "Nothing to commit (files already updated)"
git tag -f "v${NEXT}"

step "Pushing branch & tag"
if ! git push origin main; then
  warn "Push rejected; refetching & stitching then retry"
  git fetch origin main --tags || true
  git merge --allow-unrelated-histories -s ours --no-edit origin/main -m "sync: prefer local files" || true
  git push origin main || warn "Could not push main (continuing)"
fi
git push -f origin "v${NEXT}" || warn "Could not push tag v${NEXT}"
ok "Git pushed"

# ==== BUILD ARTIFACT ==========================================================
step "Building zip artifact"
ART_DIR="artifacts"
PKG_DIR="package/${PLUGIN_SLUG}"
ZIP_NAME="${PLUGIN_SLUG}-v${NEXT}.zip"
rm -rf "$PKG_DIR" "$ART_DIR"
mkdir -p "$PKG_DIR" "$ART_DIR"
RSYNC_EXCLUDES=(--exclude ".git/" --exclude "artifacts/" --exclude "package/" --exclude ".github/" --exclude ".DS_Store")
if [[ "$SRC_DIR" == "." ]]; then
  rsync -a --delete "${RSYNC_EXCLUDES[@]}" ./ "$PKG_DIR/"
else
  rsync -a --delete "${RSYNC_EXCLUDES[@]}" "${SRC_DIR}/" "$PKG_DIR/"
fi
( cd "package" && zip -qr "../${ART_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}" )
ok "Built ${ART_DIR}/${ZIP_NAME}"

# ==== GITHUB RELEASE (OPTIONAL) ===============================================
if command -v gh >/dev/null 2>&1; then
  step "Publishing GitHub release v${NEXT}"
  if gh release view "v${NEXT}" >/dev/null 2>&1; then
    warn "Release exists — updating asset"
    gh release upload "v${NEXT}" "${ART_DIR}/${ZIP_NAME}" --clobber >/dev/null
  else
    gh release create "v${NEXT}" "${ART_DIR}/${ZIP_NAME}" -t "v${NEXT}" -n "Release ${NEXT}" >/dev/null
  fi
  ok "Release v${NEXT} published"
else
  warn "gh not installed; skipped GitHub release"
fi

rm -rf package

printf "${C_GRN}🎉 All done: ${ART_DIR}/${ZIP_NAME}${C_RESET}\n"
