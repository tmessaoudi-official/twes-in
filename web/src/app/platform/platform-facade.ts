// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { StepUp } from '../shared/step-up/step-up';
import type { FieldValue } from '../shared/form/form-types';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { PlatformApi, PlatformRefused } from './platform-api';
import {
  COMPANY_COUNTRIES,
  type AccountAction,
  type CompanyCountry,
  type FailedMessagesRead,
  type ModuleDemandRow,
  type PlatformAccountRow,
  type PlatformAccountSearch,
  type PlatformCompanySearch,
  type PlatformCompanyRow,
  type PlatformDemo,
  type PlatformImports,
  type PlatformError,
  type PlatformNumber,
  type PlatformSettings,
  type PlatformSignup,
  type PlatformSubscriptionRow,
  type SubscriptionTerms,
  type PlatformSwitch,
  type PlatformSupport,
} from './platform-types';
import { LatestRequest, requestState } from '../shared/feedback/request-state';

/** What an operator runs the platform with: who may sign up, the companies (those waiting for a decision first), and accounts. */
@Injectable({ providedIn: 'root' })
export class PlatformFacade {
  private readonly api = inject(PlatformApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly waitingSignal = signal<readonly PlatformCompanyRow[]>([]);
  private readonly signupSignal = signal<PlatformSignup | null>(null);
  private readonly supportSignal = signal<PlatformSupport | null>(null);
  private readonly delaysSignal = signal<readonly PlatformNumber[] | null>(null);
  private readonly demoSignal = signal<PlatformDemo | null>(null);
  private readonly importsSignal = signal<PlatformImports | null>(null);
  /** The version of each platform key's row when read, which a form's save sends back. */
  private versions: Readonly<Record<string, number>> = {};
  private readonly state = requestState(codeOf);
  private readonly refusedSignal = signal<PlatformRefusals>({ at: null, refusals: [] });
  /**
   * The fields the last write was refused on and where it was written from (`settings`, `new-company`, `owner:<id>`):
   * the page says each under its own field there, and a field typed in again forgets its own.
   */
  readonly refused = this.refusedSignal.asReadonly();

  private readonly accountsSignal = signal<readonly PlatformAccountRow[]>([]);
  private readonly accountsTotalSignal = signal(0);
  private readonly demandSignal = signal<readonly ModuleDemandRow[]>([]);
  /** How many companies wait for each planned module, the most asked for first. */
  readonly demand = this.demandSignal.asReadonly();
  private readonly failedSignal = signal<FailedMessagesRead>({ total: 0, kinds: [] });
  /** What the worker gave up on, waiting to be retried on it. */
  readonly failed = this.failedSignal.asReadonly();

  /** The oldest companies waiting for a decision, a few, for the overview. */
  readonly waiting = this.waitingSignal.asReadonly();
  private readonly waitingTotalSignal = signal(0);
  /** How many companies wait for a decision in all. */
  readonly waitingTotal = this.waitingTotalSignal.asReadonly();
  private readonly everyCompanySignal = signal(0);
  /** How many companies there are, whatever their status. */
  readonly companyCount = this.everyCompanySignal.asReadonly();
  private readonly everyAccountSignal = signal(0);
  /** How many accounts there are. */
  readonly accountCount = this.everyAccountSignal.asReadonly();
  /** The page of accounts the list shows. */
  readonly accounts = this.accountsSignal.asReadonly();
  readonly accountsTotal = this.accountsTotalSignal.asReadonly();
  private readonly companiesSignal = signal<readonly PlatformCompanyRow[]>([]);
  private readonly companiesTotalSignal = signal(0);
  /** The page of companies the list shows. */
  readonly companies = this.companiesSignal.asReadonly();
  readonly companiesTotal = this.companiesTotalSignal.asReadonly();
  private companySearch: PlatformCompanySearch | null = null;
  private accountSearch: PlatformAccountSearch | null = null;
  private readonly companyPages = new LatestRequest();
  private readonly accountPages = new LatestRequest();
  /** Null until read. */
  readonly signup = this.signupSignal.asReadonly();
  /** How companies reach the operators, null until read. */
  readonly support = this.supportSignal.asReadonly();
  /** The numbers of days the platform sets, null until read. */
  readonly delays = this.delaysSignal.asReadonly();
  /** Whether visitors may try the demo and how many sandboxes may exist at once, null until read. */
  readonly demo = this.demoSignal.asReadonly();
  /** How many rows one imported file may hold, null until read. */
  readonly imports = this.importsSignal.asReadonly();
  private readonly subscriptionSignal = signal<PlatformSubscriptionRow | null>(null);
  private readonly openedSignal = signal<string | null>(null);
  /** The subscription of the company whose panel is open, null while it is being read or where there is none. */
  readonly subscription = this.subscriptionSignal.asReadonly();
  /** The company whose subscription panel is open. */
  readonly openedSubscription = this.openedSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  /**
   * What the overview shows: the switches, the companies waiting, the counts, the demand for planned modules and the
   * messages the worker gave up on.
   */
  async load(): Promise<void> {
    // A reload nobody asked for leaves busy as it is, so no action turns disabled under a pointer (QuietReads).
    await this.state.read(async () => {
      const [waiting, settings, every, accounts, demand, failed] = await Promise.all([
        this.api.companies(WAITING_SEARCH),
        this.api.settings(),
        this.api.companies(COUNT_SEARCH),
        this.api.accounts(ACCOUNT_COUNT_SEARCH),
        this.api.moduleDemand(),
        this.api.failedMessages(),
      ]);
      this.demandSignal.set(demand);
      this.failedSignal.set(failed);
      this.waitingSignal.set(waiting.rows);
      this.waitingTotalSignal.set(waiting.total);
      this.everyCompanySignal.set(every.total);
      this.everyAccountSignal.set(accounts.total);
      this.holdSettings(settings);
    });
  }

  /**
   * One page of companies. A search typed one letter at a time answers out of order, so only the answer to the last
   * question is kept.
   */
  async loadCompanies(search: PlatformCompanySearch): Promise<void> {
    this.companySearch = search;
    const current = this.companyPages.next();
    this.state.clearError();
    try {
      const page = await this.api.companies(search);
      if (!current()) return;
      this.companiesSignal.set(page.rows);
      this.companiesTotalSignal.set(page.total);
    } catch (error) {
      this.state.fail(error);
    }
  }

  /** One page of accounts, each with its companies. */
  async loadAccounts(search: PlatformAccountSearch): Promise<void> {
    this.accountSearch = search;
    const current = this.accountPages.next();
    this.state.clearError();
    try {
      const page = await this.api.accounts(search);
      if (!current()) return;
      this.accountsSignal.set(page.rows);
      this.accountsTotalSignal.set(page.total);
    } catch (error) {
      this.state.fail(error);
    }
  }

  /**
   * The subscription of the company an operator opened, null when licensing does not manage it. Only the company last
   * opened is held: the panel shows one at a time.
   */
  async openSubscription(companyId: string): Promise<void> {
    this.openedSignal.set(companyId);
    this.subscriptionSignal.set(null);
    await this.state.write(async () => {
      this.subscriptionSignal.set(await this.api.subscription(companyId));
    });
  }

  closeSubscription(): void {
    this.openedSignal.set(null);
    this.subscriptionSignal.set(null);
  }

  /**
   * True once the terms are held; the company list is read again, since its standing changed. Refused as saved since,
   * the subscription is read again, so the panel shows what is held now and the next save names its version.
   */
  async saveSubscription(
    companyId: string,
    terms: SubscriptionTerms,
    version: number,
  ): Promise<boolean> {
    const saved = await this.state.write(async () => {
      this.subscriptionSignal.set(
        await this.proven(() => this.api.setSubscription(companyId, terms, version)),
      );
      await this.refreshCompanies();
      await this.auth.refresh();

      return true;
    });
    if (saved === false && this.state.error() === 'record_changed') {
      try {
        this.subscriptionSignal.set(await this.api.subscription(companyId));
      } catch (error) {
        this.state.fail(error);
      }
    }

    return saved;
  }

  /**
   * True once the terms are erased, on the version they were read at. Refused as changed since, the subscription is
   * read again, so the panel shows what is held now rather than terms another operator replaced.
   */
  async stopSubscription(companyId: string, version: number): Promise<boolean> {
    const stopped = await this.state.write(async () => {
      await this.proven(() => this.api.stopSubscription(companyId, version));
      this.subscriptionSignal.set(null);
      await this.refreshCompanies();
      await this.auth.refresh();

      return true;
    });
    if (stopped === false && this.state.error() === 'record_changed') {
      try {
        this.subscriptionSignal.set(await this.api.subscription(companyId));
      } catch (error) {
        this.state.fail(error);
      }
    }

    return stopped;
  }

  /** The account is shown as the platform answers it, so a refused action leaves it as it was. */
  async actOnAccount(userId: string, action: AccountAction): Promise<boolean> {
    return this.state.write(async () => {
      const updated = await this.proven(() => this.api.actOnAccount(userId, action));
      // The answer to an action does not carry the companies, which the row already holds.
      this.accountsSignal.update((rows) =>
        rows.map((row) => (row.id === updated.id ? { ...updated, companies: row.companies } : row)),
      );
    });
  }

  /**
   * Opens a company with what a company in its country starts with, then invites its first owner. A company opened
   * whose invitation is refused still exists, so the lists are read again whenever the opening went through.
   */
  async openCompany(
    name: string,
    countryCode: CompanyCountry,
    ownerEmail: string,
  ): Promise<boolean> {
    this.refusedSignal.set({ at: 'new-company', refusals: [] });
    return this.state.marked(async () => {
      this.state.clearError();
      let opened = false;
      try {
        const companyId = await this.proven(() =>
          this.api.createCompany({ name, countryCode, ...COMPANY_COUNTRIES[countryCode] }),
        );
        opened = true;
        await this.proven(() => this.api.inviteOwner(companyId, ownerEmail));
        return true;
      } catch (error) {
        this.state.fail(error);
        this.refusedAt('new-company')(error);
        return false;
      } finally {
        if (opened) {
          await this.readCompanies();
        }
      }
    });
  }

  /** An owner joins only once they accept, so the list read again shows the same owners until then. */
  async inviteOwner(companyId: string, email: string): Promise<boolean> {
    const at = `owner:${companyId}`;
    this.refusedSignal.set({ at, refusals: [] });
    return this.state.write(
      async () => {
        await this.proven(() => this.api.inviteOwner(companyId, email));
        await this.readCompanies();
      },
      { failed: this.refusedAt(at) },
    );
  }

  approve(companyId: string): Promise<boolean> {
    return this.decide(() => this.api.approve(companyId));
  }

  reject(companyId: string): Promise<boolean> {
    return this.decide(() => this.api.reject(companyId));
  }

  /** The switch shows the new value only once the platform holds it. */
  async setSwitch(key: PlatformSwitch, value: boolean): Promise<void> {
    this.state.clearError();
    try {
      await this.proven(() => this.api.setSwitch(key, value));
      const signup = this.signupSignal();
      const demo = this.demoSignal();
      if (key === 'demo.enabled') {
        if (demo !== null) this.demoSignal.set({ ...demo, enabled: value });
      } else if (signup !== null) {
        this.signupSignal.set(
          key === 'signup.enabled'
            ? { ...signup, enabled: value }
            : { ...signup, approvalRequired: value },
        );
      }
    } catch (error) {
      this.state.fail(error);
    }
  }

  /**
   * Saves the channels changed, together or not at all, and shows what the platform then holds; the signed-in state is
   * read again, since this tab's own help panel offers them and a live change never comes back to the tab that made it.
   */
  async saveSupport(changes: Partial<PlatformSupport>): Promise<boolean> {
    const saved = await this.saveSettings(
      Object.entries(changes).map(([channel, value]) => ({ key: `support.${channel}`, value })),
    );
    if (saved) await this.auth.refresh();
    return saved;
  }

  /** Saves the delays changed, together or not at all, and shows what the platform then holds. */
  async saveDelays(changes: Readonly<Record<string, number>>): Promise<boolean> {
    return this.saveSettings(Object.entries(changes).map(([key, value]) => ({ key, value })));
  }

  /** Saves how many demo sandboxes may exist at once, with the version read, and shows what the platform then holds. */
  async saveDemoMost(most: number): Promise<boolean> {
    const key = this.demoSignal()?.most?.key;
    if (key === undefined) return false;
    return this.saveSettings([{ key, value: most }]);
  }

  /** Saves how many rows one imported file may hold, with the version read, and shows what the platform then holds. */
  async saveImportRows(rows: number): Promise<boolean> {
    const key = this.importsSignal()?.rows?.key;
    if (key === undefined) return false;
    return this.saveSettings([{ key, value: rows }]);
  }

  clearError(): void {
    this.state.clearError();
  }

  /**
   * One save of a form, each key with the version read. One saved since refuses it all as `record_changed`, and what
   * the platform holds now is read, so the form shows it and the next save names its versions.
   */
  private async saveSettings(
    changes: readonly { key: string; value: FieldValue }[],
  ): Promise<boolean> {
    this.refusedSignal.set({ at: 'settings', refusals: [] });
    const saved = await this.state.write(
      async () => {
        await this.proven(() =>
          this.api.saveSettings(
            changes.map(({ key, value }) => {
              const version = this.versions[key];
              if (version === undefined) throw new Error(`No version was read for ${key}.`);
              return { key, value, version };
            }),
          ),
        );
        this.holdSettings(await this.api.settings());
        return true;
      },
      { failed: this.refusedAt('settings') },
    );
    if (!saved && this.state.error() === 'record_changed') {
      try {
        this.holdSettings(await this.api.settings());
      } catch (error) {
        this.state.fail(error);
      }
    }
    return saved;
  }

  private holdSettings(settings: PlatformSettings): void {
    this.signupSignal.set(settings.signup);
    this.supportSignal.set(settings.support);
    this.delaysSignal.set(settings.delays);
    this.demoSignal.set(settings.demo);
    this.importsSignal.set(settings.imports);
    this.versions = settings.versions;
  }

  /**
   * Reads again everything the page shows, quietly, when a company, its owner, a grant, a subscription or a payment
   * changed elsewhere: the overview, and the lists as last searched.
   */
  async refresh(): Promise<void> {
    await Promise.all([
      this.readCompanies(),
      this.accountSearch === null ? Promise.resolve() : this.loadAccounts(this.accountSearch),
    ]);
  }

  /** Reads again what the lists last showed and the overview's counts, since a company opened or decided moves them. */
  private async readCompanies(): Promise<void> {
    await Promise.all([this.refreshCompanies(), this.load()]);
  }

  private async refreshCompanies(): Promise<void> {
    if (this.companySearch !== null) await this.loadCompanies(this.companySearch);
  }

  /**
   * Every platform write waits for the operator to prove who is at the screen again (docs/SPEC.md § 7, the step-up
   * list): asked once when the platform holds the write back, then the write is sent again. A proof holds a few
   * minutes, so the next writes in a row ask nothing.
   */
  private proven<T>(call: () => Promise<T>): Promise<T> {
    return this.stepUp.retrying(
      call,
      (error) => error instanceof PlatformRefused && error.code === 'step_up_required',
      'step_up.intro_change',
    );
  }

  /** The fields a write was refused on, kept for the place it was written from; none for any other failure. */
  private refusedAt(at: string): (error: unknown) => void {
    return (error) =>
      this.refusedSignal.set({
        at,
        refusals: error instanceof PlatformRefused ? error.refusals : [],
      });
  }

  /** A field typed in again: its refusal no longer says what it holds. */
  forgetRefusal(field: string): void {
    this.refusedSignal.update((held) => ({
      ...held,
      refusals: held.refusals.filter((refusal) => refusal.field !== field),
    }));
  }

  /** A decided company leaves the waiting list, so the list is read again rather than edited in place. */
  private async decide(call: () => Promise<unknown>): Promise<boolean> {
    return this.state.write(async () => {
      await this.proven(call);
      await this.readCompanies();
      // An operator can act on the company they are themselves working in, and its status, access and
      // subscription are read from the session by the shell (developer sweep, 2026-09-20).
      await this.auth.refresh();
    });
  }
}

const WAITING_SEARCH: PlatformCompanySearch = {
  page: 1,
  itemsPerPage: 3,
  q: '',
  statuses: ['pending'],
  countryCodes: [],
  order: { key: 'createdAt', direction: 'asc' },
};
const COUNT_SEARCH: PlatformCompanySearch = {
  page: 1,
  itemsPerPage: 1,
  q: '',
  statuses: [],
  countryCodes: [],
  order: null,
};
const ACCOUNT_COUNT_SEARCH: PlatformAccountSearch = {
  page: 1,
  itemsPerPage: 1,
  q: '',
  active: null,
  platformOperator: null,
  order: null,
};

/** What a write was refused on, field by field, and the place on the page it was written from. */
export interface PlatformRefusals {
  readonly at: string | null;
  readonly refusals: readonly FieldRefusal[];
}

function codeOf(error: unknown): PlatformError {
  return error instanceof PlatformRefused ? error.code : 'network';
}
