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
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatTabsModule } from '@angular/material/tabs';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { map } from 'rxjs';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import { mergeSavedVersion, type FieldConflict } from '../shared/form/form-merge';
import type { FormValues } from '../shared/form/form-types';
import { Select, type SelectOption } from '../shared/form/select';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListQuery } from '../shared/list/list-types';
import { CountBadge } from '../shared/ui/count-badge';
import { SelectedTabInView } from '../shared/ui/selected-tab-in-view';
import { StatusBadge } from '../shared/ui/status-badge';
import type { StatusTone } from '../shared/theme/accent-theme';
import { membershipRoleLabel } from './membership-role';
import { PlatformFacade } from './platform-facade';
import { ACCOUNTS_LIST, accountSearch, COMPANIES_LIST, companySearch } from './platform-forms';
import {
  COMPANY_COUNTRIES,
  PLATFORM_TABS,
  type AccountAction,
  type CompanyCountry,
  type PlatformCompanyRow,
  type PlatformTab,
  type PlatformSwitch,
  type SupportChannel,
  type PlatformNumber,
  type PlatformSupport,
  type PlatformSubscriptionRow,
} from './platform-types';
import { subscriptionForm, subscriptionTerms, subscriptionValues } from './subscription-form';

/** What a list keeps in the address besides the tab: switching tabs must not carry one list's words into the next. */
const LIST_PARAMS = ['q', 'status', 'country', 'state', 'sort', 'page', 'size', 'company'] as const;
const COMPANY_TONES: Readonly<Record<string, StatusTone>> = {
  pending: 'warning',
  active: 'success',
  suspended: 'neutral',
};

/** The kinds of message the screens name; another is shown by its class name, as the worker's own commands show it. */
const FAILED_KINDS: Readonly<Record<string, string>> = {
  InvitationToMail: 'invitation',
  PasswordResetAsked: 'password_reset',
  SignupAsked: 'signup',
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
    FormsModule,
    MatFormFieldModule,
    MatInputModule,
    MatSlideToggleModule,
    MatTabsModule,
    SelectedTabInView,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    StatusBadge,
    CountBadge,
    Select,
    DescriptorForm,
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
  protected readonly roleLabel = membershipRoleLabel;
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
  protected readonly failed = this.platform.failed;
  /** The translation key naming a kind of failed message, null for one the screens do not know yet. */
  protected readonly failedKind = (kind: string): string | null =>
    kind in FAILED_KINDS ? `platform.failed.kinds.${FAILED_KINDS[kind]}` : null;
  protected readonly signup = this.platform.signup;

  protected readonly supportChannels: readonly SupportChannel[] = [
    'phone',
    'whatsapp',
    'email',
    'status_url',
  ];
  /** What each channel's field takes, so a phone keyboard shows the right keys. */
  protected readonly supportInput: Readonly<Record<SupportChannel, string>> = {
    phone: 'tel',
    whatsapp: 'tel',
    email: 'email',
    status_url: 'url',
  };
  /** The support channels as typed, until saved: what the platform holds again after every load or save. */
  protected readonly supportDraft = linkedSignal<PlatformSupport | null>(() =>
    this.platform.support(),
  );
  private readonly supportChanges = computed<Partial<PlatformSupport>>(() => {
    const saved = this.platform.support();
    const draft = this.supportDraft();
    if (saved === null || draft === null) return {};
    return Object.fromEntries(
      this.supportChannels
        .filter((channel) => draft[channel] !== saved[channel])
        .map((channel) => [channel, draft[channel]]),
    );
  });
  protected readonly supportChanged = computed(() => Object.keys(this.supportChanges()).length > 0);
  protected readonly delays = this.platform.delays;
  /** The delays as typed, by key, until saved; a field never typed in shows what the platform holds. */
  protected readonly delayDraft = linkedSignal<
    readonly PlatformNumber[] | null,
    Record<string, number>
  >({
    source: () => this.platform.delays(),
    computation: () => ({}),
  });
  /** What differs from the platform, or null while a typed value is not a whole number within its bounds. */
  private readonly delayChanges = computed<Record<string, number> | null>(() => {
    const changes: Record<string, number> = {};
    for (const delay of this.platform.delays() ?? []) {
      const typed = this.delayDraft()[delay.key];
      if (typed === undefined || typed === delay.value) continue;
      const fits =
        Number.isInteger(typed) &&
        (delay.min === null || typed >= delay.min) &&
        (delay.max === null || typed <= delay.max);
      if (!fits) return null;
      changes[delay.key] = typed;
    }
    return changes;
  });
  protected readonly delaysReady = computed(() => {
    const changes = this.delayChanges();
    return changes !== null && Object.keys(changes).length > 0;
  });
  protected readonly demo = this.platform.demo;
  private readonly demoMostHeld = computed(() => this.platform.demo()?.most?.value ?? null);
  /** The count as typed until saved, null when untouched: a switch turned meanwhile leaves what is typed alone. */
  protected readonly demoMostDraft = linkedSignal<number | null, number | null>({
    source: this.demoMostHeld,
    computation: () => null,
  });
  /** A typed count differing from the platform's, whole and within its bounds. */
  protected readonly demoMostReady = computed(() => {
    const most = this.platform.demo()?.most;
    const typed = this.demoMostDraft();
    return (
      most !== null &&
      most !== undefined &&
      typed !== null &&
      typed !== most.value &&
      Number.isInteger(typed) &&
      (most.min === null || typed >= most.min) &&
      (most.max === null || typed <= most.max)
    );
  });
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
  protected readonly countryOptions: SelectOption[] = this.countries.map((code) => ({
    value: code,
    label: `platform.companies.countries.${code}`,
    testId: `platform-company-country-option-${code}`,
  }));
  protected readonly subscriptionDescriptor = subscriptionForm();
  /** The terms in the open panel, which start from what the company holds, or a month where it holds none. */
  protected readonly subscriptionGroup = signal<DescriptorFormGroup>(
    buildFormGroup(this.subscriptionDescriptor, subscriptionValues(null)),
  );
  /** The terms as the panel last read them, against which what was typed is told apart from what was not. */
  private readonly readTerms = signal<FormValues>(subscriptionValues(null));
  /** The terms both this operator and another changed since the panel read them, each offered both ways. */
  protected readonly subscriptionConflicts = signal<readonly FieldConflict[]>([]);

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

  protected async turn(key: PlatformSwitch, value: boolean): Promise<void> {
    await this.platform.setSwitch(key, value);
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

  protected chosen(value: string): CompanyCountry {
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
    this.showTerms(this.subscription());
  }

  /** The panel shows the terms held, what the API kept rather than what was typed, with nothing left to settle. */
  private showTerms(held: PlatformSubscriptionRow | null): void {
    const values = subscriptionValues(held);
    this.readTerms.set(values);
    this.subscriptionGroup.set(buildFormGroup(this.subscriptionDescriptor, values));
    this.subscriptionConflicts.set([]);
  }

  protected typedSupport(channel: SupportChannel, event: Event): void {
    const value = (event.target as HTMLInputElement).value.trim();
    this.supportDraft.update((draft) => (draft === null ? draft : { ...draft, [channel]: value }));
  }

  protected typedDelay(key: string, event: Event): void {
    const raw = (event.target as HTMLInputElement).value.trim();
    this.delayDraft.update((draft) => ({ ...draft, [key]: raw === '' ? Number.NaN : Number(raw) }));
  }

  protected typedDemoMost(event: Event): void {
    const raw = (event.target as HTMLInputElement).value.trim();
    this.demoMostDraft.set(raw === '' ? Number.NaN : Number(raw));
  }

  protected async saveDemoMost(): Promise<void> {
    const typed = this.demoMostDraft();
    if (!this.demoMostReady() || typed === null) return;
    if (await this.platform.saveDemoMost(typed)) {
      this.feedback.success('platform.demo.saved');
    } else {
      this.savedSince();
    }
  }

  protected async saveDelays(): Promise<void> {
    const changes = this.delayChanges();
    if (changes === null) return;
    if (await this.platform.saveDelays(changes)) {
      this.feedback.success('platform.delays.saved');
    } else {
      this.savedSince();
    }
  }

  protected async saveSupport(): Promise<void> {
    if (await this.platform.saveSupport(this.supportChanges())) {
      this.feedback.success('platform.support.saved');
    } else {
      this.savedSince();
    }
  }

  /** Another operator saved a key of the form since it was read: the form now shows that, and nothing here was kept. */
  private savedSince(): void {
    if (this.platform.error() !== 'record_changed') return;
    this.platform.clearError();
    this.feedback.failure('live.saved_since');
  }

  /**
   * Refused as saved since by another operator, the panel takes what they changed where nothing was typed, keeps what
   * was typed, and offers both ways a term both changed; the next save names their version.
   */
  protected async saveSubscription(companyId: string, values: FormValues): Promise<void> {
    const version = this.subscription()?.version ?? 0;
    if (await this.platform.saveSubscription(companyId, subscriptionTerms(values), version)) {
      this.showTerms(this.subscription());
      this.feedback.success('platform.subscription.saved');
    } else if (this.platform.error() === 'record_changed') {
      const theirs = subscriptionValues(this.subscription());
      const merged = mergeSavedVersion(this.subscriptionGroup(), this.readTerms(), theirs);
      this.readTerms.set(theirs);
      this.subscriptionConflicts.set(merged.conflicts);
      this.savedSince();
    }
  }

  /** A term both changed, settled: theirs is taken into the field, or what was typed stays. */
  protected resolveTerm(resolution: { field: string; choice: 'theirs' | 'mine' }): void {
    const conflict = this.subscriptionConflicts().find(({ field }) => field === resolution.field);
    if (conflict === undefined) return;
    if (resolution.choice === 'theirs') {
      this.subscriptionGroup().controls[conflict.field]?.setValue(conflict.theirs);
    }
    this.subscriptionConflicts.update((conflicts) =>
      conflicts.filter(({ field }) => field !== conflict.field),
    );
  }

  protected async stopSubscription(companyId: string): Promise<void> {
    if (await this.platform.stopSubscription(companyId)) {
      this.feedback.success('platform.subscription.stopped');
      this.showTerms(null);
    }
  }

  protected async approve(companyId: string): Promise<void> {
    await this.platform.approve(companyId);
  }

  protected async reject(companyId: string): Promise<void> {
    await this.platform.reject(companyId);
  }
}
