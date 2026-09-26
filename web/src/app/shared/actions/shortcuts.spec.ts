// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  DEFAULT_SHORTCUTS,
  isTypingTarget,
  matchesShortcut,
  parseShellShortcuts,
  refuseReservedShortcut,
  RESERVED_KEYS,
  SCREEN_KEYS,
  shellKeyRefusal,
} from './shortcuts';

function press(key: string, modifiers: Partial<KeyboardEvent> = {}): KeyboardEvent {
  return new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...modifiers });
}

describe('matchesShortcut', () => {
  it('matches the character the keyboard actually produced, whatever the layout', () => {
    // The event's key says what was typed, however the board types it — which is what makes a declared
    // shortcut AZERTY-safe without a table of layouts (docs/SPEC.md § 7, 2026-09-16).
    expect(matchesShortcut(press('n'), 'n')).toBe(true);
    expect(matchesShortcut(press('N', { shiftKey: true }), 'n')).toBe(true);
    expect(matchesShortcut(press('e'), 'n')).toBe(false);
  });

  it('refuses Ctrl and Meta, which belong to the browser', () => {
    expect(matchesShortcut(press('n', { ctrlKey: true }), 'n')).toBe(false);
    expect(matchesShortcut(press('n', { metaKey: true }), 'n')).toBe(false);
    expect(matchesShortcut(press('n', { ctrlKey: true, metaKey: true }), 'n')).toBe(false);
  });

  it('accepts the Alt a French keyboard needs to produce the character at all', () => {
    // "[" is AltGr 5 on AZERTY, which reports Ctrl and Alt TOGETHER; Option types the same character on a French
    // Mac and reports Alt alone. Refusing Alt would mean a shortcut nobody on an AZERTY board can press — and the
    // sidebar's own "[" worked this way before this file existed.
    expect(matchesShortcut(press('[', { ctrlKey: true, altKey: true }), '[')).toBe(true);
    expect(matchesShortcut(press('[', { altKey: true }), '[')).toBe(true);
  });

  it('refuses a key another handler has already answered', () => {
    const event = press('n');
    event.preventDefault();
    expect(matchesShortcut(event, 'n')).toBe(false);
  });
});

describe('isTypingTarget', () => {
  function inDom(html: string): Element {
    const host = document.createElement('div');
    host.innerHTML = html;
    document.body.append(host);
    return host.firstElementChild as Element;
  }

  afterEach(() => {
    document.querySelectorAll('body > div').forEach((node) => node.remove());
  });

  it('is true inside anything a person types into', () => {
    expect(isTypingTarget(inDom('<input />'))).toBe(true);
    expect(isTypingTarget(inDom('<textarea></textarea>'))).toBe(true);
    expect(isTypingTarget(inDom('<select></select>'))).toBe(true);
    expect(
      isTypingTarget(inDom('<div contenteditable="true"><span>x</span></div>').firstElementChild),
    ).toBe(true);
  });

  it('is true anywhere in an overlay, since a dialog or a menu owns the keyboard while it is open', () => {
    expect(
      isTypingTarget(inDom('<div class="cdk-overlay-container"><button>x</button></div>')),
    ).toBe(true);
  });

  it('is false on the page itself, which is where a shortcut is meant to work', () => {
    expect(isTypingTarget(inDom('<button>x</button>'))).toBe(false);
    expect(isTypingTarget(null)).toBe(false);
  });
});

describe('refuseReservedShortcut', () => {
  it('names every key the browser or the interface already owns, and says WHY it refuses each', () => {
    // A declaration is checked where it is written, not discovered by a person whose key does nothing. The reason
    // matters: most reserved keys are named ("Enter", "ArrowUp"), so a length check running first would answer
    // "not one character" for all of them and the reserved list would never be read.
    for (const key of RESERVED_KEYS) {
      expect(() => refuseReservedShortcut(key), key).toThrow(/reserved/i);
    }
    expect(RESERVED_KEYS.length).toBeGreaterThanOrEqual(8);
  });

  it('refuses the two single keys a browser answers itself', () => {
    // Firefox opens quick-find on / and on ', both without a modifier: a shortcut there is taken before us.
    expect(() => refuseReservedShortcut('/')).toThrow(/reserved/i);
    expect(() => refuseReservedShortcut("'")).toThrow(/reserved/i);
  });

  it('refuses the two the shell answers everywhere, which a screen would lose to', () => {
    // "?" opens the shortcut sheet and "[" folds the sidebar, both from any screen: a screen claiming one either
    // never fires or fires alongside the shell, decided by whichever handler ran first.
    expect(() => refuseReservedShortcut('?')).toThrow(/reserved/i);
    expect(() => refuseReservedShortcut('[')).toThrow(/reserved/i);
  });

  it('refuses every key the shell answers by default — C, N, E and / (docs/SPEC.md § 7, 2026-09-24 22:51)', () => {
    expect(Object.values(DEFAULT_SHORTCUTS)).toEqual(['c', 'n', 'e', '/']);
    for (const key of Object.values(DEFAULT_SHORTCUTS)) {
      expect(() => refuseReservedShortcut(key), key).toThrow(/reserved/i);
    }
  });

  it('refuses a shortcut that is not one single character', () => {
    expect(() => refuseReservedShortcut('nn')).toThrow(/one character/i);
    expect(() => refuseReservedShortcut('')).toThrow(/one character/i);
  });

  it('accepts the keys screens share, and no other, so a person may give the shell any key but those', () => {
    // The keys a person may choose for the shell (row 125) are everything but the browser's, the interface's and
    // these: a screen claiming a key outside them could collide with a person's own choice.
    expect(SCREEN_KEYS).toEqual(['s', 'v', 'l', 'p']);
    for (const key of SCREEN_KEYS) expect(() => refuseReservedShortcut(key), key).not.toThrow();
    for (const key of ['i', '7', 'é', 'x']) {
      expect(() => refuseReservedShortcut(key), key).toThrow(/screen/i);
    }
  });
});

// docs/SPEC.md § 7, 2026-09-24 22:51, row 125: each person may give the shell's four actions keys of their own.
describe('shellKeyRefusal', () => {
  it('refuses what the browser, the interface, a screen or a till count already answers, saying which', () => {
    expect(shellKeyRefusal("'")).toBe('browser');
    expect(shellKeyRefusal('?')).toBe('interface');
    expect(shellKeyRefusal('[')).toBe('interface');
    for (const key of SCREEN_KEYS) expect(shellKeyRefusal(key), key).toBe('screen');
    // "5×" makes the next scan count five, so a digit or a times sign is the count's.
    for (const key of ['0', '5', '9', 'x', '*', '×'])
      expect(shellKeyRefusal(key), key).toBe('count');
    for (const key of ['', 'Enter', 'ab', ' ']) expect(shellKeyRefusal(key), key).toBe('length');
  });

  it('accepts any other single character, including the ruled ones', () => {
    for (const key of ['c', 'n', 'e', '/', 'k', 'é', 'q', ';']) {
      expect(shellKeyRefusal(key), key).toBeNull();
    }
  });
});

describe('parseShellShortcuts', () => {
  it('answers the ruled keys for nothing stored, and the person’s own for what they chose', () => {
    expect(parseShellShortcuts(null)).toBeUndefined();
    expect(parseShellShortcuts('c')).toBeUndefined();
    expect(parseShellShortcuts({ create: 'K' })).toEqual({ ...DEFAULT_SHORTCUTS, create: 'k' });
  });

  it('keeps each entry that is still allowed, and puts back the ruled key for one that is not', () => {
    // A key refused since it was stored — a screen took it — costs that one key, not the person's others.
    expect(parseShellShortcuts({ create: 's', new: 'j', next: 42 })).toEqual({
      ...DEFAULT_SHORTCUTS,
      new: 'j',
    });
  });

  it('falls back to the ruled keys whole when two actions would share one', () => {
    expect(parseShellShortcuts({ create: 'n' })).toEqual(DEFAULT_SHORTCUTS);
  });
});
