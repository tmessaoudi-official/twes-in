// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  applyColourTokens,
  colourTokens,
  InvalidAccentColour,
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
