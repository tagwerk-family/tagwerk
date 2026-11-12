#!/usr/bin/env bash
# Splits every package of the monorepo into its own read-only repository:
# apps/platform/packages/<x> and apps/platform/plugins/<x> go to
# <owner>/fahoba-<name>, branch main, plus a tag v<version> from the
# package's composer.json if that tag is missing there. Packagist reads the
# tags. Development stays here; the split repos only receive.
# apps/platform/templates/<x> goes to <owner>/fahoba-<x>-template, main
# only: a GitHub template repository, not a package.
#
#   SPLIT_OWNER   GitHub owner of the split repositories
#   SPLIT_TOKEN   token with contents: write on them
#   SPLIT_DRY_RUN 1 = split and print, push nothing
set -euo pipefail

owner="${SPLIT_OWNER:?SPLIT_OWNER is required}"
failed=()

for dir in apps/platform/packages/*/ apps/platform/plugins/*/ apps/platform/templates/*/; do
  dir="${dir%/}"
  if [[ "$dir" == apps/platform/templates/* ]]; then
    name="${dir##*/} template"
    version=""
    repo="$owner/fahoba-${dir##*/}-template"
  else
    name="$(jq -r .name "$dir/composer.json")"
    version="$(jq -r '.version // empty' "$dir/composer.json")"
    repo="$owner/fahoba-${name#*/}"
  fi
  sha="$(git subtree split --quiet --prefix="$dir" HEAD)"

  echo "$name -> $repo @ ${sha:0:7} (v${version:-none})"

  if [[ "${SPLIT_DRY_RUN:-0}" == "1" ]]; then
    continue
  fi

  url="https://x-access-token:${SPLIT_TOKEN:?SPLIT_TOKEN is required}@github.com/$repo.git"

  # A missing repository (a new plugin) must not stop the others.
  if ! git push --quiet "$url" "$sha:refs/heads/main"; then
    failed+=("$repo")
    continue
  fi

  if [[ -n "$version" ]] && [[ -z "$(git ls-remote --tags "$url" "refs/tags/v$version")" ]]; then
    git push --quiet "$url" "$sha:refs/tags/v$version" || failed+=("$repo (tag v$version)")
  fi
done

if (( ${#failed[@]} > 0 )); then
  printf 'Not split: %s\n' "${failed[@]}" >&2
  exit 1
fi
