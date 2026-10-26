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
import { Feedback } from '../shared/feedback/feedback';
import {
  provideQuietFeedback,
  type RecordedFeedback,
  successToasts,
} from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { TextsParts } from '../shared/i18n/texts-part';
import type { FieldRefusal } from '../shared/form/field-refusals';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        locations: { default: 'Par défaut' },
        errors: { in_use: 'Cet emplacement ne peut pas être supprimé.' },
        delete_message: '{{name}} sera supprimé.',
      },
      import: {
        rejections: {
          location_code: 'Le code compte 1 à {{max}} caractères.',
          under_itself: 'Rien ne se range sous lui-même.',
        },
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
  version: 1,
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
  version: 1,
};
const options: StockOptions = {
  establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
  planShapes: [],
  structureShapes: [],
};

describe('StockLocationsPage', () => {
  const texts = { open: vi.fn<(name: string) => Promise<void>>(async () => undefined) };
  const error = signal<InventoryError | null>(null);
  const refusals = signal<readonly FieldRefusal[]>([]);
  const locations = signal<readonly StockLocationRow[]>([site, zone]);
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    locations: locations.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refusals: refusals.asReadonly(),
    loadLocations: vi.fn(),
    createLocation: vi.fn(),
    reviseLocation: vi.fn(),
    deleteLocation: vi.fn(),
    clearError: vi.fn(),
    contents: signal(null).asReadonly(),
    loadContents: vi.fn(),
    reloadContents: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<StockLocationsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  // A menu opens in the CDK overlay, which hangs off the body rather than the component.
  const inMenu = (testId: string): HTMLElement | null =>
    document.body.querySelector(
      `.cdk-overlay-container [data-testid="${testId}"]`,
    ) as HTMLElement | null;

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
    refusals.set([]);
    facade.loadLocations.mockReset().mockResolvedValue(undefined);
    facade.clearError.mockReset().mockImplementation(() => error.set(null));
    facade.createLocation.mockReset().mockResolvedValue(true);
    facade.reviseLocation.mockReset().mockResolvedValue(true);
    facade.deleteLocation.mockReset().mockResolvedValue(true);
    facade.loadContents.mockReset().mockResolvedValue(undefined);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [StockLocationsPage],
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
        { provide: InventoryFacade, useValue: facade },
        { provide: TextsParts, useValue: texts },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(StockLocationsPage);
    await settle();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
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
    fixture.detectChanges();
    q('stock-location-save')!.click();
    await settle();

    expect(facade.createLocation).toHaveBeenCalledWith('c1', {
      establishmentId: 'e1',
      parentId: null,
      kind: 'zone',
      code: 'R1',
      name: 'Rayonnage 1',
    });
    expect(successToasts()).toContain('inventory.locations.saved');
  });

  it('keeps what was typed when the locations arrive after the form was opened', async () => {
    q('stock-location-add')!.click();
    await settle();
    type('field-code', 'R1');
    type('field-name', 'Rayonnage 1');
    locations.set([site, zone, { ...zone, id: 'l3', code: 'Z2', name: 'Zone sèche' }]);
    await settle();
    fixture.detectChanges();
    q('stock-location-save')!.click();
    await settle();

    expect(facade.createLocation).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ code: 'R1', name: 'Rayonnage 1' }),
    );
  });

  it('says on its field what a save was refused for, each in its words', async () => {
    q('row-action-edit-l2')!.click();
    await settle();
    facade.reviseLocation.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([
        { field: 'code', reason: 'location_code', params: { max: 32 } },
        { field: 'parentId', reason: 'under_itself', params: {} },
      ]);
      return false;
    });
    type('field-code', 'Z9');
    fixture.detectChanges();
    q('stock-location-save')!.click();
    await settle();

    expect(facade.reviseLocation).toHaveBeenCalled();
    expect(q('field-error-code')?.textContent).toContain('Le code compte 1 à 32 caractères.');
    expect(q('field-error-parentId')?.textContent).toContain('Rien ne se range sous lui-même.');
    // Said under the fields, so not said again above the form.
    expect(q('stock-locations-error')).toBeNull();
  });

  it('renames a location where it sits, and deletes one but never the default', async () => {
    q('row-action-edit-l2')!.click();
    await settle();
    type('field-name', 'Chambre froide');
    fixture.detectChanges();
    q('stock-location-save')!.click();
    await settle();
    expect(facade.reviseLocation).toHaveBeenCalledWith(
      'c1',
      'l2',
      {
        establishmentId: 'e1',
        parentId: 'l1',
        kind: 'zone',
        code: 'Z1',
        name: 'Chambre froide',
      },
      1,
    );

    // The default location has nowhere to move its stock to, so it offers no delete at all rather than refusing;
    // deleting is destructive, so where it is offered it sits behind "⋮".
    q('row-more-l1')!.click();
    await settle();
    expect(inMenu('row-menu-count_here-l1')).not.toBeNull();
    expect(inMenu('row-menu-delete-l1')).toBeNull();
    (document.body.querySelector('.cdk-overlay-backdrop') as HTMLElement).click();
    await settle();
    q('row-more-l2')!.click();
    await settle();
    inMenu('row-menu-delete-l2')!.click();
    await settle();
    // Destructive, so it asks first, and the question names the row rather than asking about "it".
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      '000 › Z1 — Zone froide',
    );
    (document.querySelector('[data-testid="confirm-run"]') as HTMLElement).click();
    await settle();
    expect(facade.deleteLocation).toHaveBeenCalledWith('c1', 'l2');
  });

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    q('row-action-edit-l2')!.click();
    await settle();
    facade.reviseLocation.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    facade.loadLocations.mockImplementationOnce(async () =>
      locations.set([site, { ...zone, code: 'Z9', version: 2 }]),
    );

    type('field-name', 'Chambre froide');
    fixture.detectChanges();
    q('stock-location-save')!.click();
    await settle();
    await vi.waitFor(() => expect((q('field-code') as HTMLInputElement).value).toBe('Z9'));

    expect((q('field-name') as HTMLInputElement).value).toBe('Chambre froide');
    expect(q('stock-locations-error')).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    fixture.detectChanges();

    q('stock-location-save')!.click();
    await settle();
    expect(facade.reviseLocation).toHaveBeenLastCalledWith(
      'c1',
      'l2',
      { establishmentId: 'e1', parentId: 'l1', kind: 'zone', code: 'Z9', name: 'Chambre froide' },
      2,
    );
  });

  it('takes into the open form what another person saved on the same location', async () => {
    q('row-action-edit-l2')!.click();
    await settle();
    facade.loadLocations.mockImplementationOnce(async () =>
      locations.set([site, { ...zone, name: 'Zone sèche', version: 2 }]),
    );

    await announceSaved('stock_location', 'l2');
    await settle();

    expect((q('field-name') as HTMLInputElement).value).toBe('Zone sèche');
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
    expect(q('row-action-edit-l2')).toBeNull();
    expect(q('row-more-l2')).toBeNull();
    // Reading what a place holds is reading: a reader has it.
    expect(q('row-action-contents-l2')).not.toBeNull();
  });

  // Round 2: every action where its subject appears. A place is counted from its row, in count mode there.
  it('counts a place from its row, at the address its printed label carries', async () => {
    q('row-more-l2')!.click();
    await settle();
    expect(inMenu('row-menu-count_here-l2')?.getAttribute('href')).toBe('/stock/locations/l2');
  });

  /** A place the map has no drawing of is read from its row, in the same words the map uses. */
  it('opens what a place holds from its row', async () => {
    q('row-action-contents-l2')!.click();
    await settle();

    const title = document.body.querySelector('[data-testid="place-contents-title"]');
    expect(title?.textContent).toContain('Z1');
    expect(facade.loadContents).toHaveBeenLastCalledWith('c1', 'l2', '');
    // A count asked from one of its lines says what it changes, in texts opened before the dialog.
    expect(texts.open).toHaveBeenCalledWith('stock-count');
  });
});
