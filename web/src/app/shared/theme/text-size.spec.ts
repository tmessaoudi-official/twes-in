// SPDX-License-Identifier: AGPL-3.0-or-later

import { PageMemoryStorage } from '../settings/settings-facade';
import {
  keepTextSize,
  storedTextSize,
  TEXT_SIZE_CLASSES,
  TEXT_SIZE_KEY,
  textSizeApplies,
} from './text-size';

describe('the text size kept on this device', () => {
  it('reads 100 when nothing, or anything but a size, is kept', () => {
    const storage = new PageMemoryStorage();
    expect(storedTextSize(storage)).toBe('100');
    storage.setItem(TEXT_SIZE_KEY, '150');
    expect(storedTextSize(storage)).toBe('100');
  });

  it('reads back what was kept', () => {
    const storage = new PageMemoryStorage();
    keepTextSize(storage, '125');
    expect(storage.getItem(TEXT_SIZE_KEY)).toBe('125');
    expect(storedTextSize(storage)).toBe('125');
  });

  it('reads 100 and keeps nothing when the storage refuses, as with site data blocked', () => {
    const refusing = {
      getItem: () => {
        throw new Error('blocked');
      },
      setItem: () => {
        throw new Error('blocked');
      },
      removeItem: () => undefined,
    };
    expect(storedTextSize(refusing)).toBe('100');
    expect(() => keepTextSize(refusing, '112')).not.toThrow();
  });

  it('leaves the customer display at its own size', () => {
    expect(textSizeApplies('/')).toBe(true);
    expect(textSizeApplies('/invoices/12')).toBe(true);
    expect(textSizeApplies('/customer-display')).toBe(false);
  });
});

// index.html applies the size before Angular boots; read from the file itself, so the two never drift apart.
declare const process: {
  getBuiltinModule(name: 'fs'): { readFileSync(path: string, encoding: 'utf8'): string };
};

describe('the boot script in index.html', () => {
  const root = document.documentElement;
  const here = import.meta.url;
  const page = process
    .getBuiltinModule('fs')
    .readFileSync(new URL('../../../index.html', here).pathname, 'utf8');
  const script = /<script nonce="\*\*CSP_NONCE\*\*">([\s\S]*?)<\/script>/.exec(page)?.[1] ?? '';
  const boot = () => new Function(script)();

  afterEach(() => {
    root.classList.remove(...Object.values(TEXT_SIZE_CLASSES).filter((name) => name !== null));
    localStorage.removeItem(TEXT_SIZE_KEY);
    history.replaceState(null, '', '/');
  });

  it('carries the nonce nginx writes in, as the CSP admits no other inline script', () => {
    expect(script).toContain(TEXT_SIZE_KEY);
  });

  it('puts the root at the kept size before the first paint', () => {
    localStorage.setItem(TEXT_SIZE_KEY, '125');
    boot();
    expect(root.classList.contains(TEXT_SIZE_CLASSES['125']!)).toBe(true);
    localStorage.setItem(TEXT_SIZE_KEY, '112');
    root.classList.remove(TEXT_SIZE_CLASSES['125']!);
    boot();
    expect(root.classList.contains(TEXT_SIZE_CLASSES['112']!)).toBe(true);
  });

  it('leaves the root alone at 100 % and for anything but a size', () => {
    localStorage.setItem(TEXT_SIZE_KEY, '200');
    boot();
    expect(root.className).not.toMatch(/twes-text-/);
  });

  it('leaves the customer display at its own size', () => {
    localStorage.setItem(TEXT_SIZE_KEY, '125');
    history.replaceState(null, '', '/customer-display');
    boot();
    expect(root.className).not.toMatch(/twes-text-/);
  });
});

// Every control height Material draws follows the root size, so a wrapped label or Arabic's taller lines are never cut
// at 125 %: a height or size in a component's overrides is in rem, never px.
describe('the stylesheet at every text size', () => {
  const here = import.meta.url;
  const sheet = process
    .getBuiltinModule('fs')
    .readFileSync(new URL('../../../styles.scss', here).pathname, 'utf8');
  const overrides = [
    ...sheet.matchAll(/@include mat\.([a-z-]+)-overrides\(\s*\(([\s\S]*?)\)\s*\);/g),
  ];
  const sized = overrides.flatMap(([, component, body]) =>
    [...body.matchAll(/^\s*([a-z-]*(?:height|size|vertical-padding)):\s*([^,\n]+)/gm)].map(
      ([, token, value]) => ({ component, token, value: value.trim() }),
    ),
  );

  it('reads the heights of every Material component the screens use', () => {
    const components = new Set(sized.map((entry) => entry.component));
    for (const component of [
      'form-field',
      'button',
      'icon-button',
      'button-toggle',
      'table',
      'tabs',
      'list',
      'paginator',
      'checkbox',
      'radio',
      'toolbar',
    ]) {
      expect(components, component).toContain(component);
    }
  });

  it('gives each of them in rem', () => {
    const inPixels = sized
      .filter(
        (entry) =>
          !/size$/.test(entry.token) || /-container-size$|state-layer-size$/.test(entry.token),
      )
      .filter((entry) => !/^-?[\d.]+rem$/.test(entry.value) && !/^var\(/.test(entry.value));
    expect(inPixels).toEqual([]);
  });

  it('sets the root at 112.5 and 125 %', () => {
    expect(sheet).toMatch(/--twes-text-size-112: 112\.5%;/);
    expect(sheet).toMatch(/--twes-text-size-125: 125%;/);
    expect(sheet).toMatch(/&\.twes-text-112 \{\s*font-size: var\(--twes-text-size-112\);/);
    expect(sheet).toMatch(/&\.twes-text-125 \{\s*font-size: var\(--twes-text-size-125\);/);
  });
});
