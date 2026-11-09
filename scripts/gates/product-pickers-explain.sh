#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A product picker that finds nothing says why: the products switched off that the words match, the services where goods
# alone are offered, the goods whose stock is not kept, rather than « aucun produit » while the catalogue holds what was
# typed (`shared/unoffered/unoffered-products.ts`). Each picker hands its field an `explainNone`: an `<app-pick-field>`
# searching products binds `[explainNone]`, and a descriptor form's picker entry `search: searchProducts` or a list
# filter's `search: (words) => this.pickProducts(` names `explainNone:` within its entry, read to the brace closing it;
# or the element or entry says `explain-none: <why>`.
# Usage: product-pickers-explain.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=10
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "product-pickers-explain: unknown argument $1"; exit 2 ;;
  esac
done
app="$root/web/src/app"
if [[ ! -d "$app" ]]; then echo "product-pickers-explain: FAIL — found no web/src/app"; exit 1; fi

mapfile -t files < <(find "$app" -type f \( -name '*.html' -o -name '*.ts' \) ! -name '*.spec.ts' | sort)
found=0
problems=()
for file in "${files[@]}"; do
  # One line per product picker: its line number, then « ok » or « bare ».
  while read -r line verdict; do
    found=$((found + 1))
    [[ $verdict == bare ]] && problems+=("${file#"$root"/}:$line")
  done < <(awk '
    # A reason starts with a word: the element is read joined, so an empty one would take the next line`s « /> » for it.
    function said(text) { return text ~ /explain-none:[ \t]*[A-Za-z]/ }
    # A picker field, read whole from its tag to its end.
    /<app-pick-field/ { inside = 1; start = FNR; element = "" }
    inside {
      element = element " " $0
      if ($0 ~ /\/>|<\/app-pick-field>/) {
        inside = 0
        if (element ~ /\[search\]="[A-Za-z]*[Pp]roducts/) {
          print start, (element ~ /\[explainNone\]=/ || said(element)) ? "ok" : "bare"
        }
      }
      next
    }
    # A descriptor form picker entry, or a list filter searching the products: read from its search to the line
    # closing its entry, as prettier lays one property a line, never into the entry after it.
    /search: searchProducts|search: \(words\) => this\.pickProducts\(/ {
      if (pending) print pending, seen ? "ok" : "bare"
      seen = ($0 ~ /explainNone:/ || said($0))
      # More braces closing than opening after the search: the entry ends on this line.
      rest = substr($0, index($0, "search:")); opened = gsub(/\{/, "{", rest); closed = gsub(/\}/, "}", rest)
      if (closed > opened) { print FNR, seen ? "ok" : "bare"; pending = 0; next }
      pending = FNR; left = 5; next
    }
    pending {
      if ($0 ~ /^[ \t]*}/) { print pending, seen ? "ok" : "bare"; pending = 0; next }
      if ($0 ~ /explainNone:/ || said($0)) seen = 1
      if (--left == 0) { print pending, seen ? "ok" : "bare"; pending = 0 }
    }
    END { if (pending) print pending, seen ? "ok" : "bare" }
  ' "$file")
done

if ((${#problems[@]} > 0)); then
  printf 'product-pickers-explain: FAIL — a product picker that finding nothing would say only « aucun produit » (hand it an explainNone, UnofferedProducts, or say explain-none: <why>):\n'
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
if ((found < floor)); then
  printf 'product-pickers-explain: FAIL — only %d product pickers found, below the floor of %d: the search no longer finds them\n' "$found" "$floor"
  exit 1
fi
printf 'product-pickers-explain: OK — %d product pickers say why they found nothing\n' "$found"
