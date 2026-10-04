// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The accents the colour field offers as swatches, each a starting point: the theme derives every role, and the
 * colour that reads on it, from whichever one is chosen (`accentTokens`), so a swatch and a typed colour are equally
 * safe for contrast. The first is the default a company starts with.
 */
export const ACCENT_PRESETS = [
  { id: 'blue', hex: '#1f6feb' },
  { id: 'red', hex: '#b3261e' },
  { id: 'green', hex: '#1b7f3b' },
  { id: 'orange', hex: '#c25400' },
  { id: 'purple', hex: '#7b3fe4' },
  { id: 'teal', hex: '#00796b' },
  { id: 'pink', hex: '#c2185b' },
  { id: 'slate', hex: '#455a64' },
] as const;
