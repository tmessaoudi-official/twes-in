#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A page the menu hides from a role is also closed to that role when reached another way: switching company while on
# it, a bookmark, a link a colleague sent (hunt P-2: a clerk on « Fournisseurs » met a list that failed to load and
# still offered « Exporter »). Every declaration in a manifest (an array named *_NAV, *_COMMANDS or *_TABS in
# web/src/app/**/*-nav.ts or web/src/app/shell/nav-manifest.ts: a navigation entry, a palette command, a page tab;
# a guided tour's steps are not pages) that names a route and needs a permission or a role is held to its
# route in web/src/app/app.routes.ts carrying `permissionGuard(<THAT_MANIFEST>, '<that route>')`, so the guard reads the
# very declaration the menu reads. Every route below a guarded one (a record page, `/vendors/:vendorId`) carries a
# `permissionGuard` too. A route left open says why inside its route object with `// route-open: <why>`. A guard naming
# a declaration that no longer exists is named as well. Fewer declarations than the floor means the reading broke.
# Usage: route-permissions.sh [--root DIR] [--min N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=40
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    *) echo "route-permissions: unknown argument $1" >&2; exit 2 ;;
  esac
done
routes=web/src/app/app.routes.ts
mapfile -t manifests < <(git -C "$root" ls-files -- 'web/src/app/*-nav.ts' 'web/src/app/shell/nav-manifest.ts' | grep -v '\.spec\.ts$')
if [[ ! -f "$root/$routes" ]]; then
  echo "route-permissions: FAIL — $routes not found"
  exit 1
fi
# One line per declaration needing something: NAME<TAB>/route
mapfile -t declared < <(cd "$root" && perl -0777 -ne '
  # The object literals at depth one of each exported array.
  while (/export\s+const\s+(\w+)\b[^=\n]*=\s*\[/g) {
    my ($name, $at, $depth, $object, $start) = ($1, pos($_), 1, "", -1);
    for (my $i = $at; $i < length($_) && $depth > 0; $i++) {
      my $c = substr($_, $i, 1);
      if ($c eq "[" || $c eq "{") { $start = $i if $c eq "{" && $depth == 1; $depth++ }
      elsif ($c eq "]" || $c eq "}") {
        $depth--;
        if ($c eq "}" && $depth == 1 && $start >= 0) {
          my $body = substr($_, $start, $i - $start + 1);
          print "$name\t$1\n" if $name =~ /_(?:NAV|COMMANDS|TABS)\z/ && $body =~ /\b(?:permission|role):/ && $body =~ /\broute:\s*\x27([^\x27]+)\x27/;
          $start = -1;
        }
      }
    }
  }
' "${manifests[@]}" /dev/null | sort -u)
if ((${#declared[@]} < min)); then
  echo "route-permissions: FAIL — ${#declared[@]} declarations needing a permission read from the manifests, fewer than $min: the reading broke"
  exit 1
fi
result=$(cd "$root" && printf '%s\n' "${declared[@]}" | perl -0777 -e '
  my %declared; my @order;
  for (split /\n/, <STDIN>) { my ($name, $route) = split /\t/; push @{$declared{$route}}, $name; push @order, [$name, $route] }
  local $/; open my $fh, "<", $ARGV[0] or die; my $src = <$fh>;
  # Each route object: from the brace opening it to the brace closing it, found around its `path:`.
  my %objects;
  while ($src =~ /\bpath:\s*\x27([^\x27]*)\x27/g) {
    my ($path, $at) = ($1, $-[0]);
    my ($depth, $open) = (0, -1);
    for (my $i = $at; $i >= 0; $i--) {
      my $c = substr($src, $i, 1);
      if ($c eq "}") { $depth++ } elsif ($c eq "{") { if ($depth == 0) { $open = $i; last } $depth-- }
    }
    next if $open < 0;
    ($depth, my $close) = (0, -1);
    for (my $i = $open; $i < length($src); $i++) {
      my $c = substr($src, $i, 1);
      if ($c eq "{") { $depth++ } elsif ($c eq "}") { $depth--; if ($depth == 0) { $close = $i; last } }
    }
    my $body = substr($src, $open, $close - $open + 1);
    # A route with children frames them: what its children carry is not its own.
    next if $body =~ /\bchildren:/;
    my $line = 1 + (substr($src, 0, $at) =~ tr/\n//);
    push @{$objects{$path}}, [$body, $line];
  }
  my $open_ok = sub { $_[0] =~ m{//\s*route-open:\s*\S} };
  my %guarded;
  for my $declaration (@order) {
    my ($name, $route) = @$declaration;
    (my $path = $route) =~ s{^/}{};
    my $found = $objects{$path};
    unless ($found) { print "NOROUTE $name \x27$route\x27: no route with path \x27$path\x27 in $ARGV[0]\n"; next }
    for my $object (@$found) {
      my ($body, $line) = @$object;
      if ($body =~ /permissionGuard\(\s*\Q$name\E\s*,\s*\x27\Q$route\E\x27\s*\)/) { $guarded{$path} = 1 }
      elsif ($open_ok->($body)) { }
      else { print "UNGUARDED $ARGV[0]:$line \x27$path\x27 lacks permissionGuard($name, \x27$route\x27)\n" }
    }
  }
  # A route below a guarded one opens the same subject: it carries a guard too, or says why not.
  for my $path (sort keys %objects) {
    next unless grep { index($path, "$_/") == 0 } keys %guarded;
    for my $object (@{$objects{$path}}) {
      my ($body, $line) = @$object;
      if ($body =~ /permissionGuard\(/) { }
      elsif (!$open_ok->($body)) { print "UNGUARDED $ARGV[0]:$line \x27$path\x27 sits below a guarded route and carries no permissionGuard\n" }
    }
  }
  # A guard naming a declaration that is gone would throw at start; named here first.
  while ($src =~ /permissionGuard\(\s*(\w+)\s*,\s*\x27([^\x27]+)\x27\s*\)/g) {
    my ($name, $route) = ($1, $2);
    print "STALE permissionGuard($name, \x27$route\x27): $name declares no \x27$route\x27 needing a permission\n"
      unless grep { $_ eq $name } @{$declared{$route} // []};
  }
  my @bodies = map { $_->[0] } map { @$_ } values %objects;
  print "CHECKED ", scalar(grep { /permissionGuard\(/ } @bodies), " ", scalar(grep { $open_ok->($_) } @bodies), "\n";
' "$routes")
problems=$(grep -v '^CHECKED ' <<<"$result")
if [[ -n "$problems" ]]; then
  echo "route-permissions: FAIL — a page the menu hides from a role must be closed to it (permissionGuard), or say \`// route-open: <why>\`:"
  sed 's/^/  /' <<<"$problems"
  exit 1
fi
read -r _ guarded open <<<"$(grep '^CHECKED ' <<<"$result")"
printf 'route-permissions: OK — %d declarations, %d routes guarded, %d open with their reason\n' "${#declared[@]}" "$guarded" "$open"
