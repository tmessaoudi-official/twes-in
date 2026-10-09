// SPDX-License-Identifier: AGPL-3.0-or-later

import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { PageMemoryStorage, SETTINGS_STORAGE } from '../settings/settings-facade';
import { COOKIE_NOTICE_HEIGHT, COOKIE_NOTICE_KEY, CookieNotice } from './cookie-notice';

// shared/ reads no feature's files, the translations included: the strings this notice shows, inline.
const fr = {
  legal: {
    notice: {
      title: 'Cookies',
      text: 'Ce service ne dépose qu’un cookie, celui de votre session.',
      more: 'Les cookies de ce service',
      close: 'J’ai compris',
    },
  },
};

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of(fr);
  }
}

// docs/SPEC.md § 7, 2026-09-26 08:52 (row 149): an informational banner on the first visit, linking to the Cookies
// page; closing it is remembered.
describe('CookieNotice', () => {
  let storage: PageMemoryStorage;
  let fixture: ComponentFixture<CookieNotice>;

  async function render(): Promise<void> {
    await TestBed.configureTestingModule({
      imports: [CookieNotice],
      providers: [
        provideRouter([]),
        { provide: SETTINGS_STORAGE, useValue: storage },
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        provideTranslateLoader(StaticLoader),
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(CookieNotice);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const q = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(`[data-testid="${id}"]`);

  beforeEach(() => {
    storage = new PageMemoryStorage();
  });

  it('says on a first visit what is stored, in a region of its own, and links to the Cookies page', async () => {
    await render();
    const notice = q('cookie-notice');
    expect(notice?.tagName.toLowerCase()).toBe('section');
    expect(notice?.getAttribute('aria-label')).toBe('Cookies');
    expect(notice?.textContent).toContain('celui de votre session');
    expect(q('cookie-notice-more')?.getAttribute('href')).toBe('/legal/cookies');
  });

  it('goes when closed, and stays gone on the next visit', async () => {
    await render();
    q('cookie-notice-close')?.click();
    fixture.detectChanges();
    expect(q('cookie-notice')).toBeNull();
    expect(storage.getItem(COOKIE_NOTICE_KEY)).toBe('closed');

    TestBed.resetTestingModule();
    await render();
    expect(q('cookie-notice')).toBeNull();
  });

  /** A screen sized to the window leaves the notice's height out, or the notice lies over its foot. */
  it('tells the page how tall it stands while open, and nothing once closed or gone', async () => {
    const observed: (() => void)[] = [];
    vi.stubGlobal(
      'ResizeObserver',
      class {
        constructor(private readonly told: () => void) {}
        observe(): void {
          observed.push(this.told);
        }
        disconnect(): void {
          observed.splice(observed.indexOf(this.told), 1);
        }
      },
    );
    const root = document.documentElement.style;
    try {
      await render();
      Object.defineProperty(q('cookie-notice'), 'offsetHeight', { value: 53 });
      for (const told of observed) told();
      expect(root.getPropertyValue(COOKIE_NOTICE_HEIGHT)).toBe('53px');

      q('cookie-notice-close')?.click();
      fixture.detectChanges();
      expect(root.getPropertyValue(COOKIE_NOTICE_HEIGHT)).toBe('');
      expect(observed).toEqual([]);

      TestBed.resetTestingModule();
      storage = new PageMemoryStorage();
      await render();
      for (const told of observed) told();
      expect(root.getPropertyValue(COOKIE_NOTICE_HEIGHT)).not.toBe('');
      fixture.destroy();
      expect(root.getPropertyValue(COOKIE_NOTICE_HEIGHT)).toBe('');
    } finally {
      root.removeProperty(COOKIE_NOTICE_HEIGHT);
      vi.unstubAllGlobals();
    }
  });

  it('still closes where the browser refuses to store anything, for this page', async () => {
    storage.setItem = () => {
      throw new DOMException('refused', 'SecurityError');
    };
    await render();
    q('cookie-notice-close')?.click();
    fixture.detectChanges();
    expect(q('cookie-notice')).toBeNull();
  });
});
