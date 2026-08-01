<!--
This file is part of twes-in.

(c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Extra CA certificates for the build

Drop `*.crt` files here to have them trusted **inside the build stages**.

## Why this exists

Many corporate networks — and this project's own development container — sit behind a **TLS-intercepting proxy**.
Such a proxy presents its own certificate for every HTTPS connection, so `git`, `curl` and Composer inside a build
container fail certificate verification against hosts that work perfectly from the host machine. The symptom is
misleading: Composer exits `100` with a download failure that reads like the package server being unreachable.

The fix is to trust the proxy's CA in the build stage, which is what `infra/api/Dockerfile` does with the contents
of this directory. It is **build-time only** — no CA reaches the runtime image, because the runtime stage does not
copy this directory.

## Why it is not committed

`.gitignore` excludes `*.crt` here. A CA certificate is site-specific, and committing one would both be useless to
anyone else and imply a trust decision this repository has no business making on a reader's behalf.

## In this project's container

    cp /root/.ccr/ca-bundle.crt infra/api/ca-certificates/

Then build normally. Without it the build fails at the Composer step.
