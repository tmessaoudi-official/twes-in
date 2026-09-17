// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import type { SettingRow, SettingsError } from '../shared/settings/settings-types';
import { PartyDefaults } from './party-defaults';
import { PartySettings } from './party-settings-facade';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ customers: { defaults: { errors: { invalid: 'Valeur refusée.' } } } });
  }
}

// As a customer of a group sees them: the group says 45 days, the customer says nothing yet.
const terms: SettingRow = {
  key: 'document.payment_terms_days',
  chain: 'parties',
  type: 'int',
  labelKey: 'settings.document.payment_terms_days',
  module: 'core',
  defaultValue: 30,
  value: 45,
  source: 'customer_group',
  levels: [{ level: 'customer_group', value: 45 }],
  overridableLevels: ['company', 'customer_group', 'customer', 'document'],
  writableLevels: ['customer'],
  choices: [],
  min: 0,
  max: 365,
  maxLength: null,
  pattern: null,
};

describe('PartyDefaults', () => {
  const rows = signal<readonly SettingRow[]>([terms]);
  const error = signal<SettingsError | null>(null);
  const facade = {
    rows: rows.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    load: vi.fn(),
    save: vi.fn(),
    reset: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  let fixture: ComponentFixture<PartyDefaults>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(PartyDefaults);
    fixture.componentRef.setInput('subject', { customerId: 'k1' });
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    rows.set([terms]);
    error.set(null);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.save.mockReset().mockResolvedValue(true);
    facade.reset.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [PartyDefaults],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: PartySettings, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
  });

  it("reads the customer's parties chain and starts at what its group says", async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1', { customerId: 'k1' });
    expect((q('field-document__payment_terms_days') as HTMLInputElement).value).toBe('45');
  });

  it("stores only what was changed, at the customer's level", async () => {
    await open();
    const input = q('field-document__payment_terms_days') as HTMLInputElement;
    input.value = '10';
    input.dispatchEvent(new Event('input'));
    q('party-defaults-save')!.click();
    await fixture.whenStable();

    expect(facade.save).toHaveBeenCalledWith('c1', { customerId: 'k1' }, [
      { key: 'document.payment_terms_days', value: 10 },
    ]);
  });

  it('keeps what is typed when the chain is read again with the same content', async () => {
    await open();
    const input = q('field-document__payment_terms_days') as HTMLInputElement;
    input.value = '10';
    input.dispatchEvent(new Event('input'));

    rows.set([{ ...terms, levels: [...terms.levels] }]);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect((q('field-document__payment_terms_days') as HTMLInputElement).value).toBe('10');
  });

  it('shows the values of another subject once they are read', async () => {
    await open();
    facade.load.mockImplementation(async () => {
      rows.set([{ ...terms, levels: [{ level: 'customer_group', value: 60 }] }]);
    });

    fixture.componentRef.setInput('subject', { customerId: 'k2' });
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(facade.load).toHaveBeenLastCalledWith('c1', { customerId: 'k2' });
    expect((q('field-document__payment_terms_days') as HTMLInputElement).value).toBe('60');
  });

  it('takes a value another person saved into a quiet form', async () => {
    await open();
    facade.load.mockImplementation(async () => {
      rows.set([{ ...terms, levels: [{ level: 'customer_group', value: 60 }] }]);
    });

    await announceSaved('setting', 'row-1');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect((q('field-document__payment_terms_days') as HTMLInputElement).value).toBe('60');
  });

  it('offers to reset a value the customer holds, and says why a change was refused', async () => {
    rows.set([{ ...terms, levels: [...terms.levels, { level: 'customer', value: 10 }] }]);
    error.set('invalid');
    await open();

    q('party-defaults-reset-document.payment_terms_days')!.click();
    expect(facade.reset).toHaveBeenCalledWith(
      'c1',
      { customerId: 'k1' },
      'document.payment_terms_days',
    );
    expect(q('party-defaults-error')?.textContent).toContain('Valeur refusée');
  });

  it('shows a reader the values without a way to change them', async () => {
    rows.set([
      { ...terms, writableLevels: [], levels: [...terms.levels, { level: 'customer', value: 10 }] },
    ]);
    await open();

    expect((q('field-document__payment_terms_days') as HTMLInputElement).disabled).toBe(true);
    expect(q('party-defaults-save')).toBeNull();
    expect(q('party-defaults-reset-document.payment_terms_days')).toBeNull();
  });
});
