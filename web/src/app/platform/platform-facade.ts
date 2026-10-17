// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { StepUp } from '../shared/step-up/step-up';
import type { FieldValue } from '../shared/form/form-types';
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
  type PlatformDelay,
  type PlatformError,
  type PlatformSettings,
  type PlatformSignup,
  type PlatformSubscriptionRow,
  type SubscriptionTerms,
  type SignupSwitch,
  type PlatformSupport,
} from './platform-types';

/** What an operator runs the platform with: who may sign up, the companies (those waiting for a decision first), and accounts. */
@Injectable({ providedIn: 'root' })
export class PlatformFacade {
  private readonly api = inject(PlatformApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly waitingSignal = signal<readonly PlatformCompanyRow[]>([]);
  private readonly signupSignal = signal<PlatformSignup | null>(null);
  private readonly supportSignal = signal<PlatformSupport | null>(null);
  private readonly delaysSignal = signal<readonly PlatformDelay[] | null>(null);
  /** The version of each platform key's row when read, which a form's save sends back. */
  private versions: Readonly<Record<string, number>> = {};
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<PlatformError | null>(null);

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
  private companyRequest = 0;
  private accountRequest = 0;
  /** Null until read. */
  readonly signup = this.signupSignal.asReadonly();
  /** How companies reach the operators, null until read. */
  readonly support = this.supportSignal.asReadonly();
  /** The numbers of days the platform sets, null until read. */
  readonly delays = this.delaysSignal.asReadonly();
  private readonly subscriptionSignal = signal<PlatformSubscriptionRow | null>(null);
  private readonly openedSignal = signal<string | null>(null);
  /** The subscription of the company whose panel is open, null while it is being read or where there is none. */
  readonly subscription = this.subscriptionSignal.asReadonly();
  /** The company whose subscription panel is open. */
  readonly openedSubscription = this.openedSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /**
   * What the overview shows: the switches, the companies waiting, the counts, the demand for planned modules and the
   * messages the worker gave up on.
   */
  async load(): Promise<void> {
    this.busySignal.set(true);
    try {
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
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /**
   * One page of companies. A search typed one letter at a time answers out of order, so only the answer to the last
   * question is kept.
   */
  async loadCompanies(search: PlatformCompanySearch): Promise<void> {
    this.companySearch = search;
    const request = ++this.companyRequest;
    this.errorSignal.set(null);
    try {
      const page = await this.api.companies(search);
      if (request !== this.companyRequest) return;
      this.companiesSignal.set(page.rows);
      this.companiesTotalSignal.set(page.total);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    }
  }

  /** One page of accounts, each with its companies. */
  async loadAccounts(search: PlatformAccountSearch): Promise<void> {
    this.accountSearch = search;
    const request = ++this.accountRequest;
    this.errorSignal.set(null);
    try {
      const page = await this.api.accounts(search);
      if (request !== this.accountRequest) return;
      this.accountsSignal.set(page.rows);
      this.accountsTotalSignal.set(page.total);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    }
  }

  /**
   * The subscription of the company an operator opened, null when licensing does not manage it. Only the company last
   * opened is held: the panel shows one at a time.
   */
  async openSubscription(companyId: string): Promise<void> {
    this.openedSignal.set(companyId);
    this.subscriptionSignal.set(null);
    await this.write(async () => {
      this.subscriptionSignal.set(await this.api.subscription(companyId));
    });
  }

  closeSubscription(): void {
    this.openedSignal.set(null);
    this.subscriptionSignal.set(null);
  }

  /** True once the terms are held; the company list is read again, since its standing changed. */
  async saveSubscription(companyId: string, terms: SubscriptionTerms): Promise<boolean> {
    return this.write(async () => {
      this.subscriptionSignal.set(
        await this.proven(() => this.api.setSubscription(companyId, terms)),
      );
      await this.refreshCompanies();
      await this.auth.refresh();

      return true;
    });
  }

  async stopSubscription(companyId: string): Promise<boolean> {
    return this.write(async () => {
      await this.proven(() => this.api.stopSubscription(companyId));
      this.subscriptionSignal.set(null);
      await this.refreshCompanies();
      await this.auth.refresh();

      return true;
    });
  }

  private async write<T>(work: () => Promise<T>): Promise<T | false> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      return await work();
    } catch (error) {
      this.errorSignal.set(codeOf(error));

      return false;
    } finally {
      this.busySignal.set(false);
    }
  }

  /** The account is shown as the platform answers it, so a refused action leaves it as it was. */
  async actOnAccount(userId: string, action: AccountAction): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const updated = await this.proven(() => this.api.actOnAccount(userId, action));
      // The answer to an action does not carry the companies, which the row already holds.
      this.accountsSignal.update((rows) =>
        rows.map((row) => (row.id === updated.id ? { ...updated, companies: row.companies } : row)),
      );
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
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
    this.busySignal.set(true);
    this.errorSignal.set(null);
    let opened = false;
    try {
      const companyId = await this.proven(() =>
        this.api.createCompany({ name, countryCode, ...COMPANY_COUNTRIES[countryCode] }),
      );
      opened = true;
      await this.proven(() => this.api.inviteOwner(companyId, ownerEmail));
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      if (opened) {
        await this.readCompanies();
      }
      this.busySignal.set(false);
    }
  }

  /** An owner joins only once they accept, so the list read again shows the same owners until then. */
  async inviteOwner(companyId: string, email: string): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await this.proven(() => this.api.inviteOwner(companyId, email));
      await this.readCompanies();
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }

  approve(companyId: string): Promise<boolean> {
    return this.decide(() => this.api.approve(companyId));
  }

  reject(companyId: string): Promise<boolean> {
    return this.decide(() => this.api.reject(companyId));
  }

  /** The switch shows the new value only once the platform holds it. */
  async setSignup(key: SignupSwitch, value: boolean): Promise<void> {
    this.errorSignal.set(null);
    try {
      await this.proven(() => this.api.setSignup(key, value));
      const current = this.signupSignal();
      if (current !== null) {
        this.signupSignal.set(
          key === 'signup.enabled'
            ? { ...current, enabled: value }
            : { ...current, approvalRequired: value },
        );
      }
    } catch (error) {
      this.errorSignal.set(codeOf(error));
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

  clearError(): void {
    this.errorSignal.set(null);
  }

  /**
   * One save of a form, each key with the version read. One saved since refuses it all as `record_changed`, and what
   * the platform holds now is read, so the form shows it and the next save names its versions.
   */
  private async saveSettings(
    changes: readonly { key: string; value: FieldValue }[],
  ): Promise<boolean> {
    const saved = await this.write(async () => {
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
    });
    if (!saved && this.errorSignal() === 'record_changed') {
      try {
        this.holdSettings(await this.api.settings());
      } catch (error) {
        this.errorSignal.set(codeOf(error));
      }
    }
    return saved;
  }

  private holdSettings(settings: PlatformSettings): void {
    this.signupSignal.set(settings.signup);
    this.supportSignal.set(settings.support);
    this.delaysSignal.set(settings.delays);
    this.versions = settings.versions;
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

  /** A decided company leaves the waiting list, so the list is read again rather than edited in place. */
  private async decide(call: () => Promise<unknown>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await this.proven(call);
      await this.readCompanies();
      // An operator can act on the company they are themselves working in, and its status, access and
      // subscription are read from the session by the shell (developer sweep, 2026-09-20).
      await this.auth.refresh();
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
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

function codeOf(error: unknown): PlatformError {
  return error instanceof PlatformRefused ? error.code : 'network';
}
