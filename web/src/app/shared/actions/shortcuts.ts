// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The keyboard half of a screen's declaration (docs/SPEC.md § 7, 2026-09-16 and row 45): a screen says once what
 * it offers, and the shortcut, the toolbar, the palette and the "?" sheet all read that one list.
 *
 * Three rules, from the ruling: AZERTY-safe, ignored while typing, and never a key the browser reserves. The first
 * needs no table of layouts — a `keydown` already carries the character the board PRODUCED, so comparing `key`
 * is correct on AZERTY, QWERTY and anything else; what would not be is comparing `code`, which names a position.
 */

/** What the shell answers with a single key on every screen (docs/SPEC.md § 7, 2026-09-24 22:51, row 125). */
export type ShellShortcut = 'create' | 'new' | 'next' | 'search';

/**
 * The shell's keys as ruled: C opens « Créer », N a new document on its list, E the state's next step (Émettre,
 * Encaisser), / the search that Ctrl K also opens. A person will change them in Mon compte › Préférences; these are
 * what they start from and what « Rétablir » returns to.
 */
export const DEFAULT_SHORTCUTS: ShellKeys = {
  create: 'c',
  new: 'n',
  next: 'e',
  search: '/',
};

/**
 * The only keys a screen may declare. Closed on purpose: a person may give the shell any key that is not the
 * browser's, the interface's or one of these, so a screen reaching for another would collide with somebody's choice.
 */
export const SCREEN_KEYS: readonly string[] = ['s', 'v', 'l', 'p'];

/** The shell's actions in the order Préférences and the « ? » sheet list them. */
export const SHELL_SHORTCUTS: readonly ShellShortcut[] = ['search', 'create', 'new', 'next'];

/** The shell's keys as one person has them: the ruled ones, or their own. */
export type ShellKeys = Readonly<Record<ShellShortcut, string>>;

/** Keys the browser answers itself, before a page is asked. */
const BROWSER_KEYS: readonly string[] = [
  'Enter',
  'Escape',
  'Tab',
  ' ',
  'ArrowUp',
  'ArrowDown',
  'ArrowLeft',
  'ArrowRight',
  'Backspace',
  // Firefox opens quick-find on this one with no modifier at all; the shell takes / before it does (the search).
  "'",
];

/** What the interface answers everywhere and nobody changes: the « ? » sheet and folding the sidebar. */
const INTERFACE_KEYS: readonly string[] = ['?', '['];

/** "5×" typed before a scan makes it count five (docs/SPEC.md § 7, 2026-09-23 09:30): a digit or a times sign. */
const COUNT_KEY = /^[0-9x*×]$/;

/**
 * Keys a screen may not declare: the browser's, the interface's and the shell's ruled ones. A screen may declare only
 * `SCREEN_KEYS` anyway; this list is what a declaration is told it collided with.
 */
export const RESERVED_KEYS: readonly string[] = [
  ...BROWSER_KEYS,
  // The shell answers these everywhere, before any screen sees them: a screen claiming one would either lose
  // silently or fire alongside the shell, and which of the two happened would depend on the order of two handlers.
  ...INTERFACE_KEYS,
  ...Object.values(DEFAULT_SHORTCUTS),
];

/** Why a key cannot be one of the shell's, each said in the person's language in Préférences. */
export type ShellKeyRefusal = 'length' | 'browser' | 'interface' | 'screen' | 'count';

/**
 * Whether a person may give this key to one of the shell's actions (row 125): one character, and not one the
 * browser, the interface, a screen or a till count already answers. The caller has lower-cased a letter.
 */
export function shellKeyRefusal(key: string): ShellKeyRefusal | null {
  // Length first: most of the browser's keys are NAMED ("Enter"), and what a person typed there is not one key.
  if ([...key].length !== 1 || key.trim() === '') return 'length';
  if (BROWSER_KEYS.includes(key)) return 'browser';
  if (INTERFACE_KEYS.includes(key)) return 'interface';
  if (SCREEN_KEYS.includes(key)) return 'screen';
  if (COUNT_KEY.test(key)) return 'count';
  return null;
}

/**
 * The shell's keys from what is stored, read one by one: a key refused since it was stored — a screen took it — puts
 * back that action's ruled key and costs the person nothing else. If that leaves two actions on one key, the ruled
 * keys come back whole, since which of the two the key would run could not be told.
 */
export function parseShellShortcuts(raw: unknown): ShellKeys | undefined {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return undefined;
  const stored = raw as Record<string, unknown>;
  const keys = Object.fromEntries(
    SHELL_SHORTCUTS.map((name) => {
      const value = stored[name];
      const key = typeof value === 'string' ? value.toLowerCase() : '';
      return [name, shellKeyRefusal(key) === null ? key : DEFAULT_SHORTCUTS[name]];
    }),
  ) as ShellKeys;

  return new Set(Object.values(keys)).size === SHELL_SHORTCUTS.length ? keys : DEFAULT_SHORTCUTS;
}

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
  if (!SCREEN_KEYS.includes(shortcut.toLowerCase())) {
    throw new Error(
      `"${shortcut}" is not one of the screen keys (${SCREEN_KEYS.join(', ')}); a person may give it to the shell.`,
    );
  }
}
