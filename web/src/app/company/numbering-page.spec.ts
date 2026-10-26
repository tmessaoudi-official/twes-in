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
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import type { NumberingSeriesRow } from './company-types';
import { EstablishmentsFacade } from './establishments-facade';
import { NumberingPage } from './numbering-page';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import type { FieldRefusal } from '../shared/form/field-refusals';

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
      import: {
        rejections: {
          sequence_from: 'Cette série reprend à {{next}} au moins.',
          numbering_format: 'Format refusé : {SEQ:n} sur 1 à {{max}} chiffres.',
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
  numbered: false,
  preview: 'BL-2026-00001',
  version: 1,
};

describe('NumberingPage', () => {
  const series = signal<readonly NumberingSeriesRow[]>([deliveryNotes]);
  const error = signal<string | null>(null);
  const refusals = signal<readonly FieldRefusal[]>([]);
  const facade = {
    series: series.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refusals: refusals.asReadonly(),
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
    series.set([deliveryNotes]);
    error.set(null);
    refusals.set([]);
    facade.loadSeries.mockReset().mockResolvedValue(undefined);
    facade.clearError.mockReset().mockImplementation(() => error.set(null));
    facade.reviseSeries.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [NumberingPage],
      providers: [
        ...provideQuietFeedback(),
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
        { provide: Session, useExisting: AuthFacade },
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
    q('row-action-edit-s1')!.click();
    await settle();
    type('field-format', 'BL-{EST}-{SEQ:4}');
    type('field-nextNumber', '12');
    await settle();

    expect(q('series-preview')?.textContent).toContain('Prochain numéro : BL-000-0012');

    type('field-format', 'BL-{YYYY}');
    await settle();

    expect(q('series-preview')?.textContent).toContain('Format invalide');
  });

  it('keeps where the sequence resumes once documents carry its numbers', async () => {
    series.set([{ ...deliveryNotes, nextNumber: 8, numbered: true, preview: 'BL-2026-00008' }]);
    await settle();
    q('row-action-edit-s1')!.click();
    await settle();

    expect((q('field-nextNumber') as HTMLInputElement).disabled).toBe(true);

    type('field-format', 'BL-{EST}-{SEQ:4}');
    fixture.detectChanges();
    q('series-save')!.click();
    await settle();

    expect(facade.reviseSeries).toHaveBeenCalledWith(
      'c1',
      's1',
      {
        format: 'BL-{EST}-{SEQ:4}',
        nextNumber: 8,
        resetPeriod: 'yearly',
      },
      1,
    );
  });

  it('says each refusal of a save under its field', async () => {
    q('row-action-edit-s1')!.click();
    await settle();
    facade.reviseSeries.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([
        { field: 'format', reason: 'numbering_format', params: { max: 12 } },
        { field: 'nextNumber', reason: 'sequence_from', params: { next: 7 } },
      ]);
      return false;
    });
    type('field-format', 'BL-{SEQ:13}');
    fixture.detectChanges();
    q('series-save')!.click();
    await settle();

    expect(facade.reviseSeries).toHaveBeenCalled();
    expect(q('field-error-format')?.textContent).toContain(
      'Format refusé : {SEQ:n} sur 1 à 12 chiffres.',
    );
    expect(q('field-error-nextNumber')?.textContent).toContain('Cette série reprend à 7 au moins.');
  });

  it('revises the series with what was typed', async () => {
    q('row-action-edit-s1')!.click();
    await settle();
    type('field-format', 'BL-{EST}-{SEQ:4}');
    type('field-nextNumber', '12');
    fixture.detectChanges();
    q('series-save')!.click();
    await settle();

    expect(facade.reviseSeries).toHaveBeenCalledWith(
      'c1',
      's1',
      {
        format: 'BL-{EST}-{SEQ:4}',
        nextNumber: 12,
        resetPeriod: 'yearly',
      },
      1,
    );
  });

  it('after a save refused because a number was given since, takes the number the series is at and saves on it next', async () => {
    q('row-action-edit-s1')!.click();
    await settle();
    facade.reviseSeries.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    facade.loadSeries.mockImplementationOnce(async () =>
      series.set([
        { ...deliveryNotes, nextNumber: 2, numbered: true, preview: 'BL-2026-00002', version: 2 },
      ]),
    );

    type('field-format', 'BL-{EST}-{SEQ:4}');
    fixture.detectChanges();
    q('series-save')!.click();
    await settle();
    await vi.waitFor(() => expect((q('field-nextNumber') as HTMLInputElement).value).toBe('2'));

    expect((q('field-format') as HTMLInputElement).value).toBe('BL-{EST}-{SEQ:4}');
    expect(q('numbering-error')).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    fixture.detectChanges();

    q('series-save')!.click();
    await settle();
    expect(facade.reviseSeries).toHaveBeenLastCalledWith(
      'c1',
      's1',
      { format: 'BL-{EST}-{SEQ:4}', nextNumber: 2, resetPeriod: 'yearly' },
      2,
    );
  });
});
