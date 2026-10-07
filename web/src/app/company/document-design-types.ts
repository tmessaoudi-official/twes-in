// SPDX-License-Identifier: AGPL-3.0-or-later

/** The built-in layouts a document prints in (docs/SPEC.md § 7, 2026-10-06 10:19), as the API's `document.layout` names them. */
export type DocumentLayout = 'classic' | 'modern' | 'compact';
export const DOCUMENT_LAYOUTS: readonly DocumentLayout[] = ['classic', 'modern', 'compact'];

/** How the company's documents print: a layout and an accent written `#rrggbb`. */
export interface DocumentDesign {
  readonly layout: DocumentLayout;
  readonly accent: string;
}

/** The settings a design is kept in, at the company's level. */
export const DESIGN_SETTINGS = { layout: 'document.layout', accent: 'document.accent' } as const;

/**
 * What the preview shows: a picture, one on its way, or why there is none — the company has no invoice yet
 * (`nothing`), the person may change the design but not read invoices (`forbidden`), or the picture failed.
 */
export type PreviewState = 'loading' | 'ready' | 'nothing' | 'forbidden' | 'failed' | 'network';
