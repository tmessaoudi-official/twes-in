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
import { CompanySettings } from './company-settings-facade';
import { SettingsPage } from './settings-page';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ settings: { title: 'Paramètres', saved: 'Enregistré' } });
  }
}

const terms: SettingRow = {
  key: 'document.payment_terms_days',
  chain: 'parties',
  type: 'int',
  labelKey: 'settings.document.payment_terms_days',
  module: 'core',
  defaultValue: 30,
  value: 45,
  source: 'company',
  levels: [{ level: 'company', value: 45 }],
  overridableLevels: ['company', 'customer_group', 'customer', 'document'],
  writableLevels: ['company'],
  choices: [],
  min: '0',
  max: '365',
  maxLength: null,
  pattern: null,
};

describe('SettingsPage', () => {
  const rows = signal<readonly SettingRow[]>([terms]);
  const settings = {
    rows: rows.asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<SettingsError | null>(null).asReadonly(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    save: vi.fn(),
    reset: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<SettingsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function open(): Promise<void> {
    TestBed.configureTestingModule({
      imports: [SettingsPage],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: CompanySettings, useValue: settings },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
    fixture = TestBed.createComponent(SettingsPage);
    await settle();
  }

  beforeEach(() => {
    rows.set([terms]);
    settings.load.mockReset().mockResolvedValue(undefined);
    settings.save.mockReset().mockResolvedValue(true);
    settings.reset.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
  });

  it("loads the company's settings and shows each at the company's value", async () => {
    await open();

    expect(settings.load).toHaveBeenCalledWith('c1');
    expect(auth.hasPermission).toHaveBeenCalledWith('company.settings');
    expect((q('field-document__payment_terms_days') as HTMLInputElement).value).toBe('45');
  });

  it('saves only what changed, at the company level', async () => {
    await open();
    const input = q('field-document__payment_terms_days') as HTMLInputElement;
    input.value = '60';
    input.dispatchEvent(new Event('input'));
    q('settings-save')!.click();
    await settle();

    expect(settings.save).toHaveBeenCalledWith('c1', [
      { key: 'document.payment_terms_days', value: 60 },
    ]);
    expect(successToasts()).toContain('settings.saved');
  });

  it('keeps what is typed when the chain is read again with the same content', async () => {
    await open();
    const input = q('field-document__payment_terms_days') as HTMLInputElement;
    input.value = '10';
    input.dispatchEvent(new Event('input'));

    rows.set([{ ...terms, levels: [...terms.levels] }]);
    await settle();

    expect((q('field-document__payment_terms_days') as HTMLInputElement).value).toBe('10');
  });

  it('takes a value another person saved into a quiet form', async () => {
    await open();
    settings.load.mockImplementation(async () => {
      rows.set([{ ...terms, levels: [{ level: 'company', value: 60 }] }]);
    });

    await announceSaved('setting', 'row-1');
    await settle();

    expect((q('field-document__payment_terms_days') as HTMLInputElement).value).toBe('60');
  });

  it('resets a value the company holds to the level above', async () => {
    await open();
    q('settings-reset-document.payment_terms_days')!.click();
    await settle();

    expect(settings.reset).toHaveBeenCalledWith('c1', 'document.payment_terms_days');
  });

  it('neither loads nor offers the form without the settings permission', async () => {
    auth.hasPermission.mockReturnValue(false);
    await open();

    expect(settings.load).not.toHaveBeenCalled();
    expect(q('settings-forbidden')).not.toBeNull();
    expect(q('settings-form')).toBeNull();
  });
});
