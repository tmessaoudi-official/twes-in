// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslatePipe } from '@ngx-translate/core';
import { PlatformFacade } from './platform-facade';
import {
  COMPANY_COUNTRIES,
  type AccountAction,
  type CompanyCountry,
  type SignupSwitch,
  type SubscriptionTerms,
} from './platform-types';

const PERIOD_UNITS: readonly SubscriptionTerms['periodUnit'][] = ['day', 'month', 'year'];
const UNPAID_MODES: readonly NonNullable<SubscriptionTerms['unpaidMode']>[] = [
  'read_only',
  'locked',
];
const EMPTY_TERMS: SubscriptionTerms = {
  periodCount: 1,
  periodUnit: 'month',
  trialEndsOn: null,
  paidThrough: null,
  price: null,
  currency: null,
  graceDays: null,
  unpaidMode: null,
  holdDays: null,
};
import { Feedback } from '../shared/feedback/feedback';
import { SubscriptionFacade } from '../licensing/subscription-facade';

/**
 * The operators' page: whether anyone may sign up, whether a company that signs up waits for approval, the
 * companies waiting for it, every company, which an operator opens and invites owners into, and accounts, whose
 * sessions an operator ends and which they deactivate or reactivate. Reached from the home page by operators only
 * (operatorGuard).
 */
@Component({
  selector: 'app-platform-page',
  imports: [
    MatCardModule,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    MatSlideToggleModule,
    TranslatePipe,
  ],
  templateUrl: './platform-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlatformPage implements OnInit {
  private readonly platform = inject(PlatformFacade);
  private readonly payments = inject(SubscriptionFacade);
  private readonly feedback = inject(Feedback);

  protected readonly waitingPayments = this.payments.waiting;
  /** « Me prévenir » (row 150): the planned modules at least one company waits for, the most asked for first. */
  protected readonly awaited = computed(() =>
    this.platform.demand().filter((row) => row.companies > 0),
  );
  /** How many periods each waiting payment is taken to cover, by declaration; one unless the operator says otherwise. */
  protected readonly periodsFor = signal<Readonly<Record<string, number>>>({});

  protected readonly waiting = this.platform.waiting;
  protected readonly signup = this.platform.signup;
  protected readonly busy = this.platform.busy;
  protected readonly error = this.platform.error;
  protected readonly accounts = this.platform.accounts;
  protected readonly search = signal('');
  protected readonly companies = this.platform.companies;
  protected readonly countries = Object.keys(COMPANY_COUNTRIES) as CompanyCountry[];
  protected readonly companyName = signal('');
  protected readonly companyCountry = signal<CompanyCountry>('TN');
  protected readonly ownerEmail = signal('');
  /** The address the last invitation went to, once it went. */
  /** What is typed in each company's owner field, by company. */
  protected readonly ownerEmails = signal<Readonly<Record<string, string>>>({});
  protected readonly subscription = this.platform.subscription;
  protected readonly openedSubscription = this.platform.openedSubscription;
  protected readonly periodUnits = PERIOD_UNITS;
  protected readonly unpaidModes = UNPAID_MODES;
  /** The terms in the open panel, which start from what the company holds, or empty where it holds none. */
  protected readonly terms = signal<SubscriptionTerms>({ ...EMPTY_TERMS });

  async ngOnInit(): Promise<void> {
    await this.platform.load();
    await this.payments.loadWaiting();
  }

  protected typedPeriods(declarationId: string, event: Event): void {
    const periods = Number.parseInt(this.typed(event), 10);
    this.periodsFor.update((held) => ({ ...held, [declarationId]: periods > 0 ? periods : 1 }));
  }

  /** Confirming carries the company's covered time forward by that many periods. */
  protected async confirmPayment(declarationId: string): Promise<void> {
    const periods = this.periodsFor()[declarationId] ?? 1;
    if (await this.payments.confirm(declarationId, periods, null)) {
      this.feedback.success('platform.payments.confirmed');
      await this.platform.load();
    }
  }

  /** Rejecting ends at once the hold the declaration kept on the company. */
  protected async rejectPayment(declarationId: string): Promise<void> {
    if (await this.payments.reject(declarationId, null)) {
      this.feedback.success('platform.payments.rejected');
      await this.platform.load();
    }
  }

  protected async turn(key: SignupSwitch, value: boolean): Promise<void> {
    await this.platform.setSignup(key, value);
  }

  protected async find(): Promise<void> {
    await this.platform.findAccounts(this.search().trim());
  }

  protected async act(userId: string, action: AccountAction): Promise<void> {
    await this.platform.actOnAccount(userId, action);
  }

  protected async open(): Promise<void> {
    const email = this.ownerEmail().trim();
    if (await this.platform.openCompany(this.companyName().trim(), this.companyCountry(), email)) {
      this.feedback.success('platform.companies.invited', { email });
      this.companyName.set('');
      this.ownerEmail.set('');
    }
  }

  protected async invite(companyId: string): Promise<void> {
    const email = (this.ownerEmails()[companyId] ?? '').trim();
    if (await this.platform.inviteOwner(companyId, email)) {
      this.feedback.success('platform.companies.invited', { email });
      this.ownerEmails.update((typed) => ({ ...typed, [companyId]: '' }));
    }
  }

  protected typedOwner(companyId: string, event: Event): void {
    const value = this.typed(event);
    this.ownerEmails.update((typed) => ({ ...typed, [companyId]: value }));
  }

  protected chosen(event: Event): CompanyCountry {
    const value = (event.target as HTMLSelectElement).value;
    return this.countries.find((code) => code === value) ?? 'TN';
  }

  protected typed(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected async toggleSubscription(companyId: string): Promise<void> {
    if (this.openedSubscription() === companyId) {
      this.platform.closeSubscription();

      return;
    }
    await this.platform.openSubscription(companyId);
    const held = this.subscription();
    this.terms.set(
      held === null
        ? { ...EMPTY_TERMS }
        : {
            periodCount: held.periodCount,
            periodUnit: held.periodUnit,
            trialEndsOn: held.trialEndsOn,
            paidThrough: held.paidThrough,
            price: held.price,
            currency: held.currency,
            graceDays: held.graceDays,
            unpaidMode: held.unpaidMode,
            holdDays: held.holdDays,
          },
    );
  }

  /** One field of the terms being edited; an emptied field is null, which is what "follow the platform" means. */
  protected type(field: keyof SubscriptionTerms, event: Event): void {
    const value = (event.target as HTMLInputElement | HTMLSelectElement).value;
    this.terms.update((terms) => {
      switch (field) {
        case 'periodCount':
          return { ...terms, periodCount: Number.parseInt(value, 10) || 1 };
        case 'periodUnit':
          return { ...terms, periodUnit: PERIOD_UNITS.find((unit) => unit === value) ?? 'month' };
        case 'graceDays':
          return { ...terms, graceDays: '' === value ? null : Number.parseInt(value, 10) };
        case 'holdDays':
          return { ...terms, holdDays: '' === value ? null : Number.parseInt(value, 10) };
        case 'unpaidMode':
          return { ...terms, unpaidMode: UNPAID_MODES.find((mode) => mode === value) ?? null };
        default:
          return { ...terms, [field]: '' === value.trim() ? null : value.trim() };
      }
    });
  }

  protected async saveSubscription(companyId: string): Promise<void> {
    if (await this.platform.saveSubscription(companyId, this.terms())) {
      this.feedback.success('platform.subscription.saved');
    }
  }

  protected async stopSubscription(companyId: string): Promise<void> {
    if (await this.platform.stopSubscription(companyId)) {
      this.feedback.success('platform.subscription.stopped');
      this.terms.set({ ...EMPTY_TERMS });
    }
  }

  protected async approve(companyId: string): Promise<void> {
    await this.platform.approve(companyId);
  }

  protected async reject(companyId: string): Promise<void> {
    await this.platform.reject(companyId);
  }
}
