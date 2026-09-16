// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  argbFromHex,
  DynamicScheme,
  Hct,
  hexFromArgb,
  SchemeFidelity,
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

/**
 * The tones a status badge takes. Each names a meaning, so a module maps its statuses onto them and never picks a
 * colour; none follows the company's accent, which marks actions, selection and focus (docs/SPEC.md § 7, 2026-09-16,
 * the approved design).
 */
export const STATUS_TONES = ['neutral', 'info', 'warning', 'success', 'danger', 'purple'] as const;
export type StatusTone = (typeof STATUS_TONES)[number];
export type StatusTokens = Record<`--twes-status-${StatusTone}-${'bg' | 'fg' | 'dot'}`, string>;

/** OKLCH hue and chroma of each tone, as the approved design canvas draws them. */
const TONE_HUE_CHROMA: Record<StatusTone, readonly [number, number]> = {
  neutral: [262, 0.02],
  info: [252, 0.13],
  warning: [68, 0.12],
  success: [152, 0.12],
  danger: [25, 0.15],
  purple: [300, 0.13],
};

/**
 * Each status tone's badge colours in one scheme: a soft background, a strong label and a solid dot at fixed OKLCH
 * lightness, so every tone reads alike whatever its hue and the label stays readable on its badge.
 */
export function statusTokens(scheme: ColourScheme): StatusTokens {
  const dark = scheme === 'dark';
  const tokens: Record<string, string> = {};
  for (const tone of STATUS_TONES) {
    const [hue, chroma] = TONE_HUE_CHROMA[tone];
    tokens[`--twes-status-${tone}-bg`] = dark
      ? oklchToHex(0.31, chroma * 0.4, hue)
      : oklchToHex(0.95, chroma * 0.28, hue);
    tokens[`--twes-status-${tone}-fg`] = dark
      ? oklchToHex(0.86, chroma * 0.75, hue)
      : oklchToHex(0.45, chroma, hue);
    tokens[`--twes-status-${tone}-dot`] = dark
      ? oklchToHex(0.74, chroma, hue)
      : oklchToHex(0.62, chroma, hue);
  }
  return tokens as StatusTokens;
}

/** An OKLCH colour as #rrggbb (CSS Color 4 matrices), each channel clipped into sRGB. */
export function oklchToHex(lightness: number, chroma: number, hue: number): string {
  const radians = (hue * Math.PI) / 180;
  const a = chroma * Math.cos(radians);
  const b = chroma * Math.sin(radians);
  const l = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const m = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const s = (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3;
  const linear = [
    4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
  ];
  return (
    '#' +
    linear
      .map((channel) => {
        const clipped = Math.min(1, Math.max(0, channel));
        const encoded =
          clipped <= 0.0031308 ? 12.92 * clipped : 1.055 * clipped ** (1 / 2.4) - 0.055;
        return Math.round(encoded * 255)
          .toString(16)
          .padStart(2, '0');
      })
      .join('')
  );
}
