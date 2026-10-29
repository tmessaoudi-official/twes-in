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
import type { CompanyError, CompanyProfile, EstablishmentRow } from './company-types';
import { CompanyProfileFacade } from './company-profile-facade';
import { EstablishmentsFacade } from './establishments-facade';
import { EstablishmentsPage } from './establishments-page';
import { ArticleSettings } from '../products/article-settings-facade';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import type { FieldRefusal } from '../shared/form/field-refusals';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      company: {
        establishments: {
          title: 'Établissements',
          yes: 'Oui',
          no: 'Non',
          errors: { code_taken: 'Un autre établissement porte déjà ce code.' },
        },
      },
      import: {
        rejections: {
          default_kept: 'L’entreprise garde toujours un établissement par défaut.',
          code_kept: 'Des documents numérotés portent ce code : il ne change plus.',
        },
      },
    });
  }
}

const head: EstablishmentRow = {
  id: 'e1',
  code: '000',
  name: 'Acme',
  addressLine1: null,
  addressLine2: null,
  postalCode: null,
  city: 'Tunis',
  phone: null,
  email: null,
  isDefault: true,
  codePattern: '^[0-9]{3}$',
  codeLocked: false,
  contactsToCheck: [],
  version: 1,
};

describe('EstablishmentsPage', () => {
  const error = signal<CompanyError | null>(null);
  const establishments = signal<readonly EstablishmentRow[]>([head]);
  const refusals = signal<readonly FieldRefusal[]>([]);
  const facade = {
    establishments: establishments.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refusals: refusals.asReadonly(),
    loadEstablishments: vi.fn(),
    createEstablishment: vi.fn(),
    reviseEstablishment: vi.fn(),
    clearError: vi.fn(),
  };
  const articleSettings = {
    rows: signal([]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    refusals: signal([]).asReadonly(),
    load: vi.fn(async () => undefined),
    save: vi.fn(async () => true),
    reset: vi.fn(async () => true),
    clearError: vi.fn(),
  };
  const profile = signal<Partial<CompanyProfile> | null>(null);
  const profileFacade = {
    profile: profile.asReadonly(),
    load: vi.fn<(companyId: string) => Promise<void>>(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<EstablishmentsPage>;

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
    establishments.set([head]);
    facade.loadEstablishments.mockReset().mockResolvedValue(undefined);
    facade.clearError.mockReset().mockImplementation(() => error.set(null));
    facade.createEstablishment.mockReset().mockResolvedValue(true);
    facade.reviseEstablishment.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    profile.set(null);
    profileFacade.load.mockReset().mockImplementation(async () =>
      profile.set({
        addressLine1: 'Avenue Habib Bourguiba',
        addressLine2: null,
        postalCode: '1001',
        city: 'Tunis',
      }),
    );

    TestBed.configureTestingModule({
      imports: [EstablishmentsPage],
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
        { provide: CompanyProfileFacade, useValue: profileFacade },
        { provide: ArticleSettings, useValue: articleSettings },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(EstablishmentsPage);
    await settle();
  });

  it('loads and shows the establishments of the company being worked in', () => {
    expect(facade.loadEstablishments).toHaveBeenCalledWith('c1');
    expect(q('establishment-000')?.textContent).toContain('Tunis');
    expect(q('establishment-000')?.textContent).toContain('Oui');
  });

  it('adds an establishment with a code of the shape its preset gives', async () => {
    q('establishment-add')!.click();
    await settle();
    type('field-code', '001');
    type('field-name', 'Agence de Sfax');
    fixture.detectChanges();
    q('establishment-save')!.click();
    await settle();

    expect(facade.createEstablishment).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ code: '001', name: 'Agence de Sfax', isDefault: false }),
    );
  });

  // Round 2: a related field offers its link. A shop's first place is usually where the company itself is.
  it('copies the company’s address into a new establishment, still editable', async () => {
    expect(profileFacade.load).toHaveBeenCalledWith('c1');
    q('establishment-add')!.click();
    await settle();
    type('field-code', '001');
    type('field-name', 'Dépôt');
    type('field-addressLine2', 'Bloc B');
    q('establishment-copy-company-address')!.click();
    await settle();
    expect(
      ['addressLine1', 'addressLine2', 'postalCode', 'city'].map(
        (id) => (q(`field-${id}`) as HTMLInputElement).value,
      ),
    ).toEqual(['Avenue Habib Bourguiba', '', '1001', 'Tunis']);
    type('field-postalCode', '1002');
    q('establishment-save')!.click();
    await settle();

    expect(facade.createEstablishment).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        addressLine1: 'Avenue Habib Bourguiba',
        addressLine2: null,
        postalCode: '1002',
        city: 'Tunis',
      }),
    );
  });

  it('offers no copy while the company’s profile holds no address', async () => {
    profile.set({ addressLine1: null, addressLine2: null, postalCode: null, city: null });
    q('establishment-add')!.click();
    await settle();
    expect(q('field-addressLine1')).not.toBeNull();
    expect(q('establishment-copy-company-address')).toBeNull();
  });

  it('does not send a code the preset would refuse', async () => {
    q('establishment-add')!.click();
    await settle();
    type('field-code', '12');
    type('field-name', 'Agence');
    fixture.detectChanges();
    q('establishment-save')!.click();
    await settle();

    expect(facade.createEstablishment).not.toHaveBeenCalled();
  });

  it('says each refusal of a save under its field', async () => {
    q('row-action-edit-e1')!.click();
    await settle();
    facade.reviseEstablishment.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([
        { field: 'isDefault', reason: 'default_kept', params: {} },
        { field: 'code', reason: 'code_kept', params: {} },
      ]);
      return false;
    });
    type('field-name', 'Siège social');
    fixture.detectChanges();
    q('establishment-save')!.click();
    await settle();

    expect(facade.reviseEstablishment).toHaveBeenCalled();
    expect(q('field-error-isDefault')?.textContent).toContain(
      'L’entreprise garde toujours un établissement par défaut.',
    );
    expect(q('field-error-code')?.textContent).toContain(
      'Des documents numérotés portent ce code : il ne change plus.',
    );
  });

  it('names above the list each establishment keeping a contact today’s rules refuse, and says it in its editor', async () => {
    establishments.set([{ ...head, contactsToCheck: ['phone'] }]);
    await settle();

    const listed = [
      ...(q('establishments-check-reasons')?.querySelectorAll('[data-reason]') ?? []),
    ];
    expect(listed.map((reason) => reason.getAttribute('data-reason'))).toEqual(['e1-phone']);
    expect(listed[0]?.textContent).toContain('check.establishment_kept_phone');

    q('row-action-edit-e1')!.click();
    await settle();
    const own = [...(q('establishment-check-reasons')?.querySelectorAll('[data-reason]') ?? [])];
    expect(own.map((reason) => reason.getAttribute('data-reason'))).toEqual(['phone']);

    establishments.set([{ ...head, contactsToCheck: [] }]);
    await settle();
    expect(q('establishment-check')).toBeNull();
    expect(q('establishments-check')).toBeNull();
  });

  it('names above the list an establishment keeping a phone the platform keeps outside its country’s ranges', async () => {
    establishments.set([{ ...head, contactsToCheck: ['phone_outside_ranges'] }]);
    await settle();

    const listed = [
      ...(q('establishments-check-reasons')?.querySelectorAll('[data-reason]') ?? []),
    ];
    expect(listed[0]?.textContent).toContain('check.establishment_kept_phone_outside_ranges');
  });

  it('revises an establishment by its identifier', async () => {
    q('row-action-edit-e1')!.click();
    await settle();
    type('field-name', 'Siège social');
    fixture.detectChanges();
    q('establishment-save')!.click();
    await settle();

    expect(facade.reviseEstablishment).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ code: '000', name: 'Siège social', isDefault: true }),
      1,
    );
  });

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    q('row-action-edit-e1')!.click();
    await settle();
    facade.reviseEstablishment.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    facade.loadEstablishments.mockImplementationOnce(async () =>
      establishments.set([{ ...head, city: 'Sfax', version: 2 }]),
    );

    type('field-name', 'Siège social');
    fixture.detectChanges();
    q('establishment-save')!.click();
    await settle();
    await vi.waitFor(() => expect((q('field-city') as HTMLInputElement).value).toBe('Sfax'));

    expect((q('field-name') as HTMLInputElement).value).toBe('Siège social');
    expect(q('establishments-error')).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    fixture.detectChanges();

    q('establishment-save')!.click();
    await settle();
    expect(facade.reviseEstablishment).toHaveBeenLastCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ name: 'Siège social', city: 'Sfax' }),
      2,
    );
  });

  it('takes into the open form what another person saved on the same establishment', async () => {
    q('row-action-edit-e1')!.click();
    await settle();
    facade.loadEstablishments.mockImplementationOnce(async () =>
      establishments.set([{ ...head, name: 'Acme Tunis', version: 2 }]),
    );

    await announceSaved('establishment', 'e1');
    await settle();

    expect((q('field-name') as HTMLInputElement).value).toBe('Acme Tunis');
  });

  it('revises an establishment whose code numbered documents carry, keeping the code', async () => {
    establishments.set([{ ...head, codeLocked: true }]);
    await settle();
    q('row-action-edit-e1')!.click();
    await settle();

    expect((q('field-code') as HTMLInputElement).disabled).toBe(true);

    type('field-name', 'Siège social');
    fixture.detectChanges();
    q('establishment-save')!.click();
    await settle();

    expect(facade.reviseEstablishment).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ code: '000', name: 'Siège social' }),
      1,
    );
  });

  it('says why the API refused', async () => {
    error.set('code_taken');
    await settle();

    expect(q('establishments-error')?.textContent).toContain('porte déjà ce code');
  });

  it('offers no change to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(EstablishmentsPage);
    await settle();

    expect(q('establishment-add')).toBeNull();
    expect(q('row-action-edit-e1')).toBeNull();
  });

  // Audit 2026-10-06, B-13: what the establishment's customer screen says of stock is set on the establishment.
  it("opens an existing establishment's own settings below its form, and none for a new one", async () => {
    articleSettings.load.mockClear();
    q('establishment-add')!.click();
    await settle();
    expect(q('article-defaults')).toBeNull();
    q('establishment-cancel')!.click();
    await settle();

    q('row-action-edit-e1')!.click();
    await settle();
    expect(q('article-defaults')).not.toBeNull();
    expect(articleSettings.load).toHaveBeenCalledWith('c1', { establishmentId: 'e1' });
  });
});
