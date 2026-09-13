// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import type { NumberingSeriesRow } from './company-types';
import { EstablishmentsFacade } from './establishments-facade';
import { NumberingPage } from './numbering-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      company: {
        numbering: {
          title: 'Numérotation',
          preview: 'Prochain numéro : {{number}}',
          preview_invalid: 'Format invalide',
          document_types: { delivery_note: 'Bon de livraison' },
          reset: { yearly: 'Chaque année' },
        },
      },
    });
  }
}

const deliveryNotes: NumberingSeriesRow = {
  id: 's1',
  establishmentId: 'e1',
  establishmentCode: '000',
  documentType: 'delivery_note',
  format: 'BL-{YYYY}-{SEQ:5}',
  nextNumber: 1,
  resetPeriod: 'yearly',
  isDefault: true,
  preview: 'BL-2026-00001',
};

describe('NumberingPage', () => {
  const facade = {
    series: signal<readonly NumberingSeriesRow[]>([deliveryNotes]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<string | null>(null).asReadonly(),
    loadSeries: vi.fn(),
    reviseSeries: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<NumberingPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  beforeEach(async () => {
    facade.loadSeries.mockReset().mockResolvedValue(undefined);
    facade.reviseSeries.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [NumberingPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: EstablishmentsFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(NumberingPage);
    await settle();
  });

  it('shows each series with the number it would give next', () => {
    expect(facade.loadSeries).toHaveBeenCalledWith('c1');
    const row = q('series-000-delivery_note')?.textContent ?? '';
    expect(row).toContain('Bon de livraison');
    expect(row).toContain('BL-2026-00001');
  });

  it('shows the next number while a format is typed, and says when it cannot number', async () => {
    q('series-edit-000-delivery_note')!.click();
    await settle();
    type('field-format', 'BL-{EST}-{SEQ:4}');
    type('field-nextNumber', '12');
    await settle();

    expect(q('series-preview')?.textContent).toContain('Prochain numéro : BL-000-0012');

    type('field-format', 'BL-{YYYY}');
    await settle();

    expect(q('series-preview')?.textContent).toContain('Format invalide');
  });

  it('revises the series with what was typed', async () => {
    q('series-edit-000-delivery_note')!.click();
    await settle();
    type('field-format', 'BL-{EST}-{SEQ:4}');
    type('field-nextNumber', '12');
    q('series-save')!.click();
    await settle();

    expect(facade.reviseSeries).toHaveBeenCalledWith('c1', 's1', {
      format: 'BL-{EST}-{SEQ:4}',
      nextNumber: 12,
      resetPeriod: 'yearly',
    });
  });
});
