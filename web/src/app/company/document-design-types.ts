// SPDX-License-Identifier: AGPL-3.0-or-later

/** The built-in layouts a document prints in (docs/SPEC.md § 7, 2026-10-06 10:19), as the API's `document.layout` names them. */
export type DocumentLayout = 'classic' | 'modern' | 'compact';
export const DOCUMENT_LAYOUTS: readonly DocumentLayout[] = ['classic', 'modern', 'compact'];

/**
 * How the company's documents print: a layout, an accent written `#rrggbb`, the logo's printed size in whole
 * millimetres (null until the settings are read, which leaves the form invalid), and whether the logo keeps its own
 * proportions in that size or is stretched to it.
 */
export interface DocumentDesign {
  readonly layout: DocumentLayout;
  readonly accent: string;
  readonly logoWidth: number | null;
  readonly logoHeight: number | null;
  readonly logoKeepsProportions: boolean;
}

/** The settings a design is kept in, at the company's level. */
export const DESIGN_SETTINGS = {
  layout: 'document.layout',
  accent: 'document.accent',
  logoWidth: 'document.logo_width',
  logoHeight: 'document.logo_height',
  logoKeepsProportions: 'document.logo_keep_proportions',
} as const;

/** The whole millimetres a logo's width and height may each be, as the API declares them. */
export interface LogoRoom {
  readonly width: { readonly min: number; readonly max: number };
  readonly height: { readonly min: number; readonly max: number };
}

/**
 * What the preview shows: a picture, one on its way, or why there is none — the company has no invoice yet
 * (`nothing`), the person may change the design but not read invoices (`forbidden`), or the picture failed.
 */
export type PreviewState = 'loading' | 'ready' | 'nothing' | 'forbidden' | 'failed' | 'network';
