// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The nine legal pages (docs/SPEC.md § 7, 2026-09-26 08:52, rows 147 and 148), in the order the footer names them. The
 * slug is the address, `/legal/<slug>`; the title is `legal.pages.<slug>`. Row 148 gives each its text, per language
 * and dated, edited by the platform operator.
 */
export const LEGAL_PAGES = [
  'mentions',
  'privacy',
  'cookies',
  'terms',
  'sales',
  'dpa',
  'source',
  'accessibility',
  'security',
] as const;

export type LegalPage = (typeof LEGAL_PAGES)[number];

export const LEGAL_ROUTE = '/legal';

export function isLegalPage(slug: string): slug is LegalPage {
  return (LEGAL_PAGES as readonly string[]).includes(slug);
}
