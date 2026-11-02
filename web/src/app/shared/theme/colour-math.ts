// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The colour arithmetic every theme function shares, kept apart from them so the neutral ramp and the accent roles
 * can both read it without importing each other.
 */

export type ColourScheme = 'light' | 'dark';

export class InvalidAccentColour extends Error {
  constructor(readonly value: string) {
    super(`The accent colour "${value}" is not a #rrggbb colour.`);
    this.name = 'InvalidAccentColour';
  }
}

const HEX_COLOUR = /^#[0-9a-f]{6}$/i;

/** Throws InvalidAccentColour unless the value is #rrggbb; the one place that rule lives. */
export function assertAccentColour(value: string): void {
  if (!HEX_COLOUR.test(value)) {
    throw new InvalidAccentColour(value);
  }
}

/** `share` of `a` and the rest of `b`, channel by channel in sRGB, as CSS `color-mix(in srgb, a share, b)`. */
export function mixHex(a: string, b: string, share: number): string {
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
export function contrastRatio(a: string, b: string): number {
  const [light, dark] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x);
  return (light + 0.05) / (dark + 0.05);
}

/** WCAG 2 relative luminance of a #rrggbb colour. */
export function relativeLuminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
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

/**
 * The tones a status badge takes. Each names a meaning, so a module maps its statuses onto them and never picks a
 * colour; none follows the company's accent, which marks actions, selection and focus.
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
