#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# XML from outside is parsed only where a document type is refused first: libxml2 has had its flaws in handling one
# (CVE-2026-6653, no fix in the image's Debian), and an uploaded workbook reached it (security map supply F3). A site is
# a line of api/src that parses XML: XMLReader (`XMLReader::from…`, `XMLReader::open`, `new XMLReader`, or `->open(` in a
# file naming XMLReader), SimpleXML (`simplexml_load…`, `new SimpleXMLElement(`), DOM (`DOMDocument::load…`,
# `->loadXML(`, `->load(` in a file naming DOMDocument, `Dom\XMLDocument::createFrom…`), `xml_parse(` and openspout's
# XLSX or ODS reader, which parse with XMLReader. Each file allowed below says why it may: it refuses a declared document
# type before parsing, or parses only XML the API itself wrote. An allowed file that no longer parses is refused too, so
# the list never outlives its reason. Usage: xml-reads.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
declare -A allowed=(
  [api/src/Shared/Infrastructure/Spreadsheet/OpenSpoutReader.php]="refuses a workbook any part of which declares a document type (refuseDeclaredDocumentTypes) before openspout opens it"
  [api/src/Shared/Infrastructure/Spreadsheet/XlsxPercentCells.php]="called by OpenSpoutReader alone, after its document type refusal"
)
problems=()
declare -A seen=()

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  direct='XMLReader::(from|open)|new[[:space:]]+\\?XMLReader\b|simplexml_load|new[[:space:]]+\\?SimpleXMLElement[[:space:]]*\(|DOMDocument::load|->loadXML[[:space:]]*\(|XMLDocument::createFrom|\bxml_parse[[:space:]]*\(|OpenSpout\\Reader\\(XLSX|ODS)'
  while IFS=: read -r file n line; do
    code=${line#"${line%%[![:space:]]*}"}
    # A comment that names a parser is not a parse.
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
    rel=${file#"$root/"}
    if [[ ! "$code" =~ $direct ]]; then
      # `->open(` and `->load(` parse XML only on an XMLReader or a DOMDocument; elsewhere they open a zip, a session.
      if [[ "$code" =~ -\>open[[:space:]]*\( ]]; then grep -q 'XMLReader' "$file" || continue
      elif [[ "$code" =~ -\>load[[:space:]]*\( ]]; then grep -q 'DOMDocument' "$file" || continue
      else continue
      fi
    fi
    seen[$rel]=1
    [[ -n "${allowed[$rel]:-}" ]] && continue
    problems+=("$rel:$n: ${code:0:110}")
  done < <(grep -rnE --include='*.php' 'XMLReader|simplexml_load|SimpleXMLElement|DOMDocument|loadXML|XMLDocument::createFrom|xml_parse|OpenSpout\\Reader\\(XLSX|ODS)|->open[[:space:]]*\(|->load[[:space:]]*\(' "$root/api/src")
  for rel in "${!allowed[@]}"; do
    [[ -n "${seen[$rel]:-}" ]] || problems+=("$rel is allowed but parses no XML any more: take it off the list")
  done
fi

if ((${#problems[@]})); then
  printf 'xml-reads: FAIL — %d XML parse site(s) not known to refuse a document type first (refuse <!DOCTYPE before parsing, or allow the file here with why it is safe):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'xml-reads: OK — XML is parsed in %d file(s), each refusing a document type first or reading only what the API wrote\n' "${#seen[@]}"
