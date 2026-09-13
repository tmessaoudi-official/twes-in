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
import { FiscalFacade } from './fiscal-facade';
import { FiscalUnitsPage } from './fiscal-units-page';
import type { UnitRow } from './fiscal-types';

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
};

describe('FiscalUnitsPage', () => {
  const fiscal = {
    units: signal<readonly UnitRow[]>([hour]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<string | null>(null).asReadonly(),
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
    fiscal.loadUnits.mockReset().mockResolvedValue(undefined);
    fiscal.createUnit.mockReset().mockResolvedValue(true);
    fiscal.reviseUnit.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [FiscalUnitsPage],
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
        { provide: FiscalFacade, useValue: fiscal },
        { provide: AuthFacade, useValue: auth },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
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
    });
  });

  it('revises a unit without offering to change its code', async () => {
    q('unit-edit-HUR')!.click();
    await settle();

    expect(q('field-code')).toBeNull();
    type('field-name', 'Heure de travail');
    q('unit-save')!.click();
    await settle();

    expect(fiscal.reviseUnit).toHaveBeenCalledWith(
      'c1',
      'u1',
      expect.objectContaining({ code: 'HUR', name: 'Heure de travail' }),
    );
  });
});
