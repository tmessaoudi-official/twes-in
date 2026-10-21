# syntax=docker/dockerfile:1
# The web tier's toolchain (compose.yaml, service `web-tools`): lint, formatting, unit tests, the production build and
# Playwright. It is Debian, not the Alpine of infra/web/Dockerfile, because Playwright's Chromium does not run on
# Alpine's libc. The Node version and the Playwright version are each written once more here, and
# scripts/gates/version-pins.sh refuses a copy that disagrees with infra/web/Dockerfile and web/package-lock.json.
FROM node:26.12.0-trixie-slim

ARG PLAYWRIGHT_VERSION=1.64.1

# Only the system libraries Chromium and WebKit need are installed here: WebKit is Safari's engine, which the phone
# scenarios also run on, since a shop's iPhones run no other. The browsers themselves are downloaded by `make e2e`
# (`playwright-browser`, in the Makefile) into var/cache/ms-playwright of the working tree, on Docker's default network.
RUN npm install --global --no-audit --no-fund "playwright@${PLAYWRIGHT_VERSION}" \
 && playwright install-deps chromium webkit \
 && rm -rf /var/lib/apt/lists/*
