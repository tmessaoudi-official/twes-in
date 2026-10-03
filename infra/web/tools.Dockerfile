# syntax=docker/dockerfile:1
# The web tier's toolchain (compose.yaml, service `web-tools`): lint, formatting, unit tests, the production build and
# Playwright. It is Debian, not the Alpine of infra/web/Dockerfile, because Playwright's Chromium does not run on
# Alpine's libc. The Node version and the Playwright version are each written once more here, and
# scripts/gates/version-pins.sh refuses a copy that disagrees with infra/web/Dockerfile and web/package-lock.json.
FROM node:26.8.2-trixie-slim

ARG PLAYWRIGHT_VERSION=1.63.0

# Only the system libraries Chromium needs are installed here. The browser itself is downloaded by `make e2e`
# (`playwright-browser`, in the Makefile) into var/cache/ms-playwright of the working tree, on Docker's default network.
RUN npm install --global --no-audit --no-fund "playwright@${PLAYWRIGHT_VERSION}" \
 && playwright install-deps chromium \
 && rm -rf /var/lib/apt/lists/*
