// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The keyboard half of a screen's declaration (docs/SPEC.md § 7, 2026-09-16 and row 45): a screen says once what
 * it offers, and the shortcut, the toolbar, the palette and the "?" sheet all read that one list.
 *
 * Three rules, from the ruling: AZERTY-safe, ignored while typing, and never a key the browser reserves. The first
 * needs no table of layouts — a `keydown` already carries the character the board PRODUCED, so comparing `key`
 * is correct on AZERTY, QWERTY and anything else; what would not be is comparing `code`, which names a position.
 */

/** Keys the browser or the interface already answers; a declaration naming one is refused where it is written. */
export const RESERVED_KEYS: readonly string[] = [
  'Enter',
  'Escape',
  'Tab',
  ' ',
  'ArrowUp',
  'ArrowDown',
  'ArrowLeft',
  'ArrowRight',
  'Backspace',
  // Firefox opens quick-find on both of these with no modifier at all, so a shortcut there never reaches us.
  '/',
  "'",
  // The shell answers these everywhere, before any screen sees them: a screen claiming one would either lose
  // silently or fire alongside the shell, and which of the two happened would depend on the order of two handlers.
  // C opens « Créer » (docs/SPEC.md § 7, 2026-09-24 22:51).
  '?',
  '[',
  'c',
];

/**
 * Whether this keystroke is the declared shortcut: the character the board produced, typed without Ctrl or Meta
 * (see `isBareKeystroke` for why Alt is allowed through and AZERTY requires it).
 */
export function isBareKeystroke(event: KeyboardEvent): boolean {
  if (event.defaultPrevented || event.metaKey) return false;

  // Alt is NOT refused, and on this keyboard that is the whole point: AltGr on a French PC reports Ctrl and Alt
  // together and TYPES a character — "[" is AltGr 5 there — so refusing Alt would put half of an AZERTY board's
  // punctuation out of reach. Ctrl WITHOUT Alt is the browser's, and Meta always is.
  return !(event.ctrlKey && !event.altKey);
}

export function matchesShortcut(event: KeyboardEvent, shortcut: string): boolean {
  return isBareKeystroke(event) && event.key.toLowerCase() === shortcut.toLowerCase();
}

/**
 * Whether the keystroke landed somewhere a person is writing, where a single letter is a letter. An overlay counts
 * whole: while a dialog or a menu is open it owns the keyboard, and a shortcut firing behind it acts on a screen
 * nobody is looking at.
 */
export function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof Element)) return false;

  return (
    target.closest('input, textarea, select, [contenteditable], .cdk-overlay-container') !== null
  );
}

/**
 * Refuses a shortcut a screen may not have. It throws rather than returning a verdict because it is called where
 * the declaration is written: a key that does nothing is found by the person whose key does nothing, months later.
 */
export function refuseReservedShortcut(shortcut: string): void {
  // Reserved BEFORE length, and that order is load-bearing: most reserved keys are NAMED ("Enter", "ArrowUp"), so
  // a length check first would answer "not one character" for every one of them and the list below would never be
  // reached — a rule that cannot fire, wearing a rule's clothes.
  if (RESERVED_KEYS.includes(shortcut)) {
    throw new Error(
      `"${shortcut}" is reserved by the browser or the interface and cannot be a shortcut.`,
    );
  }
  if ([...shortcut].length !== 1) {
    throw new Error(`A shortcut is one character; "${shortcut}" is not.`);
  }
}
