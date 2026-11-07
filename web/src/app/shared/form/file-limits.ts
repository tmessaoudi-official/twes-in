// SPDX-License-Identifier: AGPL-3.0-or-later

import type { TranslateService } from '@ngx-translate/core';

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

/** What each accepted type is called on screen, by the MIME type or extension a file input's `accept` names it with. */
const TYPE_NAMES: Readonly<Record<string, string>> = {
  'application/pdf': 'PDF',
  'image/png': 'PNG',
  'image/jpeg': 'JPEG',
  'image/webp': 'WebP',
  'text/csv': 'CSV',
  '.csv': 'CSV',
  '.xlsx': 'XLSX',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
};

/**
 * The types a file may be, by the names a person knows them by, each once: the zone that takes a file says them before
 * anything is chosen, and a refusal of the API names them the same way. A type with no name of its own is said by its
 * extension, in capitals.
 */
export function typeNames(tokens: readonly string[]): string {
  return [
    ...new Set(tokens.map((token) => TYPE_NAMES[token] ?? token.replace(/^\./, '').toUpperCase())),
  ].join(', ');
}

/** The most a file holds, as a person reads it: in megabytes from one, else in kilobytes. */
export function sizeText(translate: TranslateService, bytes: number): string {
  return bytes >= MEGABYTE
    ? translate.instant('form.file.mb', { count: Math.round(bytes / MEGABYTE) })
    : translate.instant('form.file.kb', { count: Math.round(bytes / 1024) });
}
