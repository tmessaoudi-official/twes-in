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

export type AccentTokens = Record<
  | '--twes-accent'
  | '--twes-on-accent'
  | '--twes-accent-state'
  | '--twes-accent-soft'
  | '--twes-accent-text',
  string
>;

const WHITE = '#ffffff';
/** The ink written on a light accent (design direction § 1.1, the approved board's `ink`). */
const INK = '#0d0f14';
/** WCAG AA for body text, which every accent role that carries words meets. */
const READABLE = 4.5;

/**
 * The accent as the company picked it, and the roles that keep it readable whatever it is (design direction § 1.1,
 * docs/SPEC.md § 7, 2026-09-25): `--twes-accent` fills primary buttons and marks selection; `--twes-on-accent` writes on
 * it, in white when white reads, else in ink, else in black, which always reads where white does not;
 * `--twes-accent-state` is the layer a filled control shows when hovered, focused or pressed, the opposite of its ink,
 * so a state darkens a fill written in white and lightens one written in ink and never lowers its contrast (CI run
 * 36204583076: a hovered « Enregistrer » in #1f6feb under Material's white layer read 4.07:1);
 * `--twes-accent-soft` is a selected row's fill; `--twes-accent-text` is the accent as a link on the scheme's surface,
 * darkened (light) or lightened (dark) from the direction's starting mix until it reaches 4.5:1.
 */
export function accentTokens(accent: string, scheme: ColourScheme): AccentTokens {
  assertAccentColour(accent);
  const hex = accent.toLowerCase();
  const surface = colourTokens(hex, scheme)['--mat-sys-surface'];
  const dark = scheme === 'dark';
  const onAccent = [WHITE, INK].find((ink) => contrastRatio(ink, hex) >= READABLE) ?? '#000000';
  const toward = dark ? WHITE : '#000000';
  let share = dark ? 0.62 : 0.82;
  let text = mixHex(hex, toward, share);
  while (contrastRatio(text, surface) < READABLE && share > 0) {
    share = Math.max(0, share - 0.04);
    text = mixHex(hex, toward, share);
  }
  return {
    '--twes-accent': hex,
    '--twes-on-accent': onAccent,
    '--twes-accent-state': onAccent === WHITE ? '#000000' : WHITE,
    '--twes-accent-soft': dark ? mixHex(hex, surface, 0.22) : mixHex(hex, WHITE, 0.11),
    '--twes-accent-text': text,
  };
}

/** `share` of `a` and the rest of `b`, channel by channel in sRGB, as CSS `color-mix(in srgb, a share, b)`. */
function mixHex(a: string, b: string, share: number): string {
  const channel = (hex: string, i: number) => parseInt(hex.slice(1 + 2 * i, 3 + 2 * i), 16);
  return (
    '#' +
    [0, 1, 2]
      .map((i) =>
        Math.round(channel(a, i) * share + channel(b, i) * (1 - share))
          .toString(16)
          .padStart(2, '0'),
      )
      .join('')
  );
}

/** WCAG 2 contrast ratio between two #rrggbb colours. */
function contrastRatio(a: string, b: string): number {
  const [light, dark] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x);
  return (light + 0.05) / (dark + 0.05);
}

function relativeLuminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
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
