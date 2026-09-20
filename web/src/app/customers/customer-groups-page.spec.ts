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
import { CustomerGroupsPage } from './customer-groups-page';
import { CustomersFacade } from './customers-facade';
import { PartySettings } from './party-settings-facade';
import type { SettingRow } from '../shared/settings/settings-types';
import type { CustomerGroupRow, CustomersError } from './customers-types';
import { provideQuietFeedback } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      customers: { groups: { errors: { in_use: 'Des clients sont encore dans ce groupe.' } } },
    });
  }
}

const wholesalers: CustomerGroupRow = {
  id: 'g1',
  name: 'Grossistes',
  description: 'Remise 5 %',
  customerCount: 3,
};

describe('CustomerGroupsPage', () => {
  const error = signal<CustomersError | null>(null);
  const facade = {
    groups: signal<readonly CustomerGroupRow[]>([wholesalers]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadGroups: vi.fn(),
    createGroup: vi.fn(),
    reviseGroup: vi.fn(),
    deleteGroup: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  const partySettings = {
    rows: signal<readonly SettingRow[]>([]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    save: vi.fn(),
    reset: vi.fn(),
    clearError: vi.fn(),
  };
  let fixture: ComponentFixture<CustomerGroupsPage>;

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
    facade.loadGroups.mockReset().mockResolvedValue(undefined);
    facade.createGroup.mockReset().mockResolvedValue(true);
    facade.reviseGroup.mockReset().mockResolvedValue(true);
    facade.deleteGroup.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    partySettings.load.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [CustomerGroupsPage],
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
        { provide: CustomersFacade, useValue: facade },
        { provide: PartySettings, useValue: partySettings },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(CustomerGroupsPage);
    await settle();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('lists the groups with how many customers each holds', () => {
    expect(facade.loadGroups).toHaveBeenCalledWith('c1');
    expect(q('customer-group-Grossistes')?.textContent).toContain('3');
  });

  it('leads back to the customers through the tabs of the customers screens', () => {
    expect(q('customers-tab')?.getAttribute('href')).toBe('/customers');
    expect(q('customer-groups-link')?.closest('nav')).not.toBeNull();
  });

  it('adds a group', async () => {
    q('customer-group-add')!.click();
    await settle();
    type('field-name', 'Export');
    q('customer-group-save')!.click();
    await settle();

    expect(facade.createGroup).toHaveBeenCalledWith('c1', { name: 'Export', description: null });
  });

  it('renames a group and deletes one by its identifier', async () => {
    q('row-action-edit-g1')!.click();
    await settle();
    expect(partySettings.load).toHaveBeenCalledWith('c1', { customerGroupId: 'g1' });
    type('field-name', 'Grossistes TN');
    q('customer-group-save')!.click();
    await settle();
    expect(facade.reviseGroup).toHaveBeenCalledWith('c1', 'g1', {
      name: 'Grossistes TN',
      description: 'Remise 5 %',
    });

    // Deleting is destructive, so it sits behind "⋮" rather than under the pointer.
    q('row-more-g1')!.click();
    await settle();
    inMenu('row-menu-delete-g1')!.click();
    await settle();
    expect(facade.deleteGroup).toHaveBeenCalledWith('c1', 'g1');
  });

  it('says a group still holding customers cannot go', async () => {
    error.set('in_use');
    await settle();

    expect(q('customer-groups-error')?.textContent).toContain('encore dans ce groupe');
  });

  it('offers no change to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(CustomerGroupsPage);
    await settle();

    expect(q('customer-group-add')).toBeNull();
    expect(q('row-more-g1')).toBeNull();
  });
});
