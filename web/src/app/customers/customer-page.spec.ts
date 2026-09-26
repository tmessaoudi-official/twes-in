// SPDX-License-Identifier: AGPL-3.0-or-later
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { ScreenActions } from '../shared/actions/screen-actions';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { CustomerPage } from './customer-page';
import { CustomersFacade } from './customers-facade';
import { PartySettings } from './party-settings-facade';
import type { SettingRow } from '../shared/settings/settings-types';
import type {
  ContactRow,
  CustomerGroupRow,
  CustomerOptions,
  CustomerRow,
  CustomersError,
} from './customers-types';
import { Feedback } from '../shared/feedback/feedback';
import { LiveChanges, type LiveChange } from '../shared/realtime/live-changes';
import {
  offeredNext,
  provideQuietFeedback,
  type RecordedFeedback,
  successToasts,
} from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      customers: {
        errors: { number_taken: 'Un autre client porte déjà ce numéro.' },
        tabs: { record: 'Fiche', defaults: 'Valeurs par défaut', contacts: 'Contacts' },
        contacts: { remove_message: '{{name}} sera retiré de ce client.' },
      },
      live: { changed_by: '{{name}} a modifié cette fiche pendant votre saisie.' },
    });
  }
}

const options: CustomerOptions = {
  countryCode: 'TN',
  identifiers: [
    {
      key: 'matricule_fiscal',
      label: 'Matricule fiscal',
      pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
      requiredForBusiness: true,
    },
  ],
  regimes: [{ code: 'standard', label: 'Régime normal', excludedFamilies: [] }],
  taxes: [{ id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat' }],
};
const carthage: CustomerRow = {
  id: 'k1',
  number: 'CLI-0001',
  kind: 'company',
  customerGroupId: null,
  taxRegime: 'standard',
  name: 'Carthage Conseil',
  legalName: null,
  identifiers: { matricule_fiscal: '1234567A/B/M/000' },
  email: null,
  phone: null,
  website: null,
  billingAddress: { line1: null, line2: null, postalCode: null, city: 'Tunis', countryCode: 'TN' },
  shippingAddress: null,
  defaultTaxComponentIds: ['t1'],
  defaultDiscountRate: null,
  notes: null,
  isActive: true,
  customFields: {},
};
const leila: ContactRow = {
  id: 'p1',
  firstName: 'Leila',
  lastName: 'Ben Salah',
  email: 'leila@carthage.tn',
  phone: null,
  role: 'Comptable',
  isPrimary: true,
};

describe('CustomerPage', () => {
  const error = signal<CustomersError | null>(null);
  const customer = signal<CustomerRow | null>(null);
  const customFields = signal<readonly CustomFieldDefinition[]>([]);
  const optionsSignal = signal<CustomerOptions | null>(options);
  const facade = {
    options: optionsSignal.asReadonly(),
    groups: signal<readonly CustomerGroupRow[]>([]).asReadonly(),
    customer: customer.asReadonly(),
    contacts: signal<readonly ContactRow[]>([leila]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadCustomer: vi.fn(),
    createCustomer: vi.fn(),
    reviseCustomer: vi.fn(),
    addContact: vi.fn(),
    reviseContact: vi.fn(),
    removeContact: vi.fn(),
    clearError: vi.fn(),
    customFields: customFields.asReadonly(),
  };
  const modules = new Set<string>(['customers', 'invoices']);
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme' },
      plannedModules: [
        { key: 'mailing', planned: 'v1' },
        { key: 'whatsapp', planned: 'v1' },
        { key: 'quotes', planned: 'v1' },
        { key: 'statements', planned: 'v1' },
        { key: 'purchases', planned: 'v1' },
      ],
    }),
    hasPermission: vi.fn(),
    hasModule: (module: string) => modules.has(module),
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
  let heard: { kinds: readonly string[]; handler: (changes: readonly LiveChange[]) => void }[] = [];
  const live = {
    on: (kinds: readonly string[], handler: (changes: readonly LiveChange[]) => void) =>
      heard.push({ kinds, handler }),
    reloadOn: vi.fn(),
  };
  /** Another person's save of this customer arrives: the API now holds `saved`. */
  async function savedElsewhere(saved: CustomerRow): Promise<void> {
    facade.loadCustomer.mockImplementationOnce(async () => customer.set(saved));
    for (const listener of heard.filter((entry) => entry.kinds.includes('customer'))) {
      listener.handler([
        {
          kind: 'customer',
          id: saved.id,
          action: 'customer.revised',
          actor: { id: 'u2', name: 'Nadia' },
        },
      ]);
    }
    await vi.waitFor(() => expect(facade.loadCustomer).toHaveBeenLastCalledWith('c1', saved.id));
    await settle();
  }
  let fixture: ComponentFixture<CustomerPage>;

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

  /** A long record is in tabs, so reaching a section means opening its tab, as a person does. */
  async function openTab(label: string): Promise<void> {
    const tab = Array.from(
      fixture.nativeElement.querySelectorAll('[role="tab"]') as NodeListOf<HTMLElement>,
    ).find((candidate) => (candidate.textContent ?? '').includes(label));
    tab!.click();
    await settle();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  async function open(customerId: string | undefined): Promise<void> {
    fixture = TestBed.createComponent(CustomerPage);
    if (customerId !== undefined) {
      fixture.componentRef.setInput('customerId', customerId);
    }
    await settle();
  }

  beforeEach(() => {
    heard = [];
    error.set(null);
    customer.set(null);
    customFields.set([]);
    optionsSignal.set(options);
    facade.loadCustomer.mockReset().mockResolvedValue(undefined);
    // As the real facade does: what it created becomes the record on screen. A stub that skipped this would
    // leave the page comparing its form against nothing, which is not what production does.
    facade.createCustomer.mockReset().mockImplementation(async () => {
      const created = { ...carthage, id: 'k9' };
      customer.set(created);
      return created;
    });
    facade.reviseCustomer.mockReset().mockResolvedValue(carthage);
    facade.addContact.mockReset().mockResolvedValue(true);
    facade.reviseContact.mockReset().mockResolvedValue(true);
    facade.removeContact.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    modules.clear();
    ['customers', 'invoices'].forEach((each) => modules.add(each));
    partySettings.load.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [CustomerPage],
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
        { provide: LiveChanges, useValue: live },
        { provide: PartySettings, useValue: partySettings },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a page names nothing it has not loaded.
  it('titles a customer still loading as nothing, never as a new one', async () => {
    await open('k1');

    const title = q('customer-title');
    expect(title?.textContent?.trim()).toBe('');
    expect(title?.getAttribute('aria-hidden')).toBe('true');
  });

  it('titles the page for a new customer as new', async () => {
    await open(undefined);

    expect(q('customer-title')?.textContent).toContain('customers.new_title');
    expect(q('customer-title')?.getAttribute('aria-hidden')).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-26 10:08 and 18:17 (row 150, slice 5).
  it('offers nothing planned while a customer is new', async () => {
    await open(undefined);
    expect(q('planned-actions')).toBeNull();
  });

  it('shows what a customer will offer once its planned modules ship', async () => {
    customer.set(carthage);
    await open('k1');
    const drawn = [...fixture.nativeElement.querySelectorAll('[data-testid^="planned-action-"]')];
    expect(drawn.map((each: Element) => each.getAttribute('data-testid'))).toEqual([
      'planned-action-quotes',
      'planned-action-statements',
    ]);
  });

  it('creates a customer, then opens it by its identifier', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadCustomer).toHaveBeenCalledWith('c1', null);
    expect(q('customer-contacts')).toBeNull();
    expect(q('party-defaults')).toBeNull();

    type('field-number', 'CLI-0009');
    type('field-name', 'Carthage Conseil');
    type('field-identifier__matricule_fiscal', '1234567A/B/M/000');
    q('record-save')!.click();
    await settle();

    expect(facade.createCustomer).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        number: 'CLI-0009',
        kind: 'company',
        taxRegime: 'standard',
        identifiers: { matricule_fiscal: '1234567A/B/M/000' },
        billingAddress: expect.objectContaining({ countryCode: 'TN' }),
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/customers', 'k9'], { replaceUrl: true }),
    );
    expect(successToasts()).toContain('customers.saved');
    // And going to the record it just created is not leaving unsaved work: the leave guard added in row 45
    // otherwise asks, and holds the navigation on /customers/new (CI e2e, 2026-09-20).
    await new Promise((resolve) => {
      TestBed.inject(UnsavedChanges).confirmLeave().subscribe(resolve);
    }).then((allowed) => expect(allowed).toBe(true));
  });

  // docs/SPEC.md § 7, 2026-09-26, row 139: what was just done offers its next step, to whoever may take it.
  it('offers to invoice a customer just created, at a new invoice naming them', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    type('field-number', 'CLI-0009');
    type('field-name', 'Carthage Conseil');
    type('field-identifier__matricule_fiscal', '1234567A/B/M/000');
    q('record-save')!.click();
    await settle();
    await vi.waitFor(() => expect(offeredNext()?.key).toBe('customers.suggest.invoice'));

    offeredNext()!.run();
    expect(navigate).toHaveBeenLastCalledWith(['/invoices/new'], {
      queryParams: { billTo: 'k9' },
    });
  });

  it('offers no invoice when the invoices module is off or invoicing is not allowed', async () => {
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    modules.delete('invoices');
    await open(undefined);
    type('field-number', 'CLI-0009');
    type('field-name', 'Carthage Conseil');
    type('field-identifier__matricule_fiscal', '1234567A/B/M/000');
    q('record-save')!.click();
    await settle();
    await vi.waitFor(() => expect(successToasts()).toContain('customers.saved'));
    expect(offeredNext()).toBeNull();

    modules.add('invoices');
    auth.hasPermission.mockImplementation((permission: string) => permission !== 'invoice.write');
    await open(undefined);
    type('field-number', 'CLI-0010');
    type('field-name', 'Tunis Conseil');
    type('field-identifier__matricule_fiscal', '1234567A/B/M/000');
    q('record-save')!.click();
    await settle();
    await vi.waitFor(() => expect(successToasts()).toHaveLength(2));
    expect(offeredNext()).toBeNull();
  });

  it("sends what the company's custom fields were filled in with", async () => {
    customFields.set([
      {
        id: 'f1',
        entity: 'customer',
        key: 'sector',
        label: 'Secteur',
        type: 'text',
        required: false,
        choices: [],
        sortOrder: 0,
        isActive: true,
      },
    ]);
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);

    type('field-number', 'CLI-0009');
    type('field-name', 'Carthage Conseil');
    type('field-identifier__matricule_fiscal', '1234567A/B/M/000');
    type('field-custom__sector', ' Gros ');
    q('record-save')!.click();
    await settle();

    expect(facade.createCustomer).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ customFields: { sector: 'Gros' } }),
    );
  });

  it('does not send a registration number of the wrong shape', async () => {
    await open(undefined);
    type('field-number', 'CLI-0009');
    type('field-name', 'Carthage Conseil');
    type('field-identifier__matricule_fiscal', '1234567');
    q('record-save')!.click();
    await settle();

    expect(facade.createCustomer).not.toHaveBeenCalled();
  });

  it('shows what another person saved on a quiet form, and says who changed it', async () => {
    customer.set(carthage);
    await open('k1');

    await savedElsewhere({ ...carthage, name: 'Carthage Conseil SARL' });

    expect((q('field-name') as HTMLInputElement).value).toBe('Carthage Conseil SARL');
    expect(q('field-wrapper-name')?.classList).toContain('twes-field-updated');
    expect(q('record-changed')).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'notice',
      key: 'live.notice',
      params: { name: 'Nadia' },
    });
  });

  it('while typing, keeps the typed field, updates the others, and offers the saved version where both changed', async () => {
    customer.set(carthage);
    await open('k1');
    type('field-name', 'Carthage & associés');
    type('field-email', 'contact@carthage.tn');

    await savedElsewhere({ ...carthage, name: 'Carthage Conseil SARL', phone: '71 000 000' });

    expect((q('field-name') as HTMLInputElement).value).toBe('Carthage & associés');
    expect((q('field-phone') as HTMLInputElement).value).toBe('71 000 000');
    expect(q('record-changed')?.textContent).toContain('Nadia');
    expect(q('field-conflict-name')).not.toBeNull();
    expect(q('field-conflict-email')).toBeNull();

    q('field-take-theirs-name')!.click();
    await settle();
    expect((q('field-name') as HTMLInputElement).value).toBe('Carthage Conseil SARL');
    expect(q('field-conflict-name')).toBeNull();

    q('record-reload')!.click();
    await settle();
    expect((q('field-email') as HTMLInputElement).value).toBe('');
    expect(q('record-changed')).toBeNull();
  });

  it('splits a long record into tabs, each holding its own section and its own save', async () => {
    // Design review finding 4: this page measured 3165 px with two saves below the first screen.
    customer.set(carthage);
    await open('k1');

    expect(q('customer-tabs')).not.toBeNull();
    expect(q('customer-tab-record')).not.toBeNull();
    expect(q('customer-form')).not.toBeNull();
    // A tab nobody has opened holds nothing yet, which is the point of splitting the page.
    expect(q('customer-contacts')).toBeNull();

    await openTab('Contacts');
    expect(q('customer-contacts')).not.toBeNull();

    // The defaults are their own panel with its own save; the bar beside the title saves the record.
    await openTab('Valeurs par défaut');
    expect(q('customer-tab-defaults')).not.toBeNull();
    expect(q('party-defaults')).not.toBeNull();
  });

  it('saves from the bar beside the title, which is inert until something changed', async () => {
    // Design review finding 4, measured: every long form's save sat below the first screen, this page at 3165 px
    // with two of them.
    customer.set(carthage);
    await open('k1');

    expect((q('record-save') as HTMLButtonElement).disabled).toBe(true);
    expect(q('record-changes')).toBeNull();
    expect(q('record-revert')).toBeNull();

    type('field-email', 'compta@carthage.tn');
    await settle();
    expect((q('record-save') as HTMLButtonElement).disabled).toBe(false);
    expect(q('record-changes')?.getAttribute('data-count')).toBe('1');

    // Typing it back to what was saved is not a change, whatever Angular's own `dirty` says.
    type('field-email', carthage.email ?? '');
    await settle();
    expect(q('record-changes')).toBeNull();
    expect((q('record-save') as HTMLButtonElement).disabled).toBe(true);
  });

  it('offers the same save to the keyboard, the palette and the "?" sheet, and nothing to a reader', async () => {
    // Row 106: this page declared nothing, so "s" saved nothing here, Ctrl K showed no "Sur cette page" group and
    // "?" said the page offered none — while the bar beside the title had a save button all along.
    customer.set(carthage);
    await open('k1');
    const screen = TestBed.inject(ScreenActions);

    const save = screen.forKey('s');
    expect(save?.id).toBe('save');
    expect(save?.disabled).toBe(true);
    expect(screen.actions().map((action) => action.id)).toEqual(['save']);

    type('field-email', 'compta@carthage.tn');
    await settle();
    expect(screen.forKey('s')?.disabled).toBe(false);
    expect(screen.actions().map((action) => action.id)).toEqual(['save', 'revert']);

    // The one declaration, so the keystroke saves exactly what the button saves.
    screen.forKey('s')?.run?.();
    await settle();
    expect(facade.reviseCustomer).toHaveBeenCalled();
  });

  it('offers a reader no save at all, by the same declaration the bar reads', async () => {
    auth.hasPermission.mockReturnValue(false);
    customer.set(carthage);
    await open('k1');

    expect(TestBed.inject(ScreenActions).forKey('s')).toBeUndefined();
    expect(q('record-save')).toBeNull();
  });

  it('puts every field back to what was saved, and nothing is left to save', async () => {
    customer.set(carthage);
    await open('k1');

    type('field-email', 'compta@carthage.tn');
    type('field-name', 'Carthage SA');
    await settle();
    expect(q('record-changes')?.getAttribute('data-count')).toBe('2');

    q('record-revert')!.click();
    await settle();

    expect((q('field-email') as HTMLInputElement).value).toBe(carthage.email ?? '');
    expect((q('field-name') as HTMLInputElement).value).toBe(carthage.name);
    expect(q('record-changes')).toBeNull();
    expect(facade.reviseCustomer).not.toHaveBeenCalled();
  });

  it('revises an existing customer and lists its contacts', async () => {
    customer.set(carthage);
    await open('k1');

    expect(facade.loadCustomer).toHaveBeenCalledWith('c1', 'k1');
    expect(partySettings.load).toHaveBeenCalledWith('c1', { customerId: 'k1' });
    expect((q('field-number') as HTMLInputElement).value).toBe('CLI-0001');
    await openTab('Contacts');
    expect(q('contact-leila@carthage.tn')?.textContent).toContain('Leila Ben Salah');

    type('field-email', 'compta@carthage.tn');
    // The save comes alive only once something changed, so the change has to be seen before it is clicked.
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.reviseCustomer).toHaveBeenCalledWith(
      'c1',
      'k1',
      expect.objectContaining({ email: 'compta@carthage.tn', defaultTaxComponentIds: ['t1'] }),
    );
    expect(successToasts()).toContain('customers.saved');
  });

  it('adds a contact to the customer and removes one', async () => {
    customer.set(carthage);
    await open('k1');

    await openTab('Contacts');
    q('contact-add')!.click();
    await settle();
    type('field-firstName', 'Karim');
    q('contact-save')!.click();
    await settle();
    await vi.waitFor(() =>
      expect(facade.addContact).toHaveBeenCalledWith(
        'c1',
        'k1',
        expect.objectContaining({ firstName: 'Karim', isPrimary: false }),
      ),
    );

    // Removing a contact is destructive, so it sits behind "⋮" rather than under the pointer.
    q('row-more-p1')!.click();
    await settle();
    inMenu('row-menu-remove-p1')!.click();
    await settle();
    // Destructive, so it asks first, and the question names the row rather than asking about "it".
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Leila Ben Salah',
    );
    (document.querySelector('[data-testid="confirm-run"]') as HTMLElement).click();
    await settle();
    await vi.waitFor(() => expect(facade.removeContact).toHaveBeenCalledWith('c1', 'k1', 'p1'));
  });

  it('keeps what was typed when the customer and its options are read again', async () => {
    customer.set(carthage);
    await open('k1');
    type('field-email', 'compta@carthage.tn');

    // What reading a customer again gives: the same customer and options, as new objects.
    customer.set({ ...carthage });
    optionsSignal.set({ ...options, regimes: [...options.regimes], taxes: [...options.taxes] });
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.reviseCustomer).toHaveBeenCalledWith(
      'c1',
      'k1',
      expect.objectContaining({ email: 'compta@carthage.tn' }),
    );
  });

  it('shows another customer when another one is opened', async () => {
    customer.set(carthage);
    await open('k1');
    type('field-email', 'compta@carthage.tn');

    customer.set({ ...carthage, id: 'k2', number: 'CLI-0002', email: 'contact@hannibal.tn' });
    fixture.componentRef.setInput('customerId', 'k2');
    await settle();

    expect((q('field-email') as HTMLInputElement).value).toBe('contact@hannibal.tn');
  });

  it('says why the API refused', async () => {
    error.set('number_taken');
    await open(undefined);

    expect(q('customer-error')?.textContent).toContain('porte déjà ce numéro');
  });

  it('shows a reader the customer without a way to change it', async () => {
    auth.hasPermission.mockReturnValue(false);
    customer.set(carthage);
    await open('k1');

    expect(q('record-save')).toBeNull();
    expect((q('field-number') as HTMLInputElement).disabled).toBe(true);
    await openTab('Contacts');
    expect(q('contact-add')).toBeNull();
    expect(q('row-more-p1')).toBeNull();
  });
});
