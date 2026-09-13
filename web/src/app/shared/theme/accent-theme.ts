// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  argbFromHex,
  DynamicScheme,
  Hct,
  hexFromArgb,
  SchemeTonalSpot,
} from '@material/material-color-utilities';

/**
 * The Material 3 system colour roles Angular Material reads as `--mat-sys-<role>` custom properties
 * (`@angular/material/core/tokens/m3/_md-sys-color.scss`). Any accent is turned into all of them at runtime, so
 * the accent is configuration, not a choice among palettes compiled into the stylesheet (docs/SPEC.md § 7,
 * 2026-09-13).
 */
export const SYSTEM_COLOUR_ROLES = [
  'background',
  'error',
  'error-container',
  'inverse-on-surface',
  'inverse-primary',
  'inverse-surface',
  'on-background',
  'on-error',
  'on-error-container',
  'on-primary',
  'on-primary-container',
  'on-primary-fixed',
  'on-primary-fixed-variant',
  'on-secondary',
  'on-secondary-container',
  'on-secondary-fixed',
  'on-secondary-fixed-variant',
  'on-surface',
  'on-surface-variant',
  'on-tertiary',
  'on-tertiary-container',
  'on-tertiary-fixed',
  'on-tertiary-fixed-variant',
  'outline',
  'outline-variant',
  'primary',
  'primary-container',
  'primary-fixed',
  'primary-fixed-dim',
  'scrim',
  'secondary',
  'secondary-container',
  'secondary-fixed',
  'secondary-fixed-dim',
  'shadow',
  'surface',
  'surface-bright',
  'surface-container',
  'surface-container-high',
  'surface-container-highest',
  'surface-container-low',
  'surface-container-lowest',
  'surface-dim',
  'surface-tint',
  'surface-variant',
  'tertiary',
  'tertiary-container',
  'tertiary-fixed',
  'tertiary-fixed-dim',
] as const;

export type SystemColourRole = (typeof SYSTEM_COLOUR_ROLES)[number];
export type ColourScheme = 'light' | 'dark';
export type ColourTokens = Record<`--mat-sys-${SystemColourRole}`, string>;

export class InvalidAccentColour extends Error {
  constructor(readonly value: string) {
    super(`The accent colour "${value}" is not a #rrggbb colour.`);
    this.name = 'InvalidAccentColour';
  }
}

const HEX_COLOUR = /^#[0-9a-f]{6}$/i;

/**
 * Every system colour role for one accent and one scheme. The tonal-spot scheme is Material's default: it keeps
 * the accent's hue, tames its chroma and guarantees readable contrast for text roles, whatever the accent is.
 */
export function colourTokens(accent: string, scheme: ColourScheme): ColourTokens {
  if (!HEX_COLOUR.test(accent)) {
    throw new InvalidAccentColour(accent);
  }
  const dynamic = new SchemeTonalSpot(
    Hct.fromInt(argbFromHex(accent.toLowerCase())),
    scheme === 'dark',
    0,
  );
  const tokens: Record<string, string> = {};
  for (const role of SYSTEM_COLOUR_ROLES) {
    tokens[`--mat-sys-${role}`] = hexFromArgb(roleArgb(dynamic, role)).toLowerCase();
  }
  return tokens as ColourTokens;
}

/** Writes the tokens as custom properties on an element; the document root themes the whole application. */
export function applyColourTokens(
  element: HTMLElement,
  tokens: Readonly<Record<string, string>>,
): void {
  for (const [name, value] of Object.entries(tokens)) {
    element.style.setProperty(name, value);
  }
}

/** `on-primary-fixed-variant` is read from the scheme's `onPrimaryFixedVariant` getter. */
function roleArgb(scheme: DynamicScheme, role: SystemColourRole): number {
  const getter = role.replace(/-([a-z])/g, (_, letter: string) => letter.toUpperCase());
  return (scheme as unknown as Record<string, number>)[getter];
}
