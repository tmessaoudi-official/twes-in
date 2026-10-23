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
import { UnsavedChanges } from '../shared/form/unsaved-changes';
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
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      customers: {
        groups: {
          errors: { in_use: 'Des clients sont encore dans ce groupe.' },
          delete_message: '{{name}} sera supprimé.',
        },
      },
    });
  }
}

const wholesalers: CustomerGroupRow = {
  id: 'g1',
  name: 'Grossistes',
  description: 'Remise 5 %',
  customerCount: 3,
  version: 1,
};

describe('CustomerGroupsPage', () => {
  const error = signal<CustomersError | null>(null);
  const groups = signal<readonly CustomerGroupRow[]>([wholesalers]);
  const facade = {
    groups: groups.asReadonly(),
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
    refusals: signal([]).asReadonly(),
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
    groups.set([wholesalers]);
    error.set(null);
    facade.loadGroups.mockReset().mockResolvedValue(undefined);
    facade.clearError.mockReset().mockImplementation(() => error.set(null));
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
    fixture.detectChanges();
    q('customer-group-save')!.click();
    await settle();

    expect(facade.createGroup).toHaveBeenCalledWith('c1', { name: 'Export', description: null });
  });

  it('counts what is typed in the open editor, holds the page while it is unsaved, and closes without asking', async () => {
    const unsaved = TestBed.inject(UnsavedChanges);
    q('row-action-edit-g1')!.click();
    await settle();
    expect(q('customer-group-bar')).not.toBeNull();
    expect((q('customer-group-save') as HTMLButtonElement).disabled).toBe(true);
    expect(unsaved.count()).toBe(0);

    type('field-name', 'Grossistes TN');
    await settle();

    expect(q('customer-group-changes')?.dataset['count']).toBe('1');
    expect(unsaved.count()).toBe(1);
    const leaving = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(leaving);
    expect(leaving.defaultPrevented).toBe(true);

    q('customer-group-cancel')!.click();
    await settle();
    expect(q('customer-group-form')).toBeNull();
    expect(unsaved.count()).toBe(0);
    expect(facade.reviseGroup).not.toHaveBeenCalled();
  });

  it('renames a group and deletes one by its identifier', async () => {
    q('row-action-edit-g1')!.click();
    await settle();
    expect(partySettings.load).toHaveBeenCalledWith('c1', { customerGroupId: 'g1' });
    type('field-name', 'Grossistes TN');
    fixture.detectChanges();
    q('customer-group-save')!.click();
    await settle();
    expect(facade.reviseGroup).toHaveBeenCalledWith(
      'c1',
      'g1',
      {
        name: 'Grossistes TN',
        description: 'Remise 5 %',
      },
      1,
    );

    // Deleting is destructive, so it sits behind "⋮" rather than under the pointer.
    q('row-more-g1')!.click();
    await settle();
    inMenu('row-menu-delete-g1')!.click();
    await settle();
    // Destructive, so it asks first, and the question names the row rather than asking about "it".
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Grossistes',
    );
    (document.querySelector('[data-testid="confirm-run"]') as HTMLElement).click();
    await settle();
    expect(facade.deleteGroup).toHaveBeenCalledWith('c1', 'g1');
  });

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    q('row-action-edit-g1')!.click();
    await settle();
    facade.reviseGroup.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    facade.loadGroups.mockImplementationOnce(async () =>
      groups.set([{ ...wholesalers, description: 'Remise 8 %', version: 2 }]),
    );

    type('field-name', 'Grossistes TN');
    fixture.detectChanges();
    q('customer-group-save')!.click();
    await settle();
    await vi.waitFor(() =>
      expect((q('field-description') as HTMLTextAreaElement).value).toBe('Remise 8 %'),
    );

    expect((q('field-name') as HTMLInputElement).value).toBe('Grossistes TN');
    expect(q('customer-groups-error')).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    fixture.detectChanges();

    q('customer-group-save')!.click();
    await settle();
    expect(facade.reviseGroup).toHaveBeenLastCalledWith(
      'c1',
      'g1',
      { name: 'Grossistes TN', description: 'Remise 8 %' },
      2,
    );
  });

  it('takes into the open form what another person saved on the same group', async () => {
    q('row-action-edit-g1')!.click();
    await settle();
    facade.loadGroups.mockImplementationOnce(async () =>
      groups.set([{ ...wholesalers, name: 'Revendeurs', version: 2 }]),
    );

    await announceSaved('customer_group', 'g1');
    await settle();

    expect((q('field-name') as HTMLInputElement).value).toBe('Revendeurs');
  });

  it('says a group still holding customers cannot go', async () => {
    error.set('in_use');
    await settle();

    expect(q('customer-groups-error')?.textContent).toContain('encore dans ce groupe');
  });

  it('offers a writer who may not remove customers the edit of a group but not its delete', async () => {
    auth.hasPermission.mockImplementation((permission: string) => permission !== 'customer.remove');
    fixture = TestBed.createComponent(CustomerGroupsPage);
    await settle();

    expect(q('customer-group-add')).not.toBeNull();
    expect(q('row-action-edit-g1')).not.toBeNull();
    // The delete was the menu's one entry, so no "⋮" is drawn.
    expect(q('row-more-g1')).toBeNull();
  });

  it('offers no change to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(CustomerGroupsPage);
    await settle();

    expect(q('customer-group-add')).toBeNull();
    expect(q('row-more-g1')).toBeNull();
  });
});
