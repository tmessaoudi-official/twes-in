// SPDX-License-Identifier: AGPL-3.0-or-later

import { ACCENT_PRESETS } from './accent-presets';
import {
  accentTokens,
  applyColourTokens,
  colourTokens,
  InvalidAccentColour,
  oklchToHex,
  STATUS_TONES,
  statusTokens,
  SYSTEM_COLOUR_ROLES,
} from './accent-theme';
import { surfaceTokens, THEME_PRESETS, type ThemePreset } from './neutral-ramp';
import expected from './proposal-tokens.fixture.json';

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

// docs/SPEC.md § 7, 2026-09-25 (design direction § 1.1): the accent as the company picked it, with the roles that keep
// it readable whatever it is.
describe('accentTokens', () => {
  const accents = [
    '#1f6feb',
    '#ffd400',
    '#00e5ff',
    '#0b1f4a',
    '#e11d48',
    '#16a34a',
    '#7c3aed',
    '#808080',
    '#787878',
    '#ffffff',
    '#000000',
  ];

  it('shows the accent as picked, in both schemes', () => {
    expect(accentTokens('#1F6FEB', 'light')['--twes-accent']).toBe('#1f6feb');
    expect(accentTokens('#1f6feb', 'dark')['--twes-accent']).toBe('#1f6feb');
  });

  it('writes on the accent in white when white reads, and in ink otherwise', () => {
    expect(accentTokens('#1f6feb', 'light')['--twes-on-accent']).toBe('#ffffff');
    expect(accentTokens('#ffd400', 'light')['--twes-on-accent']).toBe('#0d0f14');
    for (const accent of accents) {
      const tokens = accentTokens(accent, 'light');
      expect(contrast(tokens['--twes-on-accent'], accent), accent).toBeGreaterThanOrEqual(4.5);
    }
  });

  // CI run 36204583076: a hovered « Enregistrer » in #1f6feb read 4.07:1 under a white state layer. A state layer moves
  // the fill away from its ink, so hovering, focusing or pressing a filled control never lowers its contrast.
  it('moves a filled control away from its ink when it is hovered, focused or pressed', () => {
    expect(accentTokens('#1f6feb', 'light')['--twes-accent-state']).toBe('#000000');
    expect(accentTokens('#ffd400', 'light')['--twes-accent-state']).toBe('#ffffff');
    const over = (base: string, layer: string, share: number): string =>
      '#' +
      [1, 3, 5]
        .map((at) => {
          const channel = (hex: string) => parseInt(hex.slice(at, at + 2), 16);
          return Math.round(channel(base) * (1 - share) + channel(layer) * share)
            .toString(16)
            .padStart(2, '0');
        })
        .join('');
    for (const scheme of ['light', 'dark'] as const) {
      for (const accent of accents) {
        const tokens = accentTokens(accent, scheme);
        const ink = tokens['--twes-on-accent'];
        // Material's strongest state layer is a pressed or focused one, at 12 %.
        for (const share of [0.08, 0.12, 0.2]) {
          const shown = over(accent, tokens['--twes-accent-state'], share);
          expect(contrast(ink, shown), `${scheme} ${accent} ${share}`).toBeGreaterThanOrEqual(
            contrast(ink, accent) - 0.01,
          );
        }
      }
    }
  });

  it('keeps the accent as text readable on the surface of each scheme, whatever the accent', () => {
    for (const scheme of ['light', 'dark'] as const) {
      for (const accent of accents) {
        const surface = colourTokens(accent, scheme)['--mat-sys-surface'];
        const text = accentTokens(accent, scheme)['--twes-accent-text'];
        expect(contrast(text, surface), `${scheme} ${accent}`).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  it('keeps every swatch the colour field offers, and the extremes a person could type, readable in both schemes', () => {
    const extremes = ['#ffffff', '#000000', '#ffff00', '#00ffff', '#808080'];
    for (const accent of [...ACCENT_PRESETS.map((preset) => preset.hex), ...extremes]) {
      for (const scheme of ['light', 'dark'] as const) {
        const tokens = accentTokens(accent, scheme);
        const surface = colourTokens(accent, scheme)['--mat-sys-surface'];
        expect(
          contrast(tokens['--twes-on-accent'], tokens['--twes-accent']),
          `${accent} ${scheme}: ink on the accent`,
        ).toBeGreaterThanOrEqual(4.5);
        // The accent text also draws the selected-destination bar, a 3:1 non-text mark, so 4.5:1 clears both.
        expect(
          contrast(tokens['--twes-accent-text'], surface),
          `${accent} ${scheme}: accent text and bar on the surface`,
        ).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  // The selected fill is a tint of the page, stronger than it used to be so it reads at 1.20:1 over the page.
  it('softens the accent toward the page for a selected fill', () => {
    const light = accentTokens('#1f6feb', 'light')['--twes-accent-soft'];
    const dark = accentTokens('#1f6feb', 'dark')['--twes-accent-soft'];
    expect(luminance(light)).toBeGreaterThan(0.65);
    expect(luminance(dark)).toBeLessThan(0.08);
    expect(light).not.toBe('#ffffff');
  });

  it('refuses what is not a #rrggbb colour', () => {
    expect(() => accentTokens('blue', 'light')).toThrow(InvalidAccentColour);
  });
});

// The renewed design system's pairs (var/claude/design-system/proposal-tokens.py, ported): every ground, ink, state and
// indicator for every swatch, scheme and preset, each at or above its minimum, each state on the right side of its ground.
type Role = keyof (typeof expected.themes)['calme/light/blue'];

/** Each of the reference computation's names, read from the tokens the theme writes. */
const ROLE_TOKEN: Record<Role, string> = {
  backdrop: '--twes-surface-backdrop',
  surface: '--mat-sys-surface',
  raised: '--twes-surface-raised',
  sunken: '--twes-surface-sunken',
  container: '--mat-sys-surface-container',
  high: '--mat-sys-surface-container-high',
  highest: '--mat-sys-surface-container-highest',
  on_surface: '--mat-sys-on-surface',
  on_variant: '--mat-sys-on-surface-variant',
  outline: '--mat-sys-outline',
  outline_variant: '--mat-sys-outline-variant',
  inverse: '--mat-sys-inverse-surface',
  on_inverse: '--mat-sys-inverse-on-surface',
  accent: '--twes-accent',
  on_accent: '--twes-on-accent',
  accent_state: '--twes-accent-state',
  accent_hover: '--twes-accent-hover',
  accent_soft: '--twes-accent-soft',
  accent_hover_raised: '--twes-accent-hover-raised',
  accent_soft_raised: '--twes-accent-soft-raised',
  accent_hover_container: '--twes-accent-hover-container',
  accent_soft_container: '--twes-accent-soft-container',
  accent_strip: '--twes-accent-strip',
  accent_text: '--twes-accent-text',
  focus: '--twes-accent-text',
  accent_indicator: '--twes-accent-indicator',
  on_indicator: '--twes-on-indicator',
  accent_text_inverse: '--twes-accent-text-inverse',
  focus_inverse: '--twes-focus-inverse',
  danger_fill: '--twes-danger-fill',
  on_danger: '--twes-on-danger',
};

const HOVER_FLOOR = 1.1;
const SOFT_FLOOR = 1.2;
const SOFT_VS_HOVER = 1.15;

/** (label, foreground, background, minimum); a minimum of 0 is listed for information only. */
const PAIRS: readonly (readonly [string, Role, Role, number])[] = [
  ['text on surface (page)', 'on_surface', 'surface', 4.5],
  ['text on raised card', 'on_surface', 'raised', 4.5],
  ['text on backdrop', 'on_surface', 'backdrop', 4.5],
  ['text on sunken (list header)', 'on_surface', 'sunken', 4.5],
  ['text on container (bar, menu)', 'on_surface', 'container', 4.5],
  ['text on highest container', 'on_surface', 'highest', 4.5],
  ['text on hover (page)', 'on_surface', 'accent_hover', 4.5],
  ['text on hover (card)', 'on_surface', 'accent_hover_raised', 4.5],
  ['text on soft (selected row)', 'on_surface', 'accent_soft', 4.5],
  ['text on soft (selected card)', 'on_surface', 'accent_soft_raised', 4.5],
  ['text on accent-strip', 'on_surface', 'accent_strip', 4.5],
  ['muted on surface', 'on_variant', 'surface', 4.5],
  ['muted on raised card', 'on_variant', 'raised', 4.5],
  ['muted on backdrop', 'on_variant', 'backdrop', 4.5],
  ['muted on sunken', 'on_variant', 'sunken', 4.5],
  ['muted on container', 'on_variant', 'container', 4.5],
  ['muted on highest', 'on_variant', 'highest', 4.5],
  ['muted on hover (page)', 'on_variant', 'accent_hover', 4.5],
  ['muted on hover (card)', 'on_variant', 'accent_hover_raised', 4.5],
  ['muted on soft (selected row)', 'on_variant', 'accent_soft', 4.5],
  ['muted on soft (selected card subtitle)', 'on_variant', 'accent_soft_raised', 4.5],
  [
    'text on soft (bottom-bar pill, menu selected item)',
    'on_surface',
    'accent_soft_container',
    4.5,
  ],
  ['muted on accent-strip (FORBIDDEN, info)', 'on_variant', 'accent_strip', 0],
  ['accent-text on surface (link)', 'accent_text', 'surface', 4.5],
  ['accent-text on raised', 'accent_text', 'raised', 4.5],
  ['accent-text on container', 'accent_text', 'container', 4.5],
  ['accent-text on soft (tonal button)', 'accent_text', 'accent_soft', 4.5],
  ['accent-text on soft (card)', 'accent_text', 'accent_soft_raised', 4.5],
  ['accent-text on soft (bottom-bar pill)', 'accent_text', 'accent_soft_container', 4.5],
  ['accent-text on hover (text button)', 'accent_text', 'accent_hover', 4.5],
  ['accent-text on accent-strip (KPI label)', 'accent_text', 'accent_strip', 4.5],
  ['on-accent on accent (filled button)', 'on_accent', 'accent', 4.5],
  ['on-indicator on indicator (pressed chip)', 'on_indicator', 'accent_indicator', 4.5],
  ['on-danger on danger fill', 'on_danger', 'danger_fill', 4.5],
  ['inverse text on inverse (toast)', 'on_inverse', 'inverse', 4.5],
  ['accent-text-inverse on inverse (toast action)', 'accent_text_inverse', 'inverse', 4.5],
  ['field outline on surface', 'outline', 'surface', 3],
  ['field outline on raised', 'outline', 'raised', 3],
  ['field outline on soft (selected card)', 'outline', 'accent_soft_raised', 3],
  ['focus ring on surface', 'focus', 'surface', 3],
  ['focus ring on raised', 'focus', 'raised', 3],
  ['focus ring on backdrop', 'focus', 'backdrop', 3],
  ['focus ring on container (bar, menu)', 'focus', 'container', 3],
  ['focus ring on soft (selected row)', 'focus', 'accent_soft', 3],
  ['focus ring on inverse (toast)', 'focus_inverse', 'inverse', 3],
  ['indicator on surface', 'accent_indicator', 'surface', 3],
  ['indicator on raised', 'accent_indicator', 'raised', 3],
  ['indicator on container (bottom-bar bar)', 'accent_indicator', 'container', 3],
  ['indicator on soft (active-row bar)', 'accent_indicator', 'accent_soft', 3],
  ['indicator on soft (selected-card border)', 'accent_indicator', 'accent_soft_raised', 3],
  ['indicator on soft (bottom-bar pill bar)', 'accent_indicator', 'accent_soft_container', 3],
  ['indicator on hover (new-row fallback)', 'accent_indicator', 'accent_hover', 3],
  ['indicator fill vs surface (pressed chip)', 'accent_indicator', 'surface', 3],
  ['hover vs page', 'accent_hover', 'surface', HOVER_FLOOR],
  ['hover vs card', 'accent_hover_raised', 'raised', HOVER_FLOOR],
  ['hover vs bar/menu', 'accent_hover_container', 'container', HOVER_FLOOR],
  ['soft vs page', 'accent_soft', 'surface', SOFT_FLOOR],
  ['soft vs card', 'accent_soft_raised', 'raised', SOFT_FLOOR],
  ['soft vs bar/menu (bottom-bar pill)', 'accent_soft_container', 'container', SOFT_FLOOR],
  ['soft vs hover (page)', 'accent_soft', 'accent_hover', SOFT_VS_HOVER],
  ['soft vs hover (card)', 'accent_soft_raised', 'accent_hover_raised', SOFT_VS_HOVER],
  ['soft vs hover (bar/menu)', 'accent_soft_container', 'accent_hover_container', SOFT_VS_HOVER],
  ['page vs backdrop (info)', 'surface', 'backdrop', 0],
  ['card vs page (info: depth)', 'raised', 'surface', 0],
  ['sunken vs card (info)', 'sunken', 'raised', 0],
  ['hairline on card (decorative, info)', 'outline_variant', 'raised', 0],
  [
    'indicator on accent-strip (vif header rule, decorative, info)',
    'accent_indicator',
    'accent_strip',
    0,
  ],
  ['accent fill vs surface (info; the button has its label)', 'accent', 'surface', 0],
];

/** A state and the ground it is drawn over: lighter than it in dark, darker in light. */
const DIRECTION: readonly (readonly [Role, Role])[] = [
  ['accent_hover', 'surface'],
  ['accent_hover_raised', 'raised'],
  ['accent_hover_container', 'container'],
  ['accent_soft', 'surface'],
  ['accent_soft_raised', 'raised'],
  ['accent_soft_container', 'container'],
];
const TINTED: readonly Role[] = [
  'accent_hover',
  'accent_hover_raised',
  'accent_soft',
  'accent_soft_raised',
  'accent_strip',
];

const THEMES = THEME_PRESETS.flatMap((preset) =>
  (['light', 'dark'] as const).flatMap((scheme) =>
    ACCENT_PRESETS.map((swatch) => ({
      preset,
      scheme,
      swatch: swatch.id,
      accent: swatch.hex as string,
    })),
  ),
);

function themeOf(
  accent: string,
  scheme: 'light' | 'dark',
  preset: ThemePreset,
): Record<Role, string> {
  const tokens: Record<string, string> = {
    ...colourTokens(accent, scheme),
    ...surfaceTokens(accent, scheme, preset),
    ...accentTokens(accent, scheme, preset),
  };
  return Object.fromEntries(
    (Object.keys(ROLE_TOKEN) as Role[]).map((role) => [role, tokens[ROLE_TOKEN[role]]]),
  ) as Record<Role, string>;
}

describe('the renewed theme, 8 swatches × 2 schemes × 2 presets', () => {
  it('covers the 32 themes the reference computation does', () => {
    expect(THEMES.map((t) => `${t.preset}/${t.scheme}/${t.swatch}`).sort()).toEqual(
      Object.keys(expected.themes).sort(),
    );
  });

  it.each(THEMES)(
    '$preset $scheme $swatch: every pair at or above its minimum',
    ({ preset, scheme, swatch, accent }) => {
      const t = themeOf(accent, scheme, preset);
      for (const [label, fg, bg, minimum] of PAIRS) {
        if (minimum > 0) {
          expect(
            contrast(t[fg], t[bg]),
            `${preset}/${scheme}/${swatch}: ${label}`,
          ).toBeGreaterThanOrEqual(minimum);
        }
      }
    },
  );

  it.each(THEMES)(
    '$preset $scheme $swatch: each state on the right side of its ground',
    ({ preset, scheme, swatch, accent }) => {
      const t = themeOf(accent, scheme, preset);
      for (const [state, ground] of DIRECTION) {
        expect(
          luminance(t[state]) > luminance(t[ground]),
          `${preset}/${scheme}/${swatch}: ${state} over ${ground}`,
        ).toBe(scheme === 'dark');
      }
    },
  );

  // A link moved off primary also sits on a banner or a dialog, the high container, which the accent text's loop does
  // not name. The highest container is not such a ground: in dark the accent text falls under 4.5 there, so no text in
  // the accent is written on it.
  it.each(THEMES)(
    '$preset $scheme $swatch: the accent text reads on the high container too',
    ({ preset, scheme, swatch, accent }) => {
      const t = themeOf(accent, scheme, preset);
      for (const ground of ['high'] as Role[]) {
        expect(
          contrast(t.accent_text, t[ground]),
          `${preset}/${scheme}/${swatch}: accent-text on ${ground}`,
        ).toBeGreaterThanOrEqual(4.5);
      }
    },
  );

  it.each(THEMES)(
    '$preset $scheme $swatch: every status label reads on every tinted ground and card',
    ({ preset, scheme, swatch, accent }) => {
      const t = themeOf(accent, scheme, preset);
      const tones = statusTokens(scheme);
      for (const tone of STATUS_TONES) {
        for (const ground of [...TINTED, 'raised', 'surface'] as Role[]) {
          expect(
            contrast(tones[`--twes-status-${tone}-fg`], t[ground]),
            `${preset}/${scheme}/${swatch}: ${tone} on ${ground}`,
          ).toBeGreaterThanOrEqual(4.5);
        }
      }
    },
  );

  // Python rounds a channel's exact half to even, Math.round rounds it up: these mixes land on a half and come out one
  // higher in a channel here, in two where an even share leaves two channels on a half (the red swatch's inverse text,
  // its mix with white). Every pair above is checked on the values written here, which is why accent-text aims at 4.6
  // rather than 4.5.
  const HALF_ROUNDED_UP: Record<string, Partial<Record<Role, string>>> = {
    'calme/light/red': { accent_text_inverse: '#bb8483', focus_inverse: '#bb8483' },
    'calme/light/slate': { accent_strip: '#eceff0' },
    'calme/dark/orange': { accent_text: '#d6a881', focus: '#d6a881' },
    'calme/dark/purple': { backdrop: '#0e0f17' },
    'vif/light/red': { accent_text_inverse: '#bb8483', focus_inverse: '#bb8483' },
    'vif/light/orange': { accent_hover: '#f4edea', accent_soft: '#ecdcd1', surface: '#f9f9fb' },
    'vif/dark/purple': { accent_strip: '#443071' },
    'vif/dark/teal': { accent_hover: '#152527' },
    'vif/dark/pink': { accent_hover_container: '#3e3845' },
    'vif/dark/slate': { accent_soft_raised: '#3a4249' },
  };

  it('differs from the reference only by a half rounded up, each channel by one at most', () => {
    for (const [at, roles] of Object.entries(HALF_ROUNDED_UP)) {
      const reference = expected.themes[at as keyof typeof expected.themes];
      for (const [role, port] of Object.entries(roles) as [Role, string][]) {
        const steps = [1, 3, 5].map(
          (i) => parseInt(port.slice(i, i + 2), 16) - parseInt(reference[role].slice(i, i + 2), 16),
        );
        expect(
          steps.every((step) => step === 0 || step === 1) && steps.includes(1),
          `${at}: ${role} ${steps.join(',')}`,
        ).toBe(true);
      }
    }
  });

  it.each(THEMES)(
    '$preset $scheme $swatch: the colours the reference computation gives',
    ({ preset, scheme, swatch, accent }) => {
      const at = `${preset}/${scheme}/${swatch}`;
      const t = themeOf(accent, scheme, preset);
      expect(t).toEqual({
        ...expected.themes[at as keyof typeof expected.themes],
        ...HALF_ROUNDED_UP[at],
      });
    },
  );

  // Material's own component chrome stays on primary and secondary-container: its pairs over the new grounds.
  it.each(THEMES)(
    '$preset $scheme $swatch: Material primary and secondary-container pairs on the new grounds',
    ({ preset, scheme, swatch, accent }) => {
      const m = colourTokens(accent, scheme);
      const t = themeOf(accent, scheme, preset);
      const at = `${preset}/${scheme}/${swatch}`;
      const pairs = {
        'primary on surface': contrast(m['--mat-sys-primary'], t.surface),
        'primary on raised': contrast(m['--mat-sys-primary'], t.raised),
        'on-primary on primary (slide toggle handle icon)': contrast(
          m['--mat-sys-on-primary'],
          m['--mat-sys-primary'],
        ),
        'primary vs surface (slide toggle track, UI)': contrast(m['--mat-sys-primary'], t.surface),
        'on-secondary-container on secondary-container': contrast(
          m['--mat-sys-on-secondary-container'],
          m['--mat-sys-secondary-container'],
        ),
      };
      for (const [label, ratio] of Object.entries(pairs)) {
        expect(ratio, `${at}: ${label}`).toBeGreaterThanOrEqual(label.includes('(UI)') ? 3 : 4.5);
      }
      if (swatch === 'blue') {
        const reference =
          expected.materialPrimaryBlue[at as keyof typeof expected.materialPrimaryBlue];
        for (const [label, ratio] of Object.entries(pairs)) {
          expect(ratio, `${at}: ${label}`).toBeCloseTo(
            reference[label as keyof typeof reference],
            2,
          );
        }
      }
    },
  );

  it('keeps the status tones the reference computation validated', () => {
    for (const scheme of ['light', 'dark'] as const) {
      const tones = statusTokens(scheme);
      expect([
        tones['--twes-status-danger-bg'],
        tones['--twes-status-danger-fg'],
        tones['--twes-status-danger-dot'],
      ]).toEqual(expected.statusDanger[scheme]);
    }
  });

  it('defaults the preset to vif for a two-argument call', () => {
    expect(accentTokens('#7b3fe4', 'light')).toEqual(accentTokens('#7b3fe4', 'light', 'vif'));
  });

  // No swatch falls under 3:1 in light, so the light step toward black is exercised by an accent a person may type.
  it('darkens a pale typed accent into an indicator that marks a state on every light ground it is drawn on', () => {
    for (const preset of THEME_PRESETS) {
      for (const accent of ['#ffd400', '#00e5ff', '#ffffff']) {
        const tokens = accentTokens(accent, 'light', preset);
        const surface = surfaceTokens(accent, 'light', preset);
        const indicator = tokens['--twes-accent-indicator'];
        expect(indicator, `${preset} ${accent}`).not.toBe(accent);
        expect(luminance(indicator), `${preset} ${accent}`).toBeLessThan(luminance(accent));
        for (const ground of [
          surface['--mat-sys-surface'],
          surface['--twes-surface-raised'],
          surface['--twes-surface-backdrop'],
          surface['--mat-sys-surface-container'],
          tokens['--twes-accent-soft'],
          tokens['--twes-accent-soft-raised'],
          tokens['--twes-accent-soft-container'],
          tokens['--twes-accent-hover'],
          tokens['--twes-accent-hover-raised'],
          tokens['--twes-accent-hover-container'],
        ]) {
          expect(
            contrast(indicator, ground),
            `${preset} ${accent} on ${ground}`,
          ).toBeGreaterThanOrEqual(3);
        }
      }
    }
  });

  it('adds the state grounds, indicator, inverse and focus roles beside the accent roles it always had', () => {
    expect(Object.keys(accentTokens('#1f6feb', 'dark', 'calme')).sort()).toEqual(
      [
        '--twes-accent',
        '--twes-on-accent',
        '--twes-accent-state',
        '--twes-accent-soft',
        '--twes-accent-text',
        '--twes-accent-strip',
        '--twes-accent-hover',
        '--twes-accent-hover-raised',
        '--twes-accent-hover-container',
        '--twes-accent-soft-raised',
        '--twes-accent-soft-container',
        '--twes-accent-indicator',
        '--twes-on-indicator',
        '--twes-accent-text-inverse',
        '--twes-focus-inverse',
      ].sort(),
    );
  });
});

// A first paint, before the theme facade writes the tokens, reads these from styles.scss: the default accent, light,
// on the preset new accounts start on. Read from the stylesheet itself, so its values never drift from the functions.
declare const process: {
  getBuiltinModule(name: 'fs'): { readFileSync(path: string, encoding: 'utf8'): string };
};

describe('the stylesheet first paint', () => {
  // The shell's ground and every link and ring read these before the facade runs: a role left out paints nothing.
  it('holds every --twes- role the theme computes at its value for #1f6feb, light, vif', () => {
    // Through a variable: the build takes `new URL('…', import.meta.url)` for an asset to bundle and rewrites it.
    const here = import.meta.url;
    const sheet = process
      .getBuiltinModule('fs')
      .readFileSync(new URL('../../../styles.scss', here).pathname, 'utf8');
    const block = /\/\/ First paint[^\n]*\n(?:\/\/[^\n]*\n)*:root \{([^}]*)\}/.exec(sheet);
    expect(block, 'the first-paint :root block').not.toBeNull();
    const declared = Object.fromEntries(
      [...(block?.[1] ?? '').matchAll(/(--twes-[a-z-]+):\s*([^;]+);/g)].map((m) => [
        m[1],
        m[2].trim(),
      ]),
    );
    const computed = Object.fromEntries(
      Object.entries({
        ...surfaceTokens('#1f6feb', 'light', 'vif'),
        ...accentTokens('#1f6feb', 'light', 'vif'),
      }).filter(([name]) => name.startsWith('--twes-')),
    );
    expect(declared).toEqual(computed);
  });

  // A danger button wears a ring an accent button never has, so a red accent never makes the two alike.
  it('rings every enabled danger button with the danger colour, off its edge', () => {
    const here = import.meta.url;
    const sheet = process
      .getBuiltinModule('fs')
      .readFileSync(new URL('../../../styles.scss', here).pathname, 'utf8');
    expect(sheet).toMatch(/--twes-danger-ring:\s*var\(--mat-sys-error\);/);
    expect(sheet).toMatch(/--twes-danger-ring-width:\s*2px;/);
    expect(sheet).toMatch(/--twes-danger-ring-gap:\s*[1-9]\d*px;/);
    const rule = /\.mat-mdc-button-base\.twes-danger \{([\s\S]*?)\n\}/.exec(sheet)?.[1] ?? '';
    const enabled =
      /&:not\(:disabled, \[aria-disabled='true'\]\) \{([^}]*)\}/.exec(rule)?.[1] ?? '';
    expect(enabled).toMatch(
      /outline:\s*var\(--twes-danger-ring-width\) solid var\(--twes-danger-ring\);/,
    );
    expect(enabled).toMatch(/outline-offset:\s*var\(--twes-danger-ring-gap\);/);
  });
});

// The ring is a mark that alone tells the button apart (WCAG 1.4.11): 3:1 on every ground a button is drawn on.
describe('the danger ring', () => {
  it.each(THEMES)(
    '$preset $scheme $swatch: reads at 3:1 on the page, a card, a bar and a dialog',
    ({ preset, scheme, swatch, accent }) => {
      const t = themeOf(accent, scheme, preset);
      const ring = surfaceTokens(accent, scheme, preset)['--mat-sys-error'];
      for (const ground of ['surface', 'raised', 'container', 'high', 'backdrop'] as Role[]) {
        expect(
          contrast(ring, t[ground]),
          `${preset}/${scheme}/${swatch}: ring on ${ground}`,
        ).toBeGreaterThanOrEqual(3);
      }
    },
  );
});
