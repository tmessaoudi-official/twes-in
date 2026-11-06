#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every screen draws its colours from the theme's tokens and styles itself through classes (docs/SPEC.md § 7,
# 2026-09-19): a literal colour ignores the company accent, the dark scheme and the status tones, and a static
# inline style escapes both. Refuses, in web/src/app, a hex colour written as a value, a colour function (rgb, hsl,
# oklch…) and a static style="…" attribute. A [style.x] binding stays allowed: it carries geometry from data, or a
# token. Also refuses a colour utility (bg-, text-, border-…) naming a Material role web/src/tailwind.css does not
# define: Tailwind emits no rule for it, so the element silently has no colour (the home page's payment bars were
# invisible that way until 2026-09-19). The theme and the global stylesheet define the tokens and are not read; specs
# are not read. Reads the index and untracked files, so a file not yet staged counts. Usage: design-tokens.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
# A QR code's modules and a barcode's bars are fixed black on white, whatever the scheme, or a scanner cannot read
# them; the default accent is the value a company starts with, which the theme then derives every token from.
exceptions=' web/src/app/shared/qr/qr-code.ts web/src/app/shared/barcode/barcode-svg.ts web/src/app/shared/settings/settings-registry.ts '
files=()
while IFS= read -r file; do
  [[ "$file" == *.spec.ts || "$file" == web/src/app/shared/theme/* || "$exceptions" == *" $file "* ]] && continue
  files+=("$file")
done < <(git -C "$root" ls-files --cached --others --exclude-standard -- 'web/src/app/*.html' 'web/src/app/*.ts' 'web/src/app/*.scss' 'web/src/app/*.css' | sort -u)
wrong=()
# The colour roles Tailwind knows: every --color-<role> the theme block of web/src/tailwind.css declares.
tokens=$(grep -oE -- '--color-[a-z-]+' "$root/web/src/tailwind.css" 2>/dev/null | sed 's/^--color-//' | tr '\n' ' ')
if ((${#files[@]})); then
  mapfile -t wrong < <(cd "$root" && TOKENS="$tokens" perl -ne '
    BEGIN { %known = map { $_ => 1 } split " ", $ENV{TOKENS} }
    my $sheet = $ARGV =~ /\.s?css$/;
    my $why;
    $why = "colour function" if /\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/;
    $why //= "hex colour" if $sheet ? /:[^;{]*#[0-9a-fA-F]{3,8}\b/ : (/["\x27\x60]\s*#[0-9a-fA-F]{3,8}\s*["\x27\x60]/ || /:\s*#[0-9a-fA-F]{3,8}\b/);
    $why //= "inline style" if !$sheet && /(?:^|[\s<])style="/;
    if (!$sheet && !defined $why) {
      while (/(?<![\w-])(?:bg|text|border|ring|outline|fill|stroke|decoration|divide|from|via|to|accent|caret|placeholder)-((?:on-)?(?:primary|secondary|tertiary|error|surface|outline|inverse|scrim|accent)[a-z-]*)/g) {
        (my $role = $1) =~ s/-$//;
        if (!$known{$role}) { $why = "unknown colour token $role"; last; }
      }
    }
    print "$ARGV:$. $why\n" if defined $why;
    close ARGV if eof;
  ' "${files[@]}")
fi
# The theme is closed (docs/SPEC.md § 7, 2026-11-02 00:32 and 00:39): every type step, weight, family, radius, shadow,
# line height, tracking, easing and colour a class names is one web/src/tailwind.css declares, so a new identity is a
# change of its values and the templates stay as they are. Read in class contexts only (a template's class attribute,
# a [class.x] or [ngClass] binding, a TypeScript string with its comments taken out), so a word in prose is no class.
# An arbitrary value in those namespaces is refused, as is a style binding of a font, radius, shadow or motion property;
# geometry (widths, grids, z) keeps its arbitrary values. DESIGN_TOKENS_FLOOR: the fewest such classes a tree reads.
floor=${DESIGN_TOKENS_FLOOR:-1910}
steps=$(perl -ne '
  next unless /^\s*--(color|text|font-weight|font|radius|shadow|leading|tracking|ease)-([a-z0-9-]+)\s*:/;
  my ($space, $name) = ($1, $2); next if $name =~ /--/;
  my %prefix = (color => "color", text => "text", "font-weight" => "font", font => "font", radius => "rounded", shadow => "shadow", leading => "leading", tracking => "tracking", ease => "ease");
  print "$prefix{$space}-$name\n";
' "$root/web/src/tailwind.css" 2>/dev/null | tr '\n' ' ')
templates=()
for file in "${files[@]}"; do [[ "$file" == *.html || "$file" == *.ts ]] && templates+=("$file"); done
read_count=0
if ((${#templates[@]})); then
  scale_out=$(cd "$root" && STEPS="$steps" perl -0777 -ne '
    BEGIN {
      %declared = map { $_ => 1 } split " ", $ENV{STEPS};
      %colour = map { s/^color-//r => 1 } grep { /^color-/ } keys %declared;
      %text_keyword = map { $_ => 1 } qw(left center right justify start end ellipsis clip wrap nowrap balance pretty transparent current inherit);
      %property = map { $_ => 1 } qw(font-size font-family font-weight font-style font-variant font-feature-settings font-variation-settings font-display font-stretch text-align text-decoration text-transform text-overflow text-indent text-anchor text-rendering text-shadow text-wrap-mode leading-trim);
      $palette = qr/^(?:black|white|(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d+)$/;
      $count = 0;
    }
    my $text = $_;
    my $html = $ARGV =~ /\.html$/;
    my @spans;
    # Each class context: its text and where it starts, so a token is reported at its own line.
    if ($html) {
      while ($text =~ /(?:\bclass|\[class\]|\[ngClass\])="([^"]*)"/g) { push @spans, [$-[1], $1]; }
    } else {
      (my $code = $text) =~ s{/\*.*?\*/}{ $& =~ s/[^\n]/ /gr }gse;
      $code =~ s{(^|[\s;{}(),])//[^\n]*}{ $1 . (" " x (length($&) - length($1))) }gme;
      while ($code =~ /(["\x27`])((?:\\.|(?!\1).)*)\1/gs) {
        my ($start, $string) = ($-[2], $2);
        # An inline template is read as a template: its class attributes, never its markup.
        if ($1 eq "`" && $string =~ /<[a-zA-Z]/) {
          while ($string =~ /(?:\bclass|\[class\]|\[ngClass\])="([^"]*)"/g) { push @spans, [$start + $-[1], $1]; }
        } else {
          push @spans, [$start, $string];
        }
      }
    }
    while ($text =~ /\[class\.([^\]=\s]+)\]/g) { push @spans, [$-[1], $1]; }
    my (%step_at, %arbitrary_at, %style_at, %primary_at);
    for my $span (@spans) {
      my ($start, $value) = @$span;
      while ($value =~ /(\S+)/g) {
        my $raw = $1; my $at = $start + $-[1];
        my $line = 1 + (substr($text, 0, $at) =~ tr/\n//);
        next if $raw =~ /\$\{|\{\{/;
        (my $class = $raw) =~ s/^[\x27"`{(]+//;
        $class =~ s/[\x27"`})>,;]+$//;
        $class =~ s/^[!-]+//;
        $class =~ s/^(?:[^:\[\]]+:)+//;
        $class =~ s/\/[\w.]+$//;
        next if $property{$class};
        # Primary is the component chrome of Material, tuned for the grounds of the accent scheme: a word, a link or an icon
        # read as text is the accent text, which is held to 4.5:1 on every ground the theme draws.
        if ($class eq "text-primary") { push @{$primary_at{$line}}, $class; next; }
        if ($class =~ /^(?:text|font|leading|tracking|rounded(?:-[a-z]{1,2})?|shadow|duration|delay|ease|bg|border|ring|outline|fill|stroke|accent|caret|decoration|divide|placeholder)-[\[(]/) {
          push @{$arbitrary_at{$line}}, $class; next;
        }
        my $ok = 1; my $mine = 0;
        if ($class =~ /^text-(.+)$/) { $mine = 1; $ok = $declared{"text-$1"} || $colour{$1} || $text_keyword{$1}; }
        elsif ($class =~ /^font-(.+)$/) { $mine = 1; $ok = $declared{"font-$1"}; }
        elsif ($class =~ /^rounded(?:-(?:t|r|b|l|s|e|tl|tr|br|bl|ss|se|es|ee))?(?:-(.+))?$/) { $mine = 1; $ok = defined $1 && ($1 eq "none" || $1 eq "full" || $declared{"rounded-$1"}); }
        elsif ($class =~ /^shadow(?:-(.+))?$/) { $mine = 1; $ok = defined $1 && ($1 eq "none" || $declared{"shadow-$1"} || $colour{$1}); }
        elsif ($class =~ /^leading-(.+)$/) { $mine = 1; $ok = $1 eq "none" || $1 =~ /^\d+(?:\.5)?$/ || $declared{"leading-$1"}; }
        elsif ($class =~ /^tracking-(.+)$/) { $mine = 1; $ok = $declared{"tracking-$1"}; }
        elsif ($class =~ /^ease-(.+)$/) { $mine = 1; $ok = $1 eq "linear" || $1 eq "initial" || $declared{"ease-$1"}; }
        elsif ($class =~ /^(?:bg|border(?:-[trblxyse])?|ring|outline|fill|stroke|divide|decoration|placeholder|caret|accent|from|via|to)-(.+)$/ && $1 =~ $palette) { $ok = 0; }
        $count++ if $mine;
        push @{$step_at{$line}}, $class unless $ok;
      }
    }
    while ($text =~ /\[style\.((?:font|letter-spacing|line-height|border(?:-[a-z]+)*-radius|box-shadow|transition|animation)[a-z-]*)(?:\.[a-z%]+)?\]/g) {
      my $line = 1 + (substr($text, 0, $-[0]) =~ tr/\n//);
      push @{$style_at{$line}}, $1;
    }
    for my $line (sort { $a <=> $b } keys %step_at) { print "$ARGV:$line undeclared step " . join(", ", @{$step_at{$line}}) . "\n"; }
    for my $line (sort { $a <=> $b } keys %arbitrary_at) { print "$ARGV:$line arbitrary value " . join(", ", @{$arbitrary_at{$line}}) . "\n"; }
    for my $line (sort { $a <=> $b } keys %style_at) { print "$ARGV:$line style binding " . join(", ", @{$style_at{$line}}) . "\n"; }
    for my $line (sort { $a <=> $b } keys %primary_at) { print "$ARGV:$line primary where an accent role is meant: " . join(", ", @{$primary_at{$line}}) . " (text-accent-text)\n"; }
    END { print "COUNT $count\n"; }
  ' "${templates[@]}")
  read_count=$(sed -n 's/^COUNT //p' <<<"$scale_out")
  while IFS= read -r line; do [[ -n "$line" && "$line" != COUNT* ]] && wrong+=("$line"); done <<<"$scale_out"
fi
# A heading takes its role and a card its kind: an h1 or h2 names no raw text size (text-xs to text-4xl, behind a
# variant too, even a declared one) but a role step, text-title for a page, text-heading or text-section below it; a
# mat-card or a twes-card names no raw radius (rounded-sm to rounded-3xl) but rounded-card. A tag is read whole, over
# its lines, in templates and in the inline templates of TypeScript. A heading in a role is one naming a role step, a
# dialog title (styles.scss gives mat-dialog-title the heading's step) or a rail's overline (it reads text-overline).
# DESIGN_TOKENS_HEADINGS_FLOOR and DESIGN_TOKENS_CARDS_FLOOR: the fewest of each a tree reads, so a parser that stops
# finding them reds rather than passing over nothing.
headings_floor=${DESIGN_TOKENS_HEADINGS_FLOOR:-214}
cards_floor=${DESIGN_TOKENS_CARDS_FLOOR:-88}
headings_read=0; cards_read=0
if ((${#templates[@]})); then
  role_out=$(cd "$root" && perl -0777 -ne '
    BEGIN { $headings = 0; $cards = 0; }
    my $text = $_;
    my @regions;
    if ($ARGV =~ /\.html$/) { @regions = ([0, $text]); }
    else {
      while ($text =~ /`([^`]*)`/gs) {
        my ($start, $string) = ($-[1], $1);
        push @regions, [$start, $string] if $string =~ /<[a-zA-Z]/;
      }
    }
    my $attrs = qr/(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*/;
    for my $region (@regions) {
      my ($offset, $body) = @$region;
      while ($body =~ /<([a-z][\w-]*)\b($attrs)>/g) {
        my ($tag, $inside, $at) = ($1, $2, $offset + $-[0]);
        my $classes = join " ", ($inside =~ /(?:^|\s)class="([^"]*)"/g), ($inside =~ /\[class\.([^\]=\s]+)\]/g);
        my $heading = $tag eq "h1" || $tag eq "h2";
        my $card = $tag eq "mat-card" || $classes =~ /(?:^|\s)twes-card(?:\s|$)/;
        next unless $heading || $card;
        my $line = 1 + (substr($text, 0, $at) =~ tr/\n//);
        if ($heading) {
          my @raw = $classes =~ /(?:^|\s)((?:[\w-]+:)*text-(?:xs|sm|base|lg|xl|2xl|3xl|4xl))(?=\s|$)/g;
          print "$ARGV:$line heading with a raw text size: " . join(", ", @raw) . " (a role: text-title, text-heading or text-section)\n" if @raw;
          $headings++ if $classes =~ /(?:^|\s)(?:[\w-]+:)*(?:text-(?:title|heading|section|overline|display)|twes-rail-overline)(?:\s|$)/ || $inside =~ /(?:^|\s)mat-dialog-title(?:[\s=]|$)/;
        }
        if ($card) {
          $cards++;
          my @raw = $classes =~ /(?:^|\s)((?:[\w-]+:)*rounded(?:-(?:t|r|b|l|s|e|tl|tr|br|bl|ss|se|es|ee))?-(?:sm|md|lg|xl|2xl|3xl))(?=\s|$)/g;
          print "$ARGV:$line card with a raw radius: " . join(", ", @raw) . " (rounded-card)\n" if @raw;
        }
      }
    }
    END { print "HEADINGS $headings\nCARDS $cards\n"; }
  ' "${templates[@]}")
  headings_read=$(sed -n 's/^HEADINGS //p' <<<"$role_out")
  cards_read=$(sed -n 's/^CARDS //p' <<<"$role_out")
  while IFS= read -r line; do [[ -n "$line" && "$line" != HEADINGS* && "$line" != CARDS* ]] && wrong+=("$line"); done <<<"$role_out"
fi
# Stylesheets read the same steps: web/src/styles.scss and every component stylesheet, outside @font-face (where a
# family is declared) and the custom properties that define a token. A type size, weight or radius is a var() or a
# keyword; a family a var() (or the icon font, which names an icon set, not a type choice); a shadow draws geometry
# only with var() colours; a transition or an animation names no duration of its own; a Material override's shape or
# weight is no bare literal. A component's inline `styles:` is a stylesheet too, read alone in its file.
sheets=("web/src/styles.scss")
for file in "${files[@]}"; do
  if [[ "$file" == *.scss || "$file" == *.css ]]; then
    sheets+=("$file")
  elif [[ "$file" == *.ts && "$file" != *.spec.ts && -f "$root/$file" ]] && grep -q 'styles:' "$root/$file"; then
    sheets+=("$file")
  fi
done
existing=()
for file in "${sheets[@]}"; do [[ -f "$root/$file" ]] && existing+=("$file"); done
if ((${#existing[@]})); then
  mapfile -t -O "${#wrong[@]}" wrong < <(cd "$root" && perl -0777 -ne '
    my $text = $_;
    my $blank = sub { $_[0] =~ s/[^\n]/ /gr };
    if ($ARGV =~ /\.ts$/) {
      my $kept = $blank->($text);
      while ($text =~ /\bstyles\s*:\s*\[?\s*`([^`]*)`/gs) { substr($kept, $-[1], $+[1] - $-[1]) = $1; }
      $text = $kept;
    }
    $text =~ s{/\*.*?\*/}{ $blank->($&) }gse;
    $text =~ s{(^|[\s;{}(,])//[^\n]*}{ $1 . $blank->(substr($&, length $1)) }gme;
    $text =~ s{\@font-face\s*\{[^{}]*\}}{ $blank->($&) }gse;
    my %at;
    my $line_of = sub { 1 + (substr($text, 0, $_[0]) =~ tr/\n//) };
    my $keyword = qr/^(?:0|none|inherit|initial|unset|normal|50%|!important)$/;
    while ($text =~ /(?<![\w-])(font-size|font-weight|font-family|border-radius|box-shadow|transition|animation)\s*:\s*([^;{}]*);/gs) {
      my ($property, $value, $at) = ($1, $2, $-[0]);
      $value =~ s/\s+/ /g; $value =~ s/^ | $//g;
      my $ok;
      if ($property =~ /^(?:font-size|font-weight|border-radius)$/) {
        (my $rest = $value) =~ s/var\([^()]*(?:\([^()]*\)[^()]*)*\)/ /g;
        $ok = !grep { $_ !~ $keyword } split " ", $rest;
      } elsif ($property eq "font-family") {
        $ok = $value =~ /^(?:var\(--[\w-]+\)|inherit|initial)$/ || $value =~ /^["\x27]Material Symbols Outlined["\x27]$/;
      } elsif ($property eq "box-shadow") {
        $ok = $value =~ /^(?:none|inherit)(?: !important)?$/ || ($value =~ /var\(|\btransparent\b|\bcurrentColor\b/ && $value !~ /\b(?:rgba?|hsla?|oklch|oklab|lab|lch|hwb)\(|#[0-9a-fA-F]{3,8}\b/);
      } else {
        $ok = $value =~ /^none(?: !important)?$/ || $value !~ /(?<![\w.-])\d*\.?\d+m?s\b/;
      }
      push @{$at{$line_of->($at)}}, $property unless $ok;
    }
    while ($text =~ /(?<![\w-])([a-z][a-z-]*-(?:shape|weight))\s*:\s*([^,;(){}\s][^,;(){}]*?)\s*[,)]/g) {
      push @{$at{$line_of->($-[0])}}, $1;
    }
    print "$ARGV:$_ literal " . join(", ", @{$at{$_}}) . "\n" for sort { $a <=> $b } keys %at;
    # Primary as a text colour, or mixed into a ground: the accent text and the derived grounds (accent-hover, -soft,
    # -strip, each over the ground it is drawn on) are what clear their contrast on every ground of the theme.
    my %primary;
    while ($text =~ /(?<![\w-])color\s*:\s*var\(\s*--mat-sys-primary\s*\)/g) { push @{$primary{$line_of->($-[0])}}, "color (var(--twes-accent-text))"; }
    while ($text =~ /color-mix\([^;{}]*?var\(\s*--mat-sys-primary\s*\)/g) { push @{$primary{$line_of->($-[0])}}, "color-mix (a derived ground: --twes-accent-hover, -soft, -strip)"; }
    print "$ARGV:$_ primary where an accent role is meant: " . join(", ", @{$primary{$_}}) . "\n" for sort { $a <=> $b } keys %primary;
  ' "${existing[@]}")
fi
if ((${#wrong[@]})); then
  printf 'design-tokens: FAIL — %d literal colour(s), inline style(s), unknown token(s), undeclared step(s), arbitrary value(s) or style binding(s) (fix: a token from shared/theme or web/src/tailwind.css, or a class):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
if ((read_count < floor)); then
  printf 'design-tokens: FAIL — %d type, radius and shadow classes read, below the floor of %d: a parser that finds nothing is no pass\n' "$read_count" "$floor"
  exit 1
fi
if ((headings_read < headings_floor)); then
  printf 'design-tokens: FAIL — %d headings in a role, below the floor of %d: the h1 and h2 are no longer found, or one was removed on purpose (then set DESIGN_TOKENS_HEADINGS_FLOOR to the new count)\n' "$headings_read" "$headings_floor"
  exit 1
fi
if ((cards_read < cards_floor)); then
  printf 'design-tokens: FAIL — %d cards read, below the floor of %d: the mat-card and twes-card are no longer found, or one was removed on purpose (then set DESIGN_TOKENS_CARDS_FLOOR to the new count)\n' "$cards_read" "$cards_floor"
  exit 1
fi
printf 'design-tokens: OK — %d files take their colours from the theme, none styled inline; %d type, radius and shadow classes read, each a declared step; %d headings in a role, %d cards in their corner\n' "${#files[@]}" "$read_count" "$headings_read" "$cards_read"
