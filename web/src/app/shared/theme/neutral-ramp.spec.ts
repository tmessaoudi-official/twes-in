// SPDX-License-Identifier: AGPL-3.0-or-later

import { contrastRatio, InvalidAccentColour, statusTokens } from './colour-math';
import {
  DEFAULT_THEME_PRESET,
  NEUTRAL_RAMP,
  neutralHex,
  SURFACE_ROLES,
  surfaceTokens,
  THEME_PRESETS,
} from './neutral-ramp';
import expected from './proposal-tokens.fixture.json';

describe('the neutral ramp', () => {
  it('draws every step at hue 262 with the hex the design proposal computed', () => {
    expect(Object.keys(NEUTRAL_RAMP).sort()).toEqual(Object.keys(expected.ramp).sort());
    for (const [step, hex] of Object.entries(expected.ramp)) {
      expect(neutralHex(step as keyof typeof NEUTRAL_RAMP), `n${step}`).toBe(hex);
    }
  });
});

describe('surfaceTokens', () => {
  it('replaces exactly the grounds, inks, outlines, inverse, tint, shadow, scrim and error roles, and names its own grounds', () => {
    const tokens = surfaceTokens('#1f6feb', 'light', 'vif');
    expect(Object.keys(tokens).sort()).toEqual(
      [
        ...SURFACE_ROLES.map((role) => `--mat-sys-${role}`),
        '--twes-surface-backdrop',
        '--twes-surface-raised',
        '--twes-surface-sunken',
        '--twes-danger-fill',
        '--twes-on-danger',
      ].sort(),
    );
    for (const value of Object.values(tokens)) {
      expect(value).toMatch(/^#[0-9a-f]{6}$/);
    }
  });

  it('leaves the accent scheme its primary, secondary, tertiary and fixed roles', () => {
    const roles = SURFACE_ROLES as readonly string[];
    for (const kept of [
      'primary',
      'on-primary',
      'secondary-container',
      'tertiary',
      'inverse-primary',
      'primary-fixed',
    ]) {
      expect(roles, kept).not.toContain(kept);
    }
  });

  it('tints the page with the accent at the preset share, and keeps the grey neutral elsewhere', () => {
    expect(surfaceTokens('#1f6feb', 'light', 'calme')['--mat-sys-surface']).toBe(neutralHex('25'));
    expect(surfaceTokens('#1f6feb', 'light', 'vif')['--mat-sys-surface']).not.toBe(
      neutralHex('25'),
    );
    for (const preset of THEME_PRESETS) {
      expect(surfaceTokens('#b3261e', 'light', preset)['--mat-sys-on-surface']).toBe(
        neutralHex('900'),
      );
      expect(surfaceTokens('#b3261e', 'dark', preset)['--mat-sys-surface-container']).toBe(
        neutralHex('800'),
      );
    }
  });

  it('sets the tint to the accent as picked', () => {
    expect(surfaceTokens('#1F6FEB', 'dark', 'calme')['--mat-sys-surface-tint']).toBe('#1f6feb');
  });

  // One red means danger everywhere: Material's error roles are the danger status tone.
  it('takes the error roles from the danger status tone, with an ink that reads on the error fill', () => {
    for (const scheme of ['light', 'dark'] as const) {
      const tokens = surfaceTokens('#1f6feb', scheme, 'vif');
      const tone = statusTokens(scheme);
      expect(tokens['--mat-sys-error']).toBe(tone['--twes-status-danger-fg']);
      expect(tokens['--mat-sys-on-error-container']).toBe(tone['--twes-status-danger-fg']);
      expect(tokens['--mat-sys-error-container']).toBe(tone['--twes-status-danger-bg']);
      expect(
        contrastRatio(tokens['--mat-sys-on-error'], tokens['--mat-sys-error']),
        `${scheme}: on-error on error`,
      ).toBeGreaterThanOrEqual(4.5);
      expect(
        contrastRatio(tokens['--twes-on-danger'], tokens['--twes-danger-fill']),
        `${scheme}: on-danger on the danger fill`,
      ).toBeGreaterThanOrEqual(4.5);
    }
  });

  it('draws the danger fill at OKLCH 0.50 in light and 0.56 in dark, hue 25', () => {
    for (const scheme of ['light', 'dark'] as const) {
      expect(surfaceTokens('#00796b', scheme, 'calme')['--twes-danger-fill']).toBe(
        expected.themes[`calme/${scheme}/blue`].danger_fill,
      );
    }
  });

  it('starts new accounts on vif', () => {
    expect(DEFAULT_THEME_PRESET).toBe('vif');
    expect(surfaceTokens('#c25400', 'dark')).toEqual(surfaceTokens('#c25400', 'dark', 'vif'));
  });

  it('refuses what is not a #rrggbb colour', () => {
    expect(() => surfaceTokens('blue', 'light')).toThrow(InvalidAccentColour);
  });
});
