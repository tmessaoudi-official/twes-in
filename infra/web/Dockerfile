# syntax=docker/dockerfile:1
# twes-in web: the Angular bundle built once, then served by nginx, which also proxies /api to the API container.
# Node is named once here (scripts/gates/version-pins.sh reads this line); both stages that run it start from it.
FROM node:26.10.0-alpine AS node-base

FROM node-base AS build
WORKDIR /app
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ ./
# The TypeScript types under src/app/api are generated, gitignored and kept out of the build context
# (.dockerignore): they come from the api image's exported OpenAPI document, so the bundle always matches the
# API it is built with. `api` is the additional build context compose.yaml points at the api service.
# `api` is not a stage of this file: it is the additional build context compose.yaml binds to the api service.
# hadolint ignore=DL3022
COPY --from=api /app/var/openapi.json /openapi.json
RUN OPENAPI_JSON=/openapi.json npm run api:types \
 && npm run build -- --configuration production

# Live development (compose.live.yaml, `make up`): the Angular dev server over web/ mounted from the host, so an edit
# shows without a rebuild. Its node_modules and build cache are volumes of their own, installed here for this image's
# libc, never the host's; both belong to the image's `node` user, uid 1000, which also owns what it writes into the mounted source.
FROM node-base AS live
WORKDIR /app
RUN mkdir -p node_modules .angular && chown 1000:1000 /app node_modules .angular
COPY --chmod=755 infra/web/live.sh /usr/local/bin/web-live
USER 1000:1000
CMD ["web-live"]
# The first start installs the dependencies before it serves: minutes, not seconds.
HEALTHCHECK --interval=5s --timeout=3s --start-period=600s --retries=5 \
  CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1

FROM nginx:1.31.6-alpine
COPY infra/web/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist/web/browser /usr/share/nginx/html
HEALTHCHECK --interval=5s --timeout=3s --start-period=5s --retries=5 \
  CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1
