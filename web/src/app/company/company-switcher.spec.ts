// SPDX-License-Identifier: AGPL-3.0-or-later

import { ApplicationRef, computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { CompanyFacade } from './company-facade';
import { CompanySwitcher } from './company-switcher';
import { NotificationsFacade } from '../notifications/notifications-facade';
import type { CompanyOption } from './company-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      roles: { owner: 'Propriétaire', support: 'Support' },
      company: {
        choose: 'Choisir',
        unread: '{{count}} notification(s) non lue(s)',
        unread_elsewhere: '{{count}} notification(s) non lue(s) dans vos autres entreprises',
      },
    });
  }
}

/** docs/SPEC.md § 7: the switcher shows each company's logo where it has one, and its initial where it has not. */
describe('CompanySwitcher', () => {
  const acme: CompanyOption = {
    id: 'c1',
    name: 'Acme',
    status: 'active',
    role: 'owner',
    pinned: false,
    logoVersion: 'v1',
    supportAccessUntil: null,
    supportAccessReason: null,
  };
  const globex: CompanyOption = { ...acme, id: 'c2', name: 'Globex', logoVersion: null };
  const companies = signal<readonly CompanyOption[]>([acme, globex]);
  const unreadByCompany = signal<Readonly<Record<string, number>>>({});

  const switchTo = vi.fn(() => Promise.resolve(true));

  async function render(
    current: CompanyOption | null,
    variant: 'bar' | 'rail' = 'rail',
  ): Promise<HTMLElement> {
    const open = current === null ? null : { ...current, countryCode: 'TN', currency: 'TND' };
    await TestBed.configureTestingModule({
      imports: [CompanySwitcher],
      providers: [
        provideTranslateService({
          fallbackLang: 'fr',
          loader: provideTranslateLoader(StaticLoader),
        }),
        { provide: LanguageFacade, useValue: { current: () => 'fr' } },
        { provide: NotificationsFacade, useValue: { unreadByCompany } },
        {
          provide: CompanyFacade,
          useValue: {
            companies,
            current: () => open,
            canSwitch: computed(
              () => companies().length > 1 || (companies().length > 0 && open === null),
            ),
            switching: () => false,
            load: () => Promise.resolve(companies()),
            switchTo,
          },
        },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(CompanySwitcher);
    fixture.componentRef.setInput('variant', variant);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('draws the logo of the company in use, read by its version', async () => {
    const root = await render(acme);

    const logo = root.querySelector<HTMLImageElement>('[data-testid="company-logo"]');
    expect(logo?.getAttribute('src')).toBe('/api/companies/c1/logo?v=v1');
    expect(logo?.getAttribute('alt')).toBe('');
  });

  it('draws the company in use by the session’s version, which a new logo refreshes, not the list read at start', async () => {
    const root = await render({ ...acme, logoVersion: 'v2' });

    const logo = root.querySelector<HTMLImageElement>('[data-testid="company-logo"]');
    expect(logo?.getAttribute('src')).toBe('/api/companies/c1/logo?v=v2');
  });

  it('draws the company in use even while the list has not arrived', async () => {
    companies.set([]);
    try {
      const root = await render(acme);

      const logo = root.querySelector<HTMLImageElement>('[data-testid="company-logo"]');
      expect(logo?.getAttribute('src')).toBe('/api/companies/c1/logo?v=v1');
    } finally {
      companies.set([acme, globex]);
    }
  });

  it('keeps the initial for a company without a logo', async () => {
    const root = await render(globex);

    expect(root.querySelector('[data-testid="company-logo"]')).toBeNull();
    expect(root.querySelector('.twes-rail-company-mark')?.textContent?.trim()).toBe('G');
  });

  it('is named on the rail by what it shows, never by an aria-label of the name alone', async () => {
    // WCAG 2.5.3: the country and currency it shows are words a speech user reads off it.
    const root = await render(globex);

    const button = root.querySelector('[data-testid="company-switcher"]');
    expect(button?.getAttribute('aria-label')).toBeNull();
    expect(
      button?.querySelector('.twes-rail-company-text')?.getAttribute('aria-hidden'),
    ).toBeNull();
    expect(button?.querySelector('[data-testid="company-name"]')?.textContent?.trim()).toBe(
      'Globex',
    );
    // Its lines read as words of their own, not « GlobexTunisie ».
    const words = button?.querySelector('.twes-rail-company-text')?.textContent;
    expect(words?.replace(/\s+/g, ' ').trim()).toMatch(/^Globex \S/);
    // Its country is named as every screen names one: its flag and the browser's name for it.
    expect(words).toContain('\u{1F1F9}\u{1F1F3} Tunisie · TND');
  });

  it('shows beside each company what waits in it, and on the closed button what waits in the others', async () => {
    unreadByCompany.set({ c1: 4, c2: 3 });
    const root = await render(acme);

    const closed = root.querySelector('[data-testid="company-switcher"] app-count-badge');
    expect(closed?.querySelector('[role="img"]')?.getAttribute('aria-label')).toBe(
      '3 notification(s) non lue(s) dans vos autres entreprises',
    );

    root.querySelector<HTMLButtonElement>('[data-testid="company-switcher"]')?.click();
    TestBed.inject(ApplicationRef).tick();
    await new Promise((resolve) => setTimeout(resolve));
    const option = (name: string) =>
      document.querySelector(`[data-testid="company-option-${name}"] [role="img"]`);
    expect(option('Globex')?.getAttribute('aria-label')).toBe('3 notification(s) non lue(s)');
    expect(option('Acme')?.getAttribute('aria-label')).toBe('4 notification(s) non lue(s)');
  });

  it('draws no pill for a company with nothing unread, and none on the button when only this company has any', async () => {
    unreadByCompany.set({ c1: 4 });
    const root = await render(acme);

    expect(root.querySelector('[data-testid="company-switcher"] app-count-badge')).toBeNull();
  });

  it('shows the counts in the menu of the bar the phone has, which has no room for one on its button', async () => {
    unreadByCompany.set({ c1: 4, c2: 3 });
    const root = await render(acme, 'bar');

    expect(root.querySelector('[data-testid="company-switcher"] app-count-badge')).toBeNull();
    root.querySelector<HTMLButtonElement>('[data-testid="company-switcher"]')?.click();
    TestBed.inject(ApplicationRef).tick();
    await new Promise((resolve) => setTimeout(resolve));
    expect(
      document
        .querySelector('[data-testid="company-option-Globex"] [role="img"]')
        ?.getAttribute('aria-label'),
    ).toBe('3 notification(s) non lue(s)');
  });

  describe('with no company open, as an operator offered one under support access', () => {
    // docs/SPEC.md § 7: « Choisir une entreprise » whenever there is a company and none is open, the only one too.
    const atlas: CompanyOption = {
      ...acme,
      id: 'c3',
      name: 'Atlas',
      role: 'support',
      logoVersion: null,
      supportAccessUntil: '2026-10-27T10:00:00+01:00',
      supportAccessReason: null,
    };

    beforeEach(() => {
      companies.set([atlas]);
      switchTo.mockClear();
    });
    afterEach(() => companies.set([acme, globex]));

    it.each(['rail', 'bar'] as const)('offers it in the %s and opens it', async (variant) => {
      const root = await render(null, variant);

      const button = root.querySelector<HTMLButtonElement>('[data-testid="company-switcher"]');
      expect(button?.textContent).toContain('Choisir');
      button?.click();
      TestBed.inject(ApplicationRef).tick();
      await new Promise((resolve) => setTimeout(resolve));
      const option = document.querySelector<HTMLButtonElement>(
        '[data-testid="company-option-Atlas"]',
      );
      expect(option?.textContent).toContain('Support');
      option?.click();
      expect(switchTo).toHaveBeenCalledWith('c3');
    });
  });

  it('draws no menu for the one company there is when it is open', async () => {
    companies.set([acme]);
    try {
      const root = await render(acme, 'bar');

      expect(root.querySelector('[data-testid="company-switcher"]')).toBeNull();
      expect(root.querySelector('[data-testid="company-name"]')?.textContent?.trim()).toBe('Acme');
    } finally {
      companies.set([acme, globex]);
    }
  });

  it('names the company in a tooltip on the phone’s bar, where a long name is cut short', async () => {
    // Audit 2026-10-06 V-1: the name ends in an ellipsis beside the bar's icons, and the tooltip says it whole.
    const root = await render(acme, 'bar');

    expect(root.querySelector('[data-testid="company-switcher"]')?.getAttribute('aria-label')).toBe(
      acme.name,
    );
  });
});
