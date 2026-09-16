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
import { InventoryFacade } from './inventory-facade';
import type { InventoryError, StockLocationRow, StockOptions } from './inventory-types';
import { StockLocationsPage } from './stock-locations-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        locations: { default: 'Par défaut' },
        errors: { in_use: 'Cet emplacement ne peut pas être supprimé.' },
      },
    });
  }
}

const site: StockLocationRow = {
  id: 'l1',
  establishmentId: 'e1',
  parentId: null,
  kind: 'site',
  code: '000',
  name: 'Siège',
  isDefault: true,
  childCount: 1,
  movementCount: 3,
};
const zone: StockLocationRow = {
  id: 'l2',
  establishmentId: 'e1',
  parentId: 'l1',
  kind: 'zone',
  code: 'Z1',
  name: 'Zone froide',
  isDefault: false,
  childCount: 0,
  movementCount: 0,
};
const options: StockOptions = {
  products: [],
  establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
};

describe('StockLocationsPage', () => {
  const error = signal<InventoryError | null>(null);
  const locations = signal<readonly StockLocationRow[]>([site, zone]);
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    locations: locations.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadLocations: vi.fn(),
    createLocation: vi.fn(),
    reviseLocation: vi.fn(),
    deleteLocation: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<StockLocationsPage>;

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
    error.set(null);
    locations.set([site, zone]);
    facade.loadLocations.mockReset().mockResolvedValue(undefined);
    facade.createLocation.mockReset().mockResolvedValue(true);
    facade.reviseLocation.mockReset().mockResolvedValue(true);
    facade.deleteLocation.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [StockLocationsPage],
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
        { provide: InventoryFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(StockLocationsPage);
    await settle();
  });

  it('lists the locations by their path and marks the default one', () => {
    expect(facade.loadLocations).toHaveBeenCalledWith('c1');
    expect(q('stock-location-000')?.textContent).toContain('Par défaut');
    expect(q('stock-location-Z1')?.textContent).toContain('000 › Z1 — Zone froide');
  });

  it("adds a location under the default one of the company's only establishment", async () => {
    q('stock-location-add')!.click();
    await settle();
    type('field-code', 'R1');
    type('field-name', 'Rayonnage 1');
    q('stock-location-save')!.click();
    await settle();

    expect(facade.createLocation).toHaveBeenCalledWith('c1', {
      establishmentId: 'e1',
      parentId: null,
      kind: 'zone',
      code: 'R1',
      name: 'Rayonnage 1',
    });
    expect(q('stock-location-saved')).not.toBeNull();
  });

  it('keeps what was typed when the locations arrive after the form was opened', async () => {
    q('stock-location-add')!.click();
    await settle();
    type('field-code', 'R1');
    type('field-name', 'Rayonnage 1');
    locations.set([site, zone, { ...zone, id: 'l3', code: 'Z2', name: 'Zone sèche' }]);
    await settle();
    q('stock-location-save')!.click();
    await settle();

    expect(facade.createLocation).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ code: 'R1', name: 'Rayonnage 1' }),
    );
  });

  it('renames a location where it sits, and deletes one but never the default', async () => {
    q('stock-location-edit-Z1')!.click();
    await settle();
    type('field-name', 'Chambre froide');
    q('stock-location-save')!.click();
    await settle();
    expect(facade.reviseLocation).toHaveBeenCalledWith('c1', 'l2', {
      establishmentId: 'e1',
      parentId: 'l1',
      kind: 'zone',
      code: 'Z1',
      name: 'Chambre froide',
    });

    expect(q('stock-location-delete-000')).toBeNull();
    q('stock-location-delete-Z1')!.click();
    await settle();
    expect(facade.deleteLocation).toHaveBeenCalledWith('c1', 'l2');
  });

  it('says a location in use cannot go', async () => {
    error.set('in_use');
    await settle();

    expect(q('stock-locations-error')?.textContent).toContain('ne peut pas être supprimé');
  });

  it('offers no change to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(StockLocationsPage);
    await settle();

    expect(q('stock-location-add')).toBeNull();
    expect(q('stock-location-edit-Z1')).toBeNull();
    expect(q('stock-location-delete-Z1')).toBeNull();
  });
});
