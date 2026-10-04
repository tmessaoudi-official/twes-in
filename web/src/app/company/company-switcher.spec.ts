// SPDX-License-Identifier: AGPL-3.0-or-later

import { ApplicationRef, signal } from '@angular/core';
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
      roles: { owner: 'Propriétaire' },
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
  };
  const globex: CompanyOption = { ...acme, id: 'c2', name: 'Globex', logoVersion: null };
  const companies = signal<readonly CompanyOption[]>([acme, globex]);
  const unreadByCompany = signal<Readonly<Record<string, number>>>({});

  async function render(current: CompanyOption): Promise<HTMLElement> {
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
            current: () => ({ ...current, countryCode: 'TN', currency: 'TND' }),
            canSwitch: () => true,
            switching: () => false,
            load: () => Promise.resolve(companies()),
            switchTo: () => Promise.resolve(),
          },
        },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(CompanySwitcher);
    fixture.componentRef.setInput('variant', 'rail');
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

  it('keeps the initial for a company without a logo', async () => {
    const root = await render(globex);

    expect(root.querySelector('[data-testid="company-logo"]')).toBeNull();
    expect(root.querySelector('.twes-rail-company-mark')?.textContent?.trim()).toBe('G');
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
});
