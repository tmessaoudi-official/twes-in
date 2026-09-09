# syntax=docker/dockerfile:1
# twes-in web: the Angular bundle built once, then served by nginx, which also proxies /api to the API container.
FROM node:26.8.1-alpine AS build
WORKDIR /app
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ ./
RUN npm run build -- --configuration production

FROM nginx:1.31.5-alpine
COPY infra/web/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist/web/browser /usr/share/nginx/html
HEALTHCHECK --interval=5s --timeout=3s --start-period=5s --retries=5 \
  CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1
