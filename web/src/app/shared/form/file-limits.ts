// SPDX-License-Identifier: AGPL-3.0-or-later

const MEGABYTE = 1024 * 1024;

/** `CompanyLogo::MAX_BYTES` in the API: a logo is refused above it. */
export const LOGO_MAX_BYTES = 2 * MEGABYTE;

/** `app.files.upload_max_bytes` in `api/config/services.yaml`: an attachment is refused above it. */
export const ATTACHMENT_MAX_BYTES = 10 * MEGABYTE;

/**
 * An import has no limit of its own: the ceiling is the 12 MB body nginx (`infra/web/nginx.conf`) and PHP
 * (`infra/api/conf.d/10-app.ini`) accept, so a larger file never reaches the import.
 */
export const IMPORT_MAX_BYTES = 12 * MEGABYTE;
