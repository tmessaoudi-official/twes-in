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
import type { CustomFieldsError } from '../shared/custom-fields/custom-fields-api';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { CustomFieldsFacade } from './custom-fields-facade';
import { CustomFieldsPage } from './custom-fields-page';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import type { FieldRefusal } from '../shared/form/field-refusals';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      company: {
        custom_fields: {
          title: 'Champs personnalisés',
          statuses: { active: 'Actif', retired: 'Retiré' },
          errors: { key_taken: 'Un autre champ porte déjà cette clé.' },
        },
      },
      import: {
        rejections: { choice_twice: 'Ce choix est déjà proposé : retirez l’un des deux.' },
      },
    });
  }
}

const sector: CustomFieldDefinition = {
  id: 'f1',
  entity: 'customer',
  key: 'sector',
  label: 'Secteur',
  type: 'choice',
  required: true,
  choices: ['retail', 'wholesale'],
  sortOrder: 0,
  isActive: true,
  version: 1,
};

describe('CustomFieldsPage', () => {
  const error = signal<CustomFieldsError | null>(null);
  const fields = signal<readonly CustomFieldDefinition[]>([sector]);
  const refusals = signal<readonly FieldRefusal[]>([]);
  const facade = {
    fields: fields.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refusals: refusals.asReadonly(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    create: vi.fn(),
    revise: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<CustomFieldsPage>;

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
    refusals.set([]);
    fields.set([sector]);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.clearError.mockReset().mockImplementation(() => error.set(null));
    facade.create.mockReset().mockResolvedValue(true);
    facade.revise.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [CustomFieldsPage],
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
        { provide: CustomFieldsFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(CustomFieldsPage);
    await settle();
  });

  it('switches to the fields of products and declares one for them', async () => {
    expect(q('custom-fields-entity-customer')?.getAttribute('aria-pressed')).toBe('true');
    q('custom-fields-entity-product')!.click();
    await settle();
    expect(facade.load).toHaveBeenLastCalledWith('c1', 'product');
    expect(q('custom-fields-entity-product')?.getAttribute('aria-pressed')).toBe('true');

    q('custom-field-add')!.click();
    await settle();
    type('field-key', 'warranty');
    type('field-label', 'Garantie');
    fixture.detectChanges();
    q('custom-field-save')!.click();
    await vi.waitFor(() =>
      expect(facade.create).toHaveBeenCalledWith(
        'c1',
        expect.objectContaining({ entity: 'product', key: 'warranty' }),
      ),
    );
  });

  it("loads and shows the company's custom fields", () => {
    expect(facade.load).toHaveBeenCalledWith('c1', 'customer');
    expect(q('custom-field-sector')?.textContent).toContain('Secteur');
    expect(q('custom-field-sector')?.textContent).toContain('Actif');
  });

  it('declares a field with its key and label', async () => {
    q('custom-field-add')!.click();
    await settle();
    type('field-key', 'account_manager');
    type('field-label', 'Chargé de compte');
    fixture.detectChanges();
    q('custom-field-save')!.click();
    await vi.waitFor(() =>
      expect(facade.create).toHaveBeenCalledWith(
        'c1',
        expect.objectContaining({
          key: 'account_manager',
          label: 'Chargé de compte',
          type: 'text',
          choices: [],
        }),
      ),
    );
  });

  it('does not send a key the API would refuse', async () => {
    q('custom-field-add')!.click();
    await settle();
    type('field-key', 'Account Manager');
    type('field-label', 'Chargé');
    fixture.detectChanges();
    q('custom-field-save')!.click();
    await settle();

    expect(facade.create).not.toHaveBeenCalled();
  });

  it('says a refusal of a save under its field', async () => {
    q('row-action-edit-f1')!.click();
    await settle();
    facade.revise.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([{ field: 'choices', reason: 'choice_twice', params: {} }]);
      return false;
    });
    type('field-label', 'Activité');
    fixture.detectChanges();
    q('custom-field-save')!.click();
    await settle();

    expect(facade.revise).toHaveBeenCalled();
    expect(q('field-error-choices')?.textContent).toContain(
      'Ce choix est déjà proposé : retirez l’un des deux.',
    );
  });

  it('revises a field by its identifier, keeping its key and type', async () => {
    q('row-action-edit-f1')!.click();
    await settle();
    type('field-label', 'Activité');
    fixture.detectChanges();
    q('custom-field-save')!.click();
    await vi.waitFor(() =>
      expect(facade.revise).toHaveBeenCalledWith(
        'c1',
        'f1',
        expect.objectContaining({ key: 'sector', type: 'choice', label: 'Activité' }),
        1,
      ),
    );
  });

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    q('row-action-edit-f1')!.click();
    await settle();
    facade.revise.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    facade.load.mockImplementationOnce(async () =>
      fields.set([{ ...sector, sortOrder: 7, version: 2 }]),
    );

    type('field-label', 'Activité');
    fixture.detectChanges();
    q('custom-field-save')!.click();
    await settle();
    await vi.waitFor(() => expect((q('field-sortOrder') as HTMLInputElement).value).toBe('7'));

    expect((q('field-label') as HTMLInputElement).value).toBe('Activité');
    expect(q('custom-fields-error')).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    fixture.detectChanges();

    q('custom-field-save')!.click();
    await settle();
    expect(facade.revise).toHaveBeenLastCalledWith(
      'c1',
      'f1',
      expect.objectContaining({ label: 'Activité', sortOrder: 7 }),
      2,
    );
  });

  it('takes into the open form what another person saved on the same field', async () => {
    q('row-action-edit-f1')!.click();
    await settle();
    facade.load.mockImplementationOnce(async () =>
      fields.set([{ ...sector, label: 'Activité principale', version: 2 }]),
    );

    await announceSaved('custom_field', 'f1');
    await settle();

    expect((q('field-label') as HTMLInputElement).value).toBe('Activité principale');
  });

  it('says why the API refused', async () => {
    error.set('key_taken');
    await settle();

    expect(q('custom-fields-error')?.textContent).toContain('porte déjà cette clé');
  });

  it('offers no change to someone who may not change settings', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(CustomFieldsPage);
    await settle();

    expect(q('custom-field-add')).toBeNull();
    expect(q('row-action-edit-f1')).toBeNull();
  });
});
