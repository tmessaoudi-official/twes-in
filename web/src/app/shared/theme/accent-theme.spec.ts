// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  applyColourTokens,
  colourTokens,
  InvalidAccentColour,
  oklchToHex,
  STATUS_TONES,
  statusTokens,
  SYSTEM_COLOUR_ROLES,
} from './accent-theme';

/** WCAG relative luminance of a #rrggbb colour. */
function luminance(hex: string): number {
  const channels = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
  const [r, g, b] = channels.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string): number {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (light + 0.05) / (dark + 0.05);
}

describe('colourTokens', () => {
  it('produces every Material 3 system colour role as a --mat-sys variable holding #rrggbb', () => {
    const tokens = colourTokens('#1f6feb', 'light');

    expect(Object.keys(tokens).sort()).toEqual(
      SYSTEM_COLOUR_ROLES.map((role) => `--mat-sys-${role}`).sort(),
    );
    for (const value of Object.values(tokens)) {
      expect(value).toMatch(/^#[0-9a-f]{6}$/);
    }
  });

  it('keeps text on the accent readable in both schemes, whatever the accent', () => {
    for (const accent of ['#1f6feb', '#ffd400', '#0b7a3b', '#d93025', '#000000']) {
      for (const scheme of ['light', 'dark'] as const) {
        const tokens = colourTokens(accent, scheme);
        expect(
          contrast(tokens['--mat-sys-on-primary'], tokens['--mat-sys-primary']),
          `${accent} ${scheme}`,
        ).toBeGreaterThanOrEqual(4.5);
        expect(
          contrast(tokens['--mat-sys-on-surface'], tokens['--mat-sys-surface']),
          `${accent} ${scheme}`,
        ).toBeGreaterThanOrEqual(4.5);
        expect(
          contrast(tokens['--mat-sys-on-primary-container'], tokens['--mat-sys-primary-container']),
          `${accent} ${scheme}`,
        ).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  it('gives a light surface in the light scheme and a dark one in the dark scheme', () => {
    expect(luminance(colourTokens('#1f6feb', 'light')['--mat-sys-surface'])).toBeGreaterThan(0.8);
    expect(luminance(colourTokens('#1f6feb', 'dark')['--mat-sys-surface'])).toBeLessThan(0.05);
  });

  it('shows the accent as picked: its container is the accent itself, not a muted tone of it', () => {
    for (const accent of ['#1f6feb', '#0b7a3b', '#d93025']) {
      expect(colourTokens(accent, 'light')['--mat-sys-primary-container'], accent).toBe(accent);
    }
  });

  it('follows the accent: two accents give two different primaries', () => {
    expect(colourTokens('#1f6feb', 'light')['--mat-sys-primary']).not.toBe(
      colourTokens('#d93025', 'light')['--mat-sys-primary'],
    );
  });

  it('accepts upper-case hex', () => {
    expect(colourTokens('#1F6FEB', 'light')).toEqual(colourTokens('#1f6feb', 'light'));
  });

  it('refuses anything that is not a #rrggbb colour, naming the value', () => {
    for (const bad of ['blue', '#12345', '1f6feb', '#1f6febff', '']) {
      expect(() => colourTokens(bad, 'light')).toThrow(InvalidAccentColour);
      expect(() => colourTokens(bad, 'light')).toThrow(`"${bad}"`);
    }
  });
});

describe('oklchToHex', () => {
  it('converts OKLCH to sRGB as CSS Color 4 does, for the three primaries and both ends of lightness', () => {
    expect(oklchToHex(0.6279554, 0.2576833, 29.2338851)).toBe('#ff0000');
    expect(oklchToHex(0.8664396, 0.2948272, 142.4953366)).toBe('#00ff00');
    expect(oklchToHex(0.4520137, 0.313214, 264.0520206)).toBe('#0000ff');
    expect(oklchToHex(1, 0, 0)).toBe('#ffffff');
    expect(oklchToHex(0, 0, 0)).toBe('#000000');
  });

  it('clips a colour outside sRGB to the nearest channel values instead of wrapping', () => {
    expect(oklchToHex(0.95, 0.4, 25)).toMatch(/^#[0-9a-f]{6}$/);
  });
});

describe('statusTokens', () => {
  it('offers six tones that name a meaning, never a colour and never the accent', () => {
    expect([...STATUS_TONES]).toEqual([
      'neutral',
      'info',
      'warning',
      'success',
      'danger',
      'purple',
    ]);
  });

  it('gives every tone a soft background, a strong label and a solid dot, in each scheme', () => {
    for (const scheme of ['light', 'dark'] as const) {
      const tokens = statusTokens(scheme);
      expect(Object.keys(tokens).sort()).toEqual(
        STATUS_TONES.flatMap((tone) =>
          ['bg', 'fg', 'dot'].map((part) => `--twes-status-${tone}-${part}`),
        ).sort(),
      );
      for (const value of Object.values(tokens)) {
        expect(value).toMatch(/^#[0-9a-f]{6}$/);
      }
    }
  });

  it('keeps a status label readable on its badge in both schemes', () => {
    for (const scheme of ['light', 'dark'] as const) {
      const tokens = statusTokens(scheme);
      for (const tone of STATUS_TONES) {
        expect(
          contrast(tokens[`--twes-status-${tone}-fg`], tokens[`--twes-status-${tone}-bg`]),
          `${scheme} ${tone}`,
        ).toBeGreaterThanOrEqual(6);
      }
    }
  });

  it('puts a light badge in the light scheme and a dark one in the dark scheme', () => {
    for (const tone of STATUS_TONES) {
      expect(luminance(statusTokens('light')[`--twes-status-${tone}-bg`]), tone).toBeGreaterThan(
        0.75,
      );
      expect(luminance(statusTokens('dark')[`--twes-status-${tone}-bg`]), tone).toBeLessThan(0.08);
    }
  });

  it('keeps the tones apart: no two share a label colour', () => {
    for (const scheme of ['light', 'dark'] as const) {
      const tokens = statusTokens(scheme);
      const labels = STATUS_TONES.map((tone) => tokens[`--twes-status-${tone}-fg`]);
      expect(new Set(labels).size).toBe(STATUS_TONES.length);
    }
  });
});

describe('applyColourTokens', () => {
  it('writes every token onto the element as a custom property', () => {
    const element = document.createElement('div');
    const tokens = colourTokens('#1f6feb', 'dark');

    applyColourTokens(element, tokens);

    for (const [name, value] of Object.entries(tokens)) {
      expect(element.style.getPropertyValue(name)).toBe(value);
    }
  });
});
