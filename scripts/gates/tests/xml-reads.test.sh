#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/xml-reads.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: the two allowed files parse XML as they do, and a zip and a session open elsewhere.
tree() {
  local d; d=$(mktemp -d); local s="$d/api/src/Shared/Infrastructure/Spreadsheet"; mkdir -p "$s" "$d/api/src/Other"
  printf '<?php\nuse OpenSpout\\Reader\\XLSX\\Reader as XlsxReader;\n$reader = new XlsxReader();\n$reader->open($path);\n' > "$s/OpenSpoutReader.php"
  printf '<?php\n$zip->open($path);\n$reader = \\XMLReader::fromString($xml, null, \\LIBXML_NONET);\n' > "$s/XlsxPercentCells.php"
  printf '<?php\n$zip = new \\ZipArchive();\n$zip->open($path);\n$this->pending->open($id);\n$this->load($key);\n' > "$d/api/src/Other/Plain.php"
  printf '<?php\n$document = new \\DOMDocument("1.0", "UTF-8");\n$document->appendChild($document->createElement("x"));\n$xml = $document->saveXML();\n' > "$d/api/src/Other/Writes.php"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "xml-reads gate"

d=$(tree); out=$(run "$d"); check "the allowed files, a zip, a session and a document written pass" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n$r = \\XMLReader::fromString($body);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "XMLReader::fromString elsewhere is refused with its file and line" $? 1 "$out" "Other/Feed.php:2"

d=$(tree); printf '<?php\n$r = \\XMLReader::open($path);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "XMLReader::open is refused" $? 1 "$out" "Feed.php:2"

d=$(tree); printf '<?php\n$r = new \\XMLReader();\n$r->open($path);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "->open( on an XMLReader is refused" $? 1 "$out" "Feed.php:3"

d=$(tree); printf '<?php\n$x = simplexml_load_string($body);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "simplexml_load_string is refused" $? 1 "$out" "Feed.php:2"

d=$(tree); printf '<?php\n$x = new \\SimpleXMLElement($body);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "new SimpleXMLElement( is refused" $? 1 "$out" "Feed.php:2"

d=$(tree); printf '<?php\n$d = new \\DOMDocument();\n$d->loadXML($body);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "->loadXML( is refused" $? 1 "$out" "Feed.php:3"

d=$(tree); printf '<?php\n$d = new \\DOMDocument();\n$d->load($path);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "->load( on a DOMDocument is refused" $? 1 "$out" "Feed.php:3"

d=$(tree); printf '<?php\n$d = \\Dom\\XMLDocument::createFromString($body);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "the new DOM API's createFromString is refused" $? 1 "$out" "Feed.php:2"

d=$(tree); printf '<?php\n$ok = xml_parse($parser, $body, true);\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "xml_parse is refused" $? 1 "$out" "Feed.php:2"

d=$(tree); printf '<?php\nuse OpenSpout\\Reader\\ODS\\Reader;\n' > "$d/api/src/Other/Feed.php"
out=$(run "$d"); check "openspout's ODS reader elsewhere is refused" $? 1 "$out" "Feed.php:2"

d=$(tree); printf '<?php\n/** Never simplexml_load_string() what a person sent. */\n// nor XMLReader::fromString\n' > "$d/api/src/Other/Doc.php"
out=$(run "$d"); check "a parser named in a comment is not a parse" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n$zip->open($path);\n' > "$d/api/src/Shared/Infrastructure/Spreadsheet/XlsxPercentCells.php"
out=$(run "$d"); check "an allowed file that parses nothing any more is refused" $? 1 "$out" "XlsxPercentCells.php is allowed but parses no XML"

d=$(tree); rm "$d/api/src/Shared/Infrastructure/Spreadsheet/OpenSpoutReader.php"
out=$(run "$d"); check "an allowed file that is gone is refused" $? 1 "$out" "OpenSpoutReader.php is allowed but parses no XML"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
