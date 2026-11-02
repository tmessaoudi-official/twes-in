// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { ACCENT_PRESETS } from './accent-presets';
import { STATUS_TONES, statusTokens } from './accent-theme';
import { hexToOklab, oklabDistance, oklchToHex, type ColourScheme } from './colour-math';
import { surfaceTokens } from './neutral-ramp';

describe('hexToOklab', () => {
  // Björn Ottosson's OKLab, as CSS Color 4 gives the sRGB primaries: red is oklch(62.8% 0.2577 29.23).
  it.each([
    ['#ffffff', [1, 0, 0]],
    ['#000000', [0, 0, 0]],
    ['#ff0000', [0.62796, 0.22486, 0.12585]],
    ['#00ff00', [0.86644, -0.23389, 0.1795]],
    ['#0000ff', [0.45201, -0.03246, -0.31153]],
  ] as const)('reads %s as the published OKLab coordinates', (hex, [l, a, b]) => {
    const [L, A, B] = hexToOklab(hex);
    expect(L).toBeCloseTo(l, 4);
    expect(A).toBeCloseTo(a, 4);
    expect(B).toBeCloseTo(b, 4);
  });

  // Points inside sRGB, so oklchToHex clips nothing and only the channel rounding stands between the two.
  it('goes back to the colour oklchToHex drew, within a channel step', () => {
    for (const [l, c, h] of [
      [0.5, 0.19, 25],
      [0.62, 0.12, 152],
      [0.74, 0.13, 252],
    ] as const) {
      const [L, A, B] = hexToOklab(oklchToHex(l, c, h));
      expect(L).toBeCloseTo(l, 2);
      expect(Math.hypot(A, B)).toBeCloseTo(c, 2);
      expect(((Math.atan2(B, A) * 180) / Math.PI + 360) % 360).toBeCloseTo(h, 0);
    }
  });
});

describe('oklabDistance', () => {
  it('is the OKLab distance times a hundred, nothing between a colour and itself', () => {
    expect(oklabDistance('#1f6feb', '#1f6feb')).toBe(0);
    expect(oklabDistance('#ffffff', '#000000')).toBeCloseTo(100, 3);
    expect(oklabDistance('#ff0000', '#0000ff')).toBe(oklabDistance('#0000ff', '#ff0000'));
  });
});

/**
 * A swatch is a company's accent: it fills the primary button and marks what is chosen, so it must never be taken for
 * a status, least of all for the danger of an action that cannot be taken back. Each swatch is held at least this far,
 * in OKLab distance times a hundred (about 2 is just noticeable), from every coloured status tone's label and dot and
 * from the danger fill, in each scheme: the floor the area colours already keep from the status tones.
 */
const STATUS_DISTANCE_FLOOR = 8;

/** Every colour that says a status in one scheme, named. */
function statusColours(scheme: ColourScheme): Record<string, string> {
  const tones = statusTokens(scheme);
  const colours: Record<string, string> = {
    'danger fill': surfaceTokens('#1f6feb', scheme)['--twes-danger-fill'],
  };
  for (const tone of STATUS_TONES.filter((t) => t !== 'neutral')) {
    colours[`${tone} label`] = tones[`--twes-status-${tone}-fg`];
    colours[`${tone} dot`] = tones[`--twes-status-${tone}-dot`];
  }
  return colours;
}

describe('the accent swatches', () => {
  it.each(
    ACCENT_PRESETS.flatMap((preset) =>
      (['light', 'dark'] as const).map((scheme) => ({ ...preset, scheme })),
    ),
  )('$id $scheme: no status colour is near it', ({ id, hex, scheme }) => {
    for (const [status, colour] of Object.entries(statusColours(scheme))) {
      expect(
        oklabDistance(hex, colour),
        `${id} ${hex} against the ${scheme} ${status} ${colour}`,
      ).toBeGreaterThanOrEqual(STATUS_DISTANCE_FLOOR);
    }
  });

  it('keeps every swatch a lower-case #rrggbb, each named once', () => {
    expect(new Set(ACCENT_PRESETS.map((p) => p.id)).size).toBe(ACCENT_PRESETS.length);
    for (const preset of ACCENT_PRESETS) {
      expect(preset.hex).toMatch(/^#[0-9a-f]{6}$/);
    }
  });
});
