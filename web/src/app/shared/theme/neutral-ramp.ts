// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  assertAccentColour,
  type ColourScheme,
  contrastRatio,
  mixHex,
  oklchToHex,
  relativeLuminance,
  statusTokens,
} from './colour-math';

/**
 * The greys every ground, ink and outline is drawn from, fixed whatever the accent, so a red accent never turns the
 * whole backdrop pink. Hue 262 is the neutral status tone's own, so greys, the neutral badge and text belong together;
 * the chroma stays low and tapers at both ends.
 */
export const NEUTRAL_HUE = 262;
export const NEUTRAL_RAMP = {
  '0': { lightness: 1, chroma: 0 },
  '25': { lightness: 0.985, chroma: 0.003 },
  '50': { lightness: 0.97, chroma: 0.005 },
  '100': { lightness: 0.945, chroma: 0.007 },
  '150': { lightness: 0.92, chroma: 0.009 },
  '200': { lightness: 0.88, chroma: 0.011 },
  '300': { lightness: 0.8, chroma: 0.013 },
  '400': { lightness: 0.69, chroma: 0.015 },
  '500': { lightness: 0.58, chroma: 0.017 },
  '600': { lightness: 0.49, chroma: 0.018 },
  '700': { lightness: 0.4, chroma: 0.018 },
  '800': { lightness: 0.32, chroma: 0.016 },
  '850': { lightness: 0.275, chroma: 0.014 },
  '900': { lightness: 0.235, chroma: 0.012 },
  '925': { lightness: 0.21, chroma: 0.011 },
  '950': { lightness: 0.185, chroma: 0.01 },
  '975': { lightness: 0.16, chroma: 0.009 },
} as const;
export type NeutralStep = keyof typeof NEUTRAL_RAMP;

export function neutralHex(step: NeutralStep): string {
  const { lightness, chroma } = NEUTRAL_RAMP[step];
  return oklchToHex(lightness, chroma, NEUTRAL_HUE);
}

/** How much of the accent the grounds carry: `calme` keeps them nearly grey, `vif` lets the accent show. */
export const THEME_PRESETS = ['calme', 'vif'] as const;
export type ThemePreset = (typeof THEME_PRESETS)[number];
/** New accounts start on « vif » (docs/SPEC.md § 7, the design decisions read from the mockup page). */
export const DEFAULT_THEME_PRESET: ThemePreset = 'vif';

interface Shares {
  backdrop: number;
  surface: number;
  raised: number;
  sunken: number;
  soft: number;
  strip: number;
  hover: number;
}

/**
 * The share of the accent each ground starts from. Hover and soft are only starting points: each is raised until it
 * clears its floor over the ground it is drawn on.
 */
const PRESET_SHARES: Record<ThemePreset, Record<ColourScheme, Shares>> = {
  calme: {
    light: {
      backdrop: 0.03,
      surface: 0,
      raised: 0,
      sunken: 0.012,
      soft: 0.1,
      strip: 0.1,
      hover: 0.05,
    },
    dark: {
      backdrop: 0.03,
      surface: 0.015,
      raised: 0.015,
      sunken: 0.02,
      soft: 0.14,
      strip: 0.2,
      hover: 0.07,
    },
  },
  vif: {
    light: {
      backdrop: 0.08,
      surface: 0.006,
      raised: 0,
      sunken: 0.03,
      soft: 0.13,
      strip: 0.2,
      hover: 0.05,
    },
    dark: {
      backdrop: 0.08,
      surface: 0.035,
      raised: 0.035,
      sunken: 0.045,
      soft: 0.24,
      strip: 0.34,
      hover: 0.1,
    },
  },
};

/** A hover against the ground under it: perceptible, though no WCAG minimum applies to a hover. */
const HOVER_FLOOR = 1.1;
/** A selection fill against the ground under it. */
const SOFT_FLOOR = 1.2;
/** A selection against the hover of the same ground, so a hovered row never reads as selected. */
const SOFT_VS_HOVER = 1.15;
/** The accent as text: WCAG's 4.5, plus room for rounding differences between this and the reference computation. */
const TEXT_TARGET = 4.6;
/** A mark that alone carries a state (WCAG 1.4.11). */
const INDICATOR_TARGET = 3;
const WHITE = '#ffffff';
/** The ink written on a light accent (design direction § 1.1, the approved board's `ink`). */
export const INK = '#0d0f14';
const BLACK = '#000000';
const READABLE = 4.5;

/** The fill of a button whose action cannot be taken back, white text on it in both schemes. */
const DANGER_FILL: Record<ColourScheme, string> = {
  light: oklchToHex(0.5, 0.19, 25),
  dark: oklchToHex(0.56, 0.19, 25),
};

/** Every colour of one theme, named as the reference computation names them. */
export interface ThemeRoles {
  backdrop: string;
  surface: string;
  raised: string;
  sunken: string;
  container: string;
  high: string;
  highest: string;
  onSurface: string;
  onVariant: string;
  outline: string;
  outlineVariant: string;
  inverse: string;
  onInverse: string;
  accent: string;
  onAccent: string;
  accentState: string;
  accentHover: string;
  accentHoverRaised: string;
  accentHoverContainer: string;
  accentSoft: string;
  accentSoftRaised: string;
  accentSoftContainer: string;
  accentStrip: string;
  accentText: string;
  accentIndicator: string;
  onIndicator: string;
  accentTextInverse: string;
  focusInverse: string;
  dangerFill: string;
  onDanger: string;
}

/**
 * Mixes `base` into `ground` from `share`, one per cent at a time, until it clears `floor` against the ground (and
 * `otherFloor` against `other`) on the right side of it: darker in light, lighter in dark. Past 60 % it stops and
 * returns the last mix, as the reference computation does.
 */
function stateOver(
  base: string,
  ground: string,
  start: number,
  floor: number,
  dark: boolean,
  other?: { colour: string; floor: number },
): string {
  let share = start;
  for (;;) {
    const colour = mixHex(base, ground, share);
    const rightSide = dark
      ? relativeLuminance(colour) > relativeLuminance(ground)
      : relativeLuminance(colour) < relativeLuminance(ground);
    const clears =
      rightSide &&
      contrastRatio(colour, ground) >= floor &&
      (other === undefined || contrastRatio(colour, other.colour) >= other.floor);
    if (clears || share >= 0.6) {
      return colour;
    }
    share += 0.01;
  }
}

function lowest(colour: string, grounds: readonly string[]): number {
  return Math.min(...grounds.map((ground) => contrastRatio(colour, ground)));
}

/**
 * The whole theme for one accent, scheme and preset: the ramp's grounds tinted at the preset's shares, the state
 * grounds raised over each ground they are drawn on, and the accent's text, indicator and inverse roles stepped until
 * they read on every ground they are drawn on. `surfaceTokens` and `accentTokens` are two views of it.
 */
export function themeRoles(accent: string, scheme: ColourScheme, preset: ThemePreset): ThemeRoles {
  assertAccentColour(accent);
  const hex = accent.toLowerCase();
  const dark = scheme === 'dark';
  const shares = PRESET_SHARES[preset][scheme];
  const n = neutralHex;
  // Dark depth: the page on 950, cards raised onto 850 (lighter is nearer), bars and menus 800, highest 700.
  const base = dark
    ? { backdrop: n('975'), surface: n('950'), raised: n('850'), sunken: n('925') }
    : { backdrop: n('100'), surface: n('25'), raised: n('0'), sunken: n('50') };
  const backdrop = mixHex(hex, base.backdrop, shares.backdrop);
  const surface = mixHex(hex, base.surface, shares.surface);
  const raised = mixHex(hex, base.raised, shares.raised);
  const sunken = mixHex(hex, base.sunken, shares.sunken);
  const container = dark ? n('800') : n('50');

  const onAccent = [WHITE, INK].find((ink) => contrastRatio(ink, hex) >= READABLE) ?? BLACK;
  // In dark the states mix a lightened accent, so a state is always lighter than the ground under it.
  const stateBase = dark ? mixHex(hex, WHITE, 0.7) : hex;
  const states = (ground: string) => {
    const hover = stateOver(stateBase, ground, shares.hover, HOVER_FLOOR, dark);
    const soft = stateOver(stateBase, ground, shares.soft, SOFT_FLOOR, dark, {
      colour: hover,
      floor: SOFT_VS_HOVER,
    });
    return { hover, soft };
  };
  const onSurface = states(surface);
  const onRaised = states(raised);
  const onContainer = states(container);
  const accentStrip = mixHex(hex, raised, shares.strip);
  const softGrounds = [onSurface.soft, onRaised.soft, onContainer.soft];
  const hoverGrounds = [onSurface.hover, onRaised.hover, onContainer.hover];

  // The accent as text, stepped by 4 % until it reads on every ground it is written on.
  const toward = dark ? WHITE : BLACK;
  const textGrounds = [
    surface,
    raised,
    backdrop,
    sunken,
    container,
    accentStrip,
    ...softGrounds,
    ...hoverGrounds,
  ];
  let share = dark ? 0.62 : 0.82;
  let accentText = mixHex(hex, toward, share);
  while (lowest(accentText, textGrounds) < TEXT_TARGET && share > 0) {
    share = Math.max(0, share - 0.04);
    accentText = mixHex(hex, toward, share);
  }

  // The accent where it alone marks a state (rail bar, active-row bar, selected-card border, switch, progress): as
  // picked unless it falls under 3:1 on a ground it is drawn on, then stepped away from that ground.
  const indicatorGrounds = [surface, raised, backdrop, container, ...softGrounds, ...hoverGrounds];
  let accentIndicator = hex;
  let step = 0;
  while (lowest(accentIndicator, indicatorGrounds) < INDICATOR_TARGET && step < 1) {
    step += 0.04;
    accentIndicator = mixHex(toward, hex, step);
  }
  const onIndicator =
    contrastRatio(INK, accentIndicator) > contrastRatio(WHITE, accentIndicator) ? INK : WHITE;

  // The accent as a word on the inverse surface (a toast's action) and the focus ring there: the ring elsewhere is
  // the accent text, which the inverse surface turns the wrong way round.
  const inverse = dark ? n('100') : n('850');
  const inverseToward = dark ? BLACK : WHITE;
  let inverseShare = dark ? 0.82 : 0.62;
  let accentTextInverse = mixHex(hex, inverseToward, inverseShare);
  while (contrastRatio(accentTextInverse, inverse) < TEXT_TARGET && inverseShare > 0) {
    inverseShare = Math.max(0, inverseShare - 0.04);
    accentTextInverse = mixHex(hex, inverseToward, inverseShare);
  }

  return {
    backdrop,
    surface,
    raised,
    sunken,
    container,
    high: dark ? n('800') : n('100'),
    highest: dark ? n('700') : n('150'),
    onSurface: dark ? n('50') : n('900'),
    onVariant: dark ? n('300') : n('600'),
    outline: dark ? n('400') : n('500'),
    outlineVariant: dark ? n('700') : n('200'),
    inverse,
    onInverse: dark ? n('900') : n('50'),
    accent: hex,
    onAccent,
    accentState: onAccent === WHITE ? BLACK : WHITE,
    accentHover: onSurface.hover,
    accentHoverRaised: onRaised.hover,
    accentHoverContainer: onContainer.hover,
    accentSoft: onSurface.soft,
    accentSoftRaised: onRaised.soft,
    accentSoftContainer: onContainer.soft,
    accentStrip,
    accentText,
    accentIndicator,
    onIndicator,
    accentTextInverse,
    focusInverse: accentTextInverse,
    dangerFill: DANGER_FILL[scheme],
    onDanger: WHITE,
  };
}

/** The Material system roles the ramp replaces after the accent scheme is written; the rest stay the accent's. */
export const SURFACE_ROLES = [
  'background',
  'surface',
  'surface-dim',
  'surface-bright',
  'surface-container-lowest',
  'surface-container-low',
  'surface-container',
  'surface-container-high',
  'surface-container-highest',
  'surface-variant',
  'on-surface',
  'on-background',
  'on-surface-variant',
  'outline',
  'outline-variant',
  'inverse-surface',
  'inverse-on-surface',
  'surface-tint',
  'shadow',
  'scrim',
  'error',
  'on-error',
  'error-container',
  'on-error-container',
] as const;
export type SurfaceRole = (typeof SURFACE_ROLES)[number];
export type SurfaceTokens = Record<
  | `--mat-sys-${SurfaceRole}`
  | '--twes-surface-backdrop'
  | '--twes-surface-raised'
  | '--twes-surface-sunken'
  | '--twes-danger-fill'
  | '--twes-on-danger',
  string
>;

/**
 * The grounds, inks and outlines of one theme as Material's system roles, written over the accent scheme's: the page,
 * cards, bars and menus come from the neutral ramp tinted at the preset's shares, and Material's error roles take the
 * danger status tone, so one red means danger everywhere. Its own grounds (backdrop, raised card, sunken well) and the
 * danger fill are named beside them.
 */
export function surfaceTokens(
  accent: string,
  scheme: ColourScheme,
  preset: ThemePreset = DEFAULT_THEME_PRESET,
): SurfaceTokens {
  const t = themeRoles(accent, scheme, preset);
  const dark = scheme === 'dark';
  const danger = statusTokens(scheme);
  const errorFill = danger['--twes-status-danger-fg'];
  return {
    '--mat-sys-background': t.surface,
    '--mat-sys-surface': t.surface,
    // Material's dim is the darkest ground and its bright the nearest: the backdrop, and the card in light or the
    // high container in dark.
    '--mat-sys-surface-dim': t.backdrop,
    '--mat-sys-surface-bright': dark ? t.high : t.raised,
    '--mat-sys-surface-container-lowest': dark ? t.backdrop : t.raised,
    '--mat-sys-surface-container-low': t.raised,
    '--mat-sys-surface-container': t.container,
    '--mat-sys-surface-container-high': t.high,
    '--mat-sys-surface-container-highest': t.highest,
    '--mat-sys-surface-variant': t.highest,
    '--mat-sys-on-surface': t.onSurface,
    '--mat-sys-on-background': t.onSurface,
    '--mat-sys-on-surface-variant': t.onVariant,
    '--mat-sys-outline': t.outline,
    '--mat-sys-outline-variant': t.outlineVariant,
    '--mat-sys-inverse-surface': t.inverse,
    '--mat-sys-inverse-on-surface': t.onInverse,
    '--mat-sys-surface-tint': t.accent,
    '--mat-sys-shadow': BLACK,
    '--mat-sys-scrim': BLACK,
    '--mat-sys-error': errorFill,
    // The danger label is dark in light and light in dark: whichever ink reads on it.
    '--mat-sys-on-error': contrastRatio(WHITE, errorFill) >= READABLE ? WHITE : INK,
    '--mat-sys-error-container': danger['--twes-status-danger-bg'],
    '--mat-sys-on-error-container': errorFill,
    '--twes-surface-backdrop': t.backdrop,
    '--twes-surface-raised': t.raised,
    '--twes-surface-sunken': t.sunken,
    '--twes-danger-fill': t.dangerFill,
    '--twes-on-danger': t.onDanger,
  };
}
