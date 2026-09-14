// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  argbFromHex,
  DynamicScheme,
  Hct,
  hexFromArgb,
  SchemeFidelity,
  TonalPalette,
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
 * Every system colour role for one accent and one scheme. The fidelity scheme keeps the accent's chroma, so the colour
 * a company picks shows as picked (docs/SPEC.md § 7, 2026-09-14: tonal spot rendered #1f6feb as a muted slate), and
 * still guarantees readable contrast for text roles, whatever the accent is.
 */
export function colourTokens(accent: string, scheme: ColourScheme): ColourTokens {
  assertAccentColour(accent);
  const dynamic = new SchemeFidelity(
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

/** Throws InvalidAccentColour unless the value is #rrggbb; the one place that rule lives. */
export function assertAccentColour(value: string): void {
  if (!HEX_COLOUR.test(value)) {
    throw new InvalidAccentColour(value);
  }
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

/** The tones a status badge takes: `accent` follows the company's accent, the others keep their own hue. */
export const STATUS_TONES = ['accent', 'green', 'amber', 'red', 'neutral'] as const;
export type StatusTone = (typeof STATUS_TONES)[number];
export type StatusTokens = Record<`--twes-status-${StatusTone}-${'bg' | 'fg' | 'dot'}`, string>;

/** Hue and chroma of the tones that do not follow the accent, as the design canvas computed them. */
const FIXED_TONES: Record<Exclude<StatusTone, 'accent'>, readonly [number, number]> = {
  green: [150, 40],
  amber: [70, 45],
  red: [25, 60],
  neutral: [75, 4],
};

/**
 * Each status tone's badge colours in one scheme: a soft tone behind, a strong one for the label and the dot, far
 * enough apart in lightness that the label reads on its badge whatever the hue ("quiet ledger": colour marks status).
 */
export function statusTokens(accent: string, scheme: ColourScheme): StatusTokens {
  assertAccentColour(accent);
  const source = Hct.fromInt(argbFromHex(accent.toLowerCase()));
  const dark = scheme === 'dark';
  const tokens: Record<string, string> = {};
  for (const tone of STATUS_TONES) {
    const [hue, chroma] = tone === 'accent' ? [source.hue, source.chroma] : FIXED_TONES[tone];
    const strong = TonalPalette.fromHueAndChroma(hue, chroma);
    const soft = TonalPalette.fromHueAndChroma(hue, Math.min(chroma, 18));
    tokens[`--twes-status-${tone}-bg`] = hexFromArgb(soft.tone(dark ? 22 : 94)).toLowerCase();
    tokens[`--twes-status-${tone}-fg`] = hexFromArgb(strong.tone(dark ? 88 : 30)).toLowerCase();
    tokens[`--twes-status-${tone}-dot`] = hexFromArgb(strong.tone(dark ? 70 : 50)).toLowerCase();
  }
  return tokens as StatusTokens;
}
