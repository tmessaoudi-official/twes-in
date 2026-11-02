// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The accents the colour field offers as swatches, each a starting point: the theme derives every role, and the
 * colour that reads on it, from whichever one is chosen (`accentTokens`), so a swatch and a typed colour are equally
 * safe for contrast. The first is the default a company starts with.
 */
export const ACCENT_PRESETS = [
  { id: 'blue', hex: '#1f6feb' },
  { id: 'red', hex: '#760906' },
  { id: 'green', hex: '#058329' },
  { id: 'orange', hex: '#ac5102' },
  { id: 'purple', hex: '#7b3fe4' },
  { id: 'teal', hex: '#00796b' },
  { id: 'pink', hex: '#bc1d6f' },
  { id: 'slate', hex: '#455a64' },
] as const;
