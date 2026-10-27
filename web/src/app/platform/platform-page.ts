// SPDX-License-Identifier: AGPL-3.0-or-later

import { toSignal } from '@angular/core/rxjs-interop';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
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
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { map } from 'rxjs';
import { refusalParams } from '../shared/form/refusal-words';
import { TextsParts } from '../shared/i18n/texts-part';
import { SETTING_REFUSAL_TEXTS } from '../shared/settings/setting-refusals';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { unsavedChanges } from '../shared/form/dirty-count';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import { mergeSavedVersion, type FieldConflict } from '../shared/form/form-merge';
import type { FormValues } from '../shared/form/form-types';
import { Select, type SelectOption } from '../shared/form/select';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { CountBadge } from '../shared/ui/count-badge';
import { SelectedTabInView } from '../shared/ui/selected-tab-in-view';
import { StatusBadge } from '../shared/ui/status-badge';
import { DocumentActions } from '../shared/ui/document-actions';
import { onRecord, onRows } from '../shared/actions/record-action';
import type { ActionConfirm, ActionKind } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { companyActions } from './platform-actions';
import type { StatusTone } from '../shared/theme/accent-theme';
import { membershipRoleLabel } from './membership-role';
import { PlatformFacade } from './platform-facade';
import { ACCOUNTS_LIST, accountSearch, COMPANIES_LIST, companySearch } from './platform-forms';
import {
  COMPANY_COUNTRIES,
  PLATFORM_TABS,
  type AccountAction,
  type PlatformAccountRow,
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
/** A typed whole number differing from what the platform holds, and within its bounds. */
function numberReady(held: PlatformNumber | null | undefined, typed: number | null): boolean {
  return (
    held !== null &&
    held !== undefined &&
    typed !== null &&
    typed !== held.value &&
    Number.isInteger(typed) &&
    (held.min === null || typed >= held.min) &&
    (held.max === null || typed <= held.max)
  );
}

/** One unsaved change where something other than the held number is typed, a field emptied or out of bounds included. */
function numberTyped(held: PlatformNumber | null | undefined, typed: number | null): number {
  return typed !== null && typed !== (held?.value ?? null) ? 1 : 0;
}

/** What a field says under it: a text key and its params. */
export interface FieldVerdict {
  readonly key: string;
  readonly params: Readonly<Record<string, unknown>>;
}

/**
 * Why a typed number cannot be saved, said on its field rather than only by a greyed button: emptied, not whole, or
 * outside the bounds the platform declares; null while it is untouched or fits.
 */
function numberVerdict(
  held: PlatformNumber | null | undefined,
  typed: number | null | undefined,
): FieldVerdict | null {
  if (typed === null || typed === undefined || held === null || held === undefined) return null;
  if (Number.isNaN(typed)) return { key: 'platform.numbers.empty', params: {} };
  if (!Number.isInteger(typed)) return { key: 'import.rejections.not_a_whole_number', params: {} };
  const { min, max } = held;
  const below = min !== null && typed < min;
  const above = max !== null && typed > max;
  if (!below && !above) return null;
  if (min !== null && max !== null) {
    return { key: 'import.rejections.whole_number_between', params: { min, max } };
  }
  return below
    ? { key: 'import.rejections.at_least', params: { min } }
    : { key: 'import.rejections.at_most', params: { max } };
}

/** How many of these typed texts hold something. */
function filled(texts: readonly string[]): number {
  return texts.filter((text) => text.trim() !== '').length;
}

const LIST_PARAMS = ['q', 'status', 'country', 'state', 'sort', 'page', 'size', 'company'] as const;
const COMPANY_TONES: Readonly<Record<string, StatusTone>> = {
  pending: 'warning',
  active: 'success',
  suspended: 'neutral',
};

/** What the toast after each account action is said under. */
const ACCOUNT_DONE: Readonly<Record<AccountAction, string>> = {
  'end-sessions': 'ended',
  deactivate: 'deactivated',
  reactivate: 'reactivated',
};

/** The kinds of message the screens name; another is shown by its class name, as the worker's own commands show it. */
const FAILED_KINDS: Readonly<Record<string, string>> = {
  InvitationToMail: 'invitation',
  PasswordResetAsked: 'password_reset',
  SignupAsked: 'signup',
};
import { Feedback } from '../shared/feedback/feedback';
import { SubscriptionFacade } from '../licensing/subscription-facade';
import type { WaitingPayment } from '../licensing/subscription-types';
import { MatDialog } from '@angular/material/dialog';
import { runAction } from '../shared/actions/run-action';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { AmountPipe, DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { CompanyFacade } from '../company/company-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { PLATFORM_KINDS } from './platform-kinds';

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
    AmountPipe,
    DayPipe,
    MomentPipe,
    DataList,
    DataListCell,
    StatusBadge,
    DocumentActions,
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
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly route = inject(ActivatedRoute);
  private readonly translate = inject(TranslateService);
  private readonly texts = inject(TextsParts);
  private readonly workingCompanies = inject(CompanyFacade);
  private readonly router = inject(Router);

  /**
   * « Accès du support »: every company whose owner lets support read it now, which an operator in no company opens
   * from here as from the switcher (docs/SPEC.md § 7, support access as a bug).
   */
  protected readonly grants = computed(() =>
    this.workingCompanies.companies().filter((company) => company.supportAccessUntil !== null),
  );

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
  /** A company's actions, declared once for its row, its waiting card and its sheet. */
  private readonly companyRecordActions = companyActions({
    approve: (company) => void this.approve(company),
    suspend: (company) => void this.suspend(company),
    busy: () => this.busy(),
  });
  protected readonly companiesList: ListDescriptor<PlatformCompanyRow> = {
    ...COMPANIES_LIST,
    actions: onRows(this.companyRecordActions),
  };
  /** The open sheet's actions: its bar draws them, and the keys, Ctrl K and the « ? » sheet offer them. */
  protected readonly sheetActions = computed(() =>
    onRecord(this.companyRecordActions, this.opened(), this.busy()),
  );
  /** Each waiting company with its actions, built once per change rather than on every check of the template. */
  protected readonly waitingCards = computed(() =>
    this.platform.waiting().map((company) => ({
      company,
      actions: onRecord(this.companyRecordActions, company, this.busy()),
    })),
  );
  /**
   * An account's actions, declared on its list as a member's are on the company's (one canon of actions): reactivating
   * is a button, what ends access sits behind « ⋮ » and asks first, naming the person.
   */
  protected readonly accountsList = computed<ListDescriptor<PlatformAccountRow>>(() => ({
    ...ACCOUNTS_LIST,
    actions: [
      {
        id: 'reactivate',
        label: 'platform.accounts.reactivate',
        icon: 'lock_open',
        shown: (row) => !row.active,
        disabled: () => this.busy(),
        run: (row) => void this.act(row, 'reactivate'),
      },
      {
        // Nothing brings a session back: whoever was signed in signs in again.
        id: 'end-sessions',
        label: 'platform.accounts.end_sessions',
        icon: 'logout',
        destructive: true,
        shown: (row) => row.active,
        disabled: () => this.busy(),
        run: (row) => void this.act(row, 'end-sessions'),
        confirm: (row) => ({
          kind: 'definitif',
          title: 'platform.accounts.end_sessions_title',
          message: 'platform.accounts.end_sessions_message',
          messageParams: { name: row.displayName },
          confirmLabel: 'platform.accounts.end_sessions',
          keepLabel: 'platform.accounts.keep',
        }),
      },
      {
        // « Réactiver » gives the account back, so this is corrected rather than final.
        id: 'deactivate',
        label: 'platform.accounts.deactivate',
        icon: 'block',
        rare: true,
        shown: (row) => row.active,
        disabled: () => this.busy(),
        run: (row) => void this.act(row, 'deactivate'),
        confirm: (row) => ({
          kind: 'corrigeable',
          title: 'platform.accounts.deactivate_title',
          message: 'platform.accounts.deactivate_message',
          messageParams: { name: row.displayName },
          confirmLabel: 'platform.accounts.deactivate',
          keepLabel: 'platform.accounts.keep',
        }),
      },
    ],
  }));
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
  protected readonly showComing = this.platform.showComing;
  private readonly demoMostHeld = computed(() => this.platform.demo()?.most?.value ?? null);
  /** The count as typed until saved, null when untouched: a switch turned meanwhile leaves what is typed alone. */
  protected readonly demoMostDraft = linkedSignal<number | null, number | null>({
    source: this.demoMostHeld,
    computation: () => null,
  });
  /** A typed count differing from the platform's, whole and within its bounds. */
  protected readonly demoMostReady = computed(() =>
    numberReady(this.platform.demo()?.most, this.demoMostDraft()),
  );
  protected readonly imports = this.platform.imports;
  private readonly importRowsHeld = computed(() => this.platform.imports()?.rows?.value ?? null);
  /** The rows as typed until saved, null when untouched. */
  protected readonly importRowsDraft = linkedSignal<number | null, number | null>({
    source: this.importRowsHeld,
    computation: () => null,
  });
  protected readonly importRowsReady = computed(() =>
    numberReady(this.platform.imports()?.rows, this.importRowsDraft()),
  );
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
  /**
   * What is typed in the cards and not saved: each card saves on its own, beside its fields, but leaving the page or
   * reloading it loses all of them alike, so the page is held while any holds something.
   */
  private readonly cardChanges = computed(() => {
    const delayDraft = this.delayDraft();
    const delaysTyped = (this.platform.delays() ?? []).filter(
      (delay) => delay.key in delayDraft && !Object.is(delayDraft[delay.key], delay.value),
    ).length;
    return (
      Object.keys(this.supportChanges()).length +
      delaysTyped +
      numberTyped(this.platform.demo()?.most, this.demoMostDraft()) +
      numberTyped(this.platform.imports()?.rows, this.importRowsDraft()) +
      (this.creating() ? filled([this.companyName(), this.ownerEmail()]) : 0) +
      filled(Object.values(this.ownerEmails()))
    );
  });

  constructor() {
    // What another operator, an owner or the worker changes about a company shows here at once: the platform's channel
    // says it by kind alone, an owner joining or a grant opened in any company included.
    inject(LiveChanges).reloadOn(
      PLATFORM_KINDS,
      () =>
        Promise.all([
          this.platform.refresh(),
          this.payments.loadWaiting(),
          this.workingCompanies.load(),
        ]),
      inject(DestroyRef),
    );
    inject(UnsavedChanges).declare(this.cardChanges);
    inject(ScreenActions).declare(this.sheetActions);
    // The terms typed in the open panel and not saved, against what the panel last read; counted where it counts.
    unsavedChanges(
      computed(() => (this.openedSubscription() === null ? null : this.subscriptionGroup())),
      this.readTerms,
    );
  }

  async ngOnInit(): Promise<void> {
    // The words a setting's bounds and refusals are said in beyond the platform's own texts; a failure to read them
    // leaves each field saying « Valeur refusée. », as every form does for a reason it has no words for.
    void this.texts.open(SETTING_REFUSAL_TEXTS).catch(() => undefined);
    await Promise.all([this.platform.load(), this.workingCompanies.load()]);
    await this.payments.loadWaiting();
  }

  /** Opens a company support may read, read-only, in this session, then its home. */
  protected async openGrant(companyId: string): Promise<void> {
    if (await this.workingCompanies.switchTo(companyId)) await this.router.navigateByUrl('/');
  }

  /**
   * What a number's field says under it: why what is typed cannot be saved, else what the platform refused it with,
   * else nothing (its bounds are hinted instead).
   */
  protected numberSaid(
    held: PlatformNumber | null | undefined,
    typed: number | null | undefined,
  ): FieldVerdict | null {
    return numberVerdict(held, typed) ?? (held ? this.refusalOf('settings', held.key) : null);
  }

  /** The refusal the platform answered a field written from that place with, in the screens' words. */
  protected refusalOf(at: string, field: string): FieldVerdict | null {
    const held = this.platform.refused();
    if (held.at !== at) return null;
    const refusal = held.refusals.find((each) => each.field === field);
    if (refusal === undefined) return null;
    const key = `import.rejections.${refusal.reason ?? ''}`;
    const known = refusal.reason !== null && this.translate.instant(key) !== key;
    return {
      key: known ? key : 'import.rejections.invalid_value',
      params: refusalParams(this.translate, refusal.params),
    };
  }

  protected typedPeriods(declarationId: string, event: Event): void {
    const periods = Number.parseInt(this.typed(event), 10);
    this.periodsFor.update((held) => ({ ...held, [declarationId]: periods > 0 ? periods : 1 }));
  }

  /**
   * Confirming carries the company's covered time forward by that many periods, so it asks first, naming the company
   * and the periods; a wrong count is corrected in the company's terms, which is why it says « corrigeable ».
   */
  protected askConfirmPayment(payment: WaitingPayment): void {
    const periods = this.periodsFor()[payment.id] ?? 1;
    this.ask(
      {
        kind: 'corrigeable',
        title: 'platform.payments.confirm_title',
        message: 'platform.payments.confirm_message',
        messageParams: { name: payment.companyName, periods: String(periods) },
        confirmLabel: 'platform.payments.confirm',
        keepLabel: 'platform.payments.keep',
      },
      () => void this.confirmPayment(payment.id, periods),
    );
  }

  /** Rejecting ends at once the hold the declaration kept on the company, which may then lose its access. */
  protected askRejectPayment(payment: WaitingPayment): void {
    this.ask(
      {
        kind: 'corrigeable',
        title: 'platform.payments.reject_title',
        message: 'platform.payments.reject_message',
        messageParams: { name: payment.companyName },
        confirmLabel: 'platform.payments.reject',
        keepLabel: 'platform.payments.keep',
      },
      () => void this.rejectPayment(payment.id),
    );
  }

  private async confirmPayment(declarationId: string, periods: number): Promise<void> {
    if (await this.payments.confirm(declarationId, periods, null)) {
      this.feedback.effect('platform.payments.confirmed', {}, 'corrigeable');
      await this.platform.load();
    }
  }

  private async rejectPayment(declarationId: string): Promise<void> {
    if (await this.payments.reject(declarationId, null)) {
      this.feedback.effect('platform.payments.rejected', {}, 'corrigeable');
      await this.platform.load();
    }
  }

  /** The shared confirmation, the one every declared action asks through. */
  private ask(confirm: ActionConfirm, run: () => void): void {
    if (this.busy()) return;
    runAction({ run, confirm }, (asked) =>
      this.dialog
        .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
          data: asked,
          autoFocus: 'dialog',
        })
        .afterClosed(),
    );
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

  /** Done, the toast names the person and says the word its question said; refused, the page says why as before. */
  protected async act(row: PlatformAccountRow, action: AccountAction): Promise<void> {
    if (!(await this.platform.actOnAccount(row.id, action))) return;
    const key = `platform.accounts.done.${ACCOUNT_DONE[action]}`;
    const params = { name: row.displayName };
    const kind = this.accountsList()
      .actions?.find((declared) => declared.id === action)
      ?.confirm?.(row).kind;
    if (kind === undefined) {
      this.feedback.success(key, params);
    } else {
      this.feedback.effect(key, params, kind);
    }
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

  /** A field typed in again: what the platform refused it with no longer says what it holds. */
  protected forget(field: string | undefined): void {
    if (field !== undefined) this.platform.forgetRefusal(field);
  }

  protected typedOwner(companyId: string, event: Event): void {
    const value = this.typed(event);
    this.forget('email');
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
    this.platform.forgetRefusal(`support.${channel}`);
  }

  protected typedDelay(key: string, event: Event): void {
    const raw = (event.target as HTMLInputElement).value.trim();
    this.delayDraft.update((draft) => ({ ...draft, [key]: raw === '' ? Number.NaN : Number(raw) }));
    this.platform.forgetRefusal(key);
  }

  protected typedDemoMost(event: Event): void {
    const raw = (event.target as HTMLInputElement).value.trim();
    this.demoMostDraft.set(raw === '' ? Number.NaN : Number(raw));
    this.forget(this.platform.demo()?.most?.key);
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

  protected typedImportRows(event: Event): void {
    const raw = (event.target as HTMLInputElement).value.trim();
    this.importRowsDraft.set(raw === '' ? Number.NaN : Number(raw));
    this.forget(this.platform.imports()?.rows?.key);
  }

  protected async saveImportRows(): Promise<void> {
    const typed = this.importRowsDraft();
    if (!this.importRowsReady() || typed === null) return;
    if (await this.platform.saveImportRows(typed)) {
      this.feedback.success('platform.imports.saved');
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
      this.takeTheirTerms();
    }
  }

  /** What another operator held since comes into the panel; what was typed stays, and a term both changed is offered. */
  private takeTheirTerms(): void {
    const theirs = subscriptionValues(this.subscription());
    const merged = mergeSavedVersion(this.subscriptionGroup(), this.readTerms(), theirs);
    this.readTerms.set(theirs);
    this.subscriptionConflicts.set(merged.conflicts);
    this.savedSince();
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

  /**
   * Stopping erases the company's terms, which nothing keeps afterwards (the audit names them only), so it asks first
   * as « définitif », naming the company; managing it again means typing its terms anew.
   */
  protected askStopSubscription(company: PlatformCompanyRow): void {
    const version = this.subscription()?.version;
    if (version === undefined) return;
    this.ask(
      {
        kind: 'definitif',
        title: 'platform.subscription.stop_title',
        message: 'platform.subscription.stop_message',
        messageParams: { name: company.name },
        confirmLabel: 'platform.subscription.stop',
        keepLabel: 'platform.subscription.keep',
      },
      () => void this.stopSubscription(company, version),
    );
  }

  private async stopSubscription(company: PlatformCompanyRow, version: number): Promise<void> {
    if (await this.platform.stopSubscription(company.id, version)) {
      this.feedback.effect('platform.subscription.stopped', { name: company.name }, 'definitif');
      this.showTerms(null);
    } else if (this.platform.error() === 'record_changed') {
      this.takeTheirTerms();
    }
  }

  /** Opened, the toast names the company with the word its question said; refused, the page says why as before. */
  private async approve(company: PlatformCompanyRow): Promise<void> {
    const done = company.status === 'suspended' ? 'restored' : 'approved';
    if (await this.platform.approve(company.id)) {
      this.feedback.effect(
        `platform.companies.done.${done}`,
        { name: company.name },
        this.companyKind('approve'),
      );
    }
  }

  private async suspend(company: PlatformCompanyRow): Promise<void> {
    const done = company.status === 'pending' ? 'rejected' : 'suspended';
    if (await this.platform.reject(company.id)) {
      this.feedback.effect(
        `platform.companies.done.${done}`,
        { name: company.name },
        this.companyKind('suspend'),
      );
    }
  }

  /** The kind a company action's question says, read from its one declaration. */
  private companyKind(id: string): ActionKind {
    const effect = this.companyRecordActions.find((action) => action.id === id)?.effect;
    return effect?.kind === 'corrigeable' || effect?.kind === 'definitif'
      ? effect.kind
      : 'annulable';
  }
}
