// SPDX-License-Identifier: AGPL-3.0-or-later

import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { SubscriptionFacade } from './subscription-facade';
import { SubscriptionPage } from './subscription-page';
import type { PaymentRow, SubscriptionError, SubscriptionView } from './subscription-types';
import type { FieldRefusal } from '../shared/form/field-refusals';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      licensing: {
        title: 'Abonnement',
        unmanaged: 'Aucun abonnement à régler.',
        covered_until: 'Couvert jusqu’au',
        paid_through: 'Payé jusqu’au',
        price: 'Prix',
        days_left: '{{days}} jour(s) restant(s)',
        stages: { unpaid: 'Impayé', held: 'Règlement en attente', paid: 'À jour' },
        periods: { month: '{{count}} mois' },
        errors: { already_declared: 'Un règlement est déjà en attente.' },
        payment: {
          declare: 'Déclarer un règlement',
          declared: 'Règlement déclaré.',
          waiting: 'Règlement de {{amount}} {{currency}} en attente.',
          history: 'Règlements déclarés',
          none: 'Aucun règlement déclaré.',
          section: 'Règlement',
          submit: 'Déclarer',
          cancel: 'Annuler',
          methods: { cash: 'Espèces' },
          statuses: { declared: 'En attente', confirmed: 'Confirmé' },
          fields: {
            amount: 'Montant',
            method: 'Moyen',
            paidOn: 'Date',
            reference: 'Référence',
            note: 'Précision',
          },
        },
      },
      form: { optional: 'facultatif' },
      import: {
        rejections: { invalid_amount: 'Attendu : un montant d’au plus {{decimals}} décimales.' },
      },
    });
  }
}

const confirmed: PaymentRow = {
  id: 'p0',
  amount: '600.000',
  currency: 'TND',
  method: 'cash',
  paidOn: '2026-08-16',
  reference: null,
  note: null,
  status: 'confirmed',
  declaredAt: '2026-08-16T10:00:00+00:00',
  decidedAt: '2026-08-17T10:00:00+00:00',
  decisionNote: null,
};

const unpaid: SubscriptionView = {
  companyId: 'c1',
  stage: 'unpaid',
  access: 'read_only',
  coveredUntil: '2026-08-18T23:59:59+01:00',
  graceEndsAt: '2026-08-25T23:59:59+01:00',
  daysLeft: null,
  trialEndsOn: null,
  paidThrough: '2026-08-18',
  periodCount: 1,
  periodUnit: 'month',
  price: '600.000',
  currency: 'TND',
  openPayment: null,
  payments: [confirmed],
  canDeclare: true,
};

describe('SubscriptionPage', () => {
  const subscription = signal<SubscriptionView | null>(unpaid);
  const managed = signal(true);
  const error = signal<SubscriptionError | null>(null);
  const readFailed = signal(false);
  const refusals = signal<readonly FieldRefusal[]>([]);
  const facade = {
    subscription: subscription.asReadonly(),
    managed: managed.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    readFailed: readFailed.asReadonly(),
    refusals: refusals.asReadonly(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    declare: vi.fn(),
  };
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme', currency: 'TND', timezone: 'Africa/Tunis' },
    }),
  };
  const live = { reloadOn: vi.fn() };
  let fixture: ComponentFixture<SubscriptionPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(SubscriptionPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function fill(field: string, value: string): void {
    const input = q(`field-${field}`) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  beforeEach(async () => {
    readFailed.set(false);
    subscription.set(unpaid);
    managed.set(true);
    error.set(null);
    refusals.set([]);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.declare.mockReset().mockResolvedValue(true);
    live.reloadOn.mockReset();
    await TestBed.configureTestingModule({
      imports: [SubscriptionPage],
      providers: [
        ...provideQuietFeedback(),
        { provide: SubscriptionFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: LiveChanges, useValue: live },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  it('shows where the company stands and what it declared before', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1');
    expect(q('subscription-stage')?.textContent).toContain('Impayé');
    expect(q('subscription-paid-through')).not.toBeNull();
    expect(q('subscription-payments')?.textContent).toContain('Confirmé');
  });

  // docs/SPEC.md § 7, 2026-09-25 11:54 (hardcoded walkthrough item 16): the page wrote its dates with Angular's own
  // 'mediumDate' and its amounts as the API sent them, where every other screen writes both as the locale does.
  it('writes its days and amounts as every other screen does', async () => {
    await open();

    expect(q('subscription-covered-until')?.textContent?.trim()).toBe('18/08/2026');
    expect(q('subscription-paid-through')?.textContent?.trim()).toBe('18/08/2026');
    expect(q('subscription-price')?.textContent).toContain('600,000 TND');
    expect(q('subscription-payments')?.textContent).toContain('600,000 TND');
    expect(q('subscription-payments')?.textContent).toContain('16/08/2026');
  });

  it('declares a payment in the subscription’s own currency, never one that was typed', async () => {
    await open();

    q('subscription-declare')!.click();
    fixture.detectChanges();
    fill('amount', '600.000');
    fill('paidOn', '2026-09-16');
    // A payment typed and not declared holds the page until it is.
    expect(TestBed.inject(UnsavedChanges).count()).toBe(2);
    q('payment-submit')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(TestBed.inject(UnsavedChanges).count()).toBe(0);

    expect(facade.declare).toHaveBeenCalledWith('c1', {
      amount: '600.000',
      currency: 'TND',
      method: 'cash',
      paidOn: '2026-09-16',
      reference: null,
      note: null,
    });
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      { kind: 'success', key: 'licensing.payment.declared', params: undefined },
    ]);
  });

  // A declaration the API refused on a field said only « ne peut pas être enregistré tel quel » above the form.
  it('says a refused field under it and keeps the form open with what was typed', async () => {
    facade.declare.mockImplementation(async () => {
      error.set('invalid');
      refusals.set([{ field: 'amount', reason: 'invalid_amount', params: { decimals: 3 } }]);
      return false;
    });
    await open();

    q('subscription-declare')!.click();
    fixture.detectChanges();
    fill('amount', '600.000');
    q('payment-submit')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(q('field-error-amount')?.textContent).toContain('au plus 3 décimales');
    expect((q('field-amount') as HTMLInputElement).value).toBe('600.000');
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);
  });

  it('offers no form while a payment waits, and says one does', async () => {
    subscription.set({
      ...unpaid,
      stage: 'held',
      access: 'full',
      openPayment: { ...confirmed, id: 'p1', status: 'declared' },
      canDeclare: false,
    });

    await open();

    expect(q('subscription-declare')).toBeNull();
    expect(q('subscription-waiting')?.textContent).toContain('600.000');
  });

  it('says a company licensing does not manage owes nothing', async () => {
    managed.set(false);
    subscription.set(null);

    await open();

    expect(q('subscription-unmanaged')?.textContent).toContain('Aucun abonnement à régler.');
    expect(q('subscription-standing')).toBeNull();
  });

  it('names a refusal', async () => {
    error.set('already_declared');

    await open();

    expect(q('subscription-error')?.textContent).toContain('déjà en attente');
  });

  it('offers « Réessayer » only after a read failed, never under a refused save, and reads again as on opening', async () => {
    await open();
    error.set('network');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(q('subscription-error')).not.toBeNull();
    expect(q('subscription-retry')).toBeNull();

    readFailed.set(true);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const before = facade.load.mock.calls.length;
    q('subscription-retry')!.click();

    expect(facade.load).toHaveBeenCalledTimes(before + 1);
  });
});
