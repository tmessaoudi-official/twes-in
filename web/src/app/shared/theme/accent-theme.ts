// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  argbFromHex,
  DynamicScheme,
  Hct,
  hexFromArgb,
  SchemeFidelity,
} from '@material/material-color-utilities';

import { assertAccentColour, type ColourScheme } from './colour-math';
import { DEFAULT_THEME_PRESET, themeRoles, type ThemePreset } from './neutral-ramp';

export {
  assertAccentColour,
  type ColourScheme,
  InvalidAccentColour,
  oklchToHex,
  STATUS_TONES,
  type StatusTone,
  type StatusTokens,
  statusTokens,
} from './colour-math';

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
export type ColourTokens = Record<`--mat-sys-${SystemColourRole}`, string>;

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

export type AccentTokens = Record<
  | '--twes-accent'
  | '--twes-on-accent'
  | '--twes-accent-state'
  | '--twes-accent-soft'
  | '--twes-accent-text'
  | '--twes-accent-strip'
  | '--twes-accent-hover'
  | '--twes-accent-hover-raised'
  | '--twes-accent-hover-container'
  | '--twes-accent-soft-raised'
  | '--twes-accent-soft-container'
  | '--twes-accent-indicator'
  | '--twes-on-indicator'
  | '--twes-accent-text-inverse'
  | '--twes-focus-inverse',
  string
>;

/**
 * The accent as the company picked it, and the roles that keep it readable whatever it is, over the neutral ramp's
 * grounds tinted at the preset's shares (`themeRoles`). `--twes-accent` fills primary buttons; `--twes-on-accent`
 * writes on it, in white when white reads, else in ink, else in black; `--twes-accent-state` is the layer a filled
 * control shows when hovered, focused or pressed, the opposite of its ink, so a state never lowers its contrast (CI run
 * 36204583076: a hovered « Enregistrer » in #1f6feb under Material's white layer read 4.07:1).
 * Hover and soft (selected) grounds come in three, over the page, over a raised card and over a bar or menu, each
 * perceptible over its ground, a selection never mistaken for a hover; `--twes-accent-strip` is the header band.
 * `--twes-accent-text` reads at 4.6:1 on every ground it is written on and is the focus ring; `--twes-accent-indicator`
 * is the accent where it alone marks a state, at 3:1 on every ground it is drawn on, `--twes-on-indicator` written on
 * it when filled; `--twes-accent-text-inverse` and `--twes-focus-inverse` are the word and the ring on a toast's inverse
 * surface, where the accent text would turn the wrong way round.
 */
export function accentTokens(
  accent: string,
  scheme: ColourScheme,
  preset: ThemePreset = DEFAULT_THEME_PRESET,
): AccentTokens {
  const t = themeRoles(accent, scheme, preset);
  return {
    '--twes-accent': t.accent,
    '--twes-on-accent': t.onAccent,
    '--twes-accent-state': t.accentState,
    '--twes-accent-soft': t.accentSoft,
    '--twes-accent-text': t.accentText,
    '--twes-accent-strip': t.accentStrip,
    '--twes-accent-hover': t.accentHover,
    '--twes-accent-hover-raised': t.accentHoverRaised,
    '--twes-accent-hover-container': t.accentHoverContainer,
    '--twes-accent-soft-raised': t.accentSoftRaised,
    '--twes-accent-soft-container': t.accentSoftContainer,
    '--twes-accent-indicator': t.accentIndicator,
    '--twes-on-indicator': t.onIndicator,
    '--twes-accent-text-inverse': t.accentTextInverse,
    '--twes-focus-inverse': t.focusInverse,
  };
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
