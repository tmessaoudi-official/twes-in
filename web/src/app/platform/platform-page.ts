// SPDX-License-Identifier: AGPL-3.0-or-later

import { toSignal } from '@angular/core/rxjs-interop';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatTabsModule } from '@angular/material/tabs';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { map } from 'rxjs';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListQuery } from '../shared/list/list-types';
import { CountBadge } from '../shared/ui/count-badge';
import { StatusBadge } from '../shared/ui/status-badge';
import type { StatusTone } from '../shared/theme/accent-theme';
import { PlatformFacade } from './platform-facade';
import { ACCOUNTS_LIST, accountSearch, COMPANIES_LIST, companySearch } from './platform-forms';
import {
  COMPANY_COUNTRIES,
  PLATFORM_TABS,
  type AccountAction,
  type CompanyCountry,
  type PlatformCompanyRow,
  type PlatformTab,
  type SignupSwitch,
  type SubscriptionTerms,
} from './platform-types';

/** What a list keeps in the address besides the tab: switching tabs must not carry one list's words into the next. */
const LIST_PARAMS = ['q', 'status', 'country', 'state', 'sort', 'page', 'size', 'company'] as const;
const COMPANY_TONES: Readonly<Record<string, StatusTone>> = {
  pending: 'warning',
  active: 'success',
  suspended: 'neutral',
};

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
    MatTabsModule,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    StatusBadge,
    CountBadge,
  ],
  templateUrl: './platform-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlatformPage implements OnInit {
  private readonly platform = inject(PlatformFacade);
  private readonly payments = inject(SubscriptionFacade);
  private readonly feedback = inject(Feedback);
  private readonly route = inject(ActivatedRoute);

  protected readonly tabs = PLATFORM_TABS;
  /** The tab the address names, the overview where it names none. */
  protected readonly tab = toSignal(
    this.route.queryParamMap.pipe(
      map(
        (params): PlatformTab =>
          PLATFORM_TABS.find((known) => known === params.get('tab')) ?? 'overview',
      ),
    ),
    { initialValue: 'overview' as PlatformTab },
  );
  /** The company whose sheet is open, named by the address. */
  private readonly openedId = toSignal(
    this.route.queryParamMap.pipe(map((params) => params.get('company'))),
    { initialValue: null },
  );
  /**
   * The row of that company: found in the page shown, and kept when a search moves the page on while the sheet is
   * open, so reading a sheet does not lose it to the list behind.
   */
  protected readonly opened = linkedSignal<
    { id: string | null; rows: readonly PlatformCompanyRow[] },
    PlatformCompanyRow | null
  >({
    source: () => ({ id: this.openedId(), rows: this.platform.companies() }),
    computation: (source, previous) =>
      source.id === null
        ? null
        : (source.rows.find((row) => row.id === source.id) ??
          (previous?.value?.id === source.id ? previous.value : null)),
  });
  protected readonly companiesList = COMPANIES_LIST;
  protected readonly accountsList = ACCOUNTS_LIST;
  protected readonly companiesTotal = this.platform.companiesTotal;
  protected readonly accountsTotal = this.platform.accountsTotal;
  protected readonly companyCount = this.platform.companyCount;
  protected readonly accountCount = this.platform.accountCount;
  protected readonly waitingTotal = this.platform.waitingTotal;
  /** Whether the form that opens a company is shown. */
  protected readonly creating = signal(false);
  protected readonly companyRowId = (row: PlatformCompanyRow): string => `company-${row.name}`;
  protected readonly accountRowId = (row: { email: string }): string => `account-${row.email}`;

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

  /** The address a tab opens: its own name and none of the words another list left behind. */
  protected tabParams(tab: PlatformTab): Record<string, string | null> {
    return {
      tab: tab === 'overview' ? null : tab,
      ...Object.fromEntries(LIST_PARAMS.map((key) => [key, null])),
    };
  }

  protected tone(status: string): StatusTone {
    return COMPANY_TONES[status] ?? 'neutral';
  }

  protected onCompaniesQuery(query: ListQuery): void {
    void this.platform.loadCompanies(companySearch(query));
  }

  protected onAccountsQuery(query: ListQuery): void {
    void this.platform.loadAccounts(accountSearch(query));
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
      this.creating.set(false);
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
