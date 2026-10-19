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
import { SettingsApi } from '../shared/settings/settings-api';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { FiscalFacade } from './fiscal-facade';
import { FiscalUnitsPage } from './fiscal-units-page';
import type { UnitRow } from './fiscal-types';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ fiscal: { edit: 'Modifier', yes: 'Oui', no: 'Non', units: { title: 'Unités' } } });
  }
}

const hour: UnitRow = {
  id: 'u1',
  code: 'HUR',
  name: 'Heure',
  decimals: 2,
  isActive: true,
  sortOrder: 20,
  names: {},
  version: 1,
};

describe('FiscalUnitsPage', () => {
  const units = signal<readonly UnitRow[]>([hour]);
  const error = signal<string | null>(null);
  const fiscal = {
    units: units.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadUnits: vi.fn(),
    createUnit: vi.fn(),
    reviseUnit: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<FiscalUnitsPage>;

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
    units.set([hour]);
    error.set(null);
    fiscal.loadUnits.mockReset().mockResolvedValue(undefined);
    fiscal.clearError.mockReset().mockImplementation(() => error.set(null));
    fiscal.createUnit.mockReset().mockResolvedValue(true);
    fiscal.reviseUnit.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [FiscalUnitsPage],
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
        { provide: FiscalFacade, useValue: fiscal },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        // The company prints in French, and one of its customers in Arabic.
        {
          provide: SettingsApi,
          useValue: { valuesInUse: async () => ({ company: 'fr', parties: ['ar'] }) },
        },
      ],
    });
    fixture = TestBed.createComponent(FiscalUnitsPage);
    await settle();
  });

  it('loads and shows the units of the company being worked in', () => {
    expect(fiscal.loadUnits).toHaveBeenCalledWith('c1');
    expect(q('unit-HUR')?.textContent).toContain('Heure');
  });

  it('adds a unit with its code', async () => {
    q('unit-add')!.click();
    await settle();
    type('field-code', 'TNE');
    type('field-name', 'Tonne');
    type('field-decimals', '3');
    q('unit-save')!.click();
    await settle();

    expect(fiscal.createUnit).toHaveBeenCalledWith('c1', {
      code: 'TNE',
      name: 'Tonne',
      decimals: 3,
      sortOrder: 0,
      isActive: true,
      names: {},
    });
  });

  it('revises a unit without offering to change its code', async () => {
    q('row-action-edit-u1')!.click();
    await settle();

    expect(q('field-code')).toBeNull();
    type('field-name', 'Heure de travail');
    q('unit-save')!.click();
    await settle();

    expect(fiscal.reviseUnit).toHaveBeenCalledWith(
      'c1',
      'u1',
      expect.objectContaining({ code: 'HUR', name: 'Heure de travail' }),
      1,
    );
  });

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    q('row-action-edit-u1')!.click();
    await settle();
    fiscal.reviseUnit.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    fiscal.loadUnits.mockImplementationOnce(async () =>
      units.set([{ ...hour, sortOrder: 70, version: 2 }]),
    );

    type('field-name', 'Heure de travail');
    q('unit-save')!.click();
    await settle();
    await vi.waitFor(() => expect((q('field-sortOrder') as HTMLInputElement).value).toBe('70'));

    expect((q('field-name') as HTMLInputElement).value).toBe('Heure de travail');
    expect(q('units-error')).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    q('unit-save')!.click();
    await settle();
    expect(fiscal.reviseUnit).toHaveBeenLastCalledWith(
      'c1',
      'u1',
      expect.objectContaining({ name: 'Heure de travail', sortOrder: 70 }),
      2,
    );
  });

  it('names a unit in the languages the company prints in, the others asked for, and one let go left out', async () => {
    q('row-action-edit-u1')!.click();
    await settle();

    expect(q('field-name-fr')).not.toBeNull();
    expect(q('field-remove-name-fr')).toBeNull();
    expect(q('field-note-name-fr')).not.toBeNull();
    expect(q('field-name-en')).toBeNull();
    type('field-name-ar', 'ساعة');
    q('field-remove-name-ar')!.click();
    await settle();
    expect(q('field-name-ar')).toBeNull();

    q('names-add')!.click();
    await settle();
    // The dropdown's list opens on the overlay, outside the page.
    document.querySelector<HTMLElement>('[data-testid="names-add-en"]')!.click();
    await settle();
    expect(document.activeElement).toBe(q('field-name-en'));
    type('field-name-en', 'Hour');
    q('unit-save')!.click();
    await settle();

    expect(fiscal.reviseUnit).toHaveBeenCalledWith(
      'c1',
      'u1',
      expect.objectContaining({ names: { en: 'Hour' } }),
      1,
    );
  });

  it('takes into the open form what another person saved on the same unit', async () => {
    q('row-action-edit-u1')!.click();
    await settle();
    fiscal.loadUnits.mockImplementationOnce(async () =>
      units.set([{ ...hour, name: 'Heure facturée', version: 2 }]),
    );

    await announceSaved('unit', 'u1');
    await settle();

    expect((q('field-name') as HTMLInputElement).value).toBe('Heure facturée');
  });
});
