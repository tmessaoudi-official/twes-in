#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every screen draws its colours from the theme's tokens and styles itself through classes (docs/SPEC.md § 7,
# 2026-09-19): a literal colour ignores the company accent, the dark scheme and the status tones, and a static
# inline style escapes both. Refuses, in web/src/app, a hex colour written as a value, a colour function (rgb, hsl,
# oklch…) and a static style="…" attribute. A [style.x] binding stays allowed: it carries geometry from data, or a
# token. The theme and the global stylesheet define the tokens and are not read; specs are not read. Reads the index
# and untracked files, so a file not yet staged counts. Usage: design-tokens.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
# A QR code's modules are fixed black on white, whatever the scheme, or a phone cannot read them; the default accent
# is the value a company starts with, which the theme then derives every token from.
exceptions=' web/src/app/auth/qr-code.ts web/src/app/shared/settings/settings-registry.ts '
files=()
while IFS= read -r file; do
  [[ "$file" == *.spec.ts || "$file" == web/src/app/shared/theme/* || "$exceptions" == *" $file "* ]] && continue
  files+=("$file")
done < <(git -C "$root" ls-files --cached --others --exclude-standard -- 'web/src/app/*.html' 'web/src/app/*.ts' 'web/src/app/*.scss' 'web/src/app/*.css' | sort -u)
wrong=()
if ((${#files[@]})); then
  mapfile -t wrong < <(cd "$root" && perl -ne '
    my $sheet = $ARGV =~ /\.s?css$/;
    my $why;
    $why = "colour function" if /\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/;
    $why //= "hex colour" if $sheet ? /:[^;{]*#[0-9a-fA-F]{3,8}\b/ : (/["\x27\x60]\s*#[0-9a-fA-F]{3,8}\s*["\x27\x60]/ || /:\s*#[0-9a-fA-F]{3,8}\b/);
    $why //= "inline style" if !$sheet && /(?:^|[\s<])style="/;
    print "$ARGV:$. $why\n" if defined $why;
    close ARGV if eof;
  ' "${files[@]}")
fi
if ((${#wrong[@]})); then
  printf 'design-tokens: FAIL — %d literal colour(s) or inline style(s) (fix: a token from shared/theme, or a class):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
printf 'design-tokens: OK — %d files take their colours from the theme, none styled inline\n' "${#files[@]}"
