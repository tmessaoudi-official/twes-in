// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { PlatformApi, PlatformRefused } from './platform-api';
import {
  COMPANY_COUNTRIES,
  type AccountAction,
  type CompanyCountry,
  type ModuleDemandRow,
  type PlatformAccountRow,
  type PlatformAccountSearch,
  type PlatformCompanySearch,
  type PlatformCompanyRow,
  type PlatformError,
  type PlatformSignup,
  type PlatformSubscriptionRow,
  type SubscriptionTerms,
  type SignupSwitch,
} from './platform-types';

/** What an operator runs the platform with: who may sign up, the companies (those waiting for a decision first), and accounts. */
@Injectable({ providedIn: 'root' })
export class PlatformFacade {
  private readonly api = inject(PlatformApi);
  private readonly auth = inject(AuthFacade);
  private readonly waitingSignal = signal<readonly PlatformCompanyRow[]>([]);
  private readonly signupSignal = signal<PlatformSignup | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<PlatformError | null>(null);

  private readonly accountsSignal = signal<readonly PlatformAccountRow[]>([]);
  private readonly accountsTotalSignal = signal(0);
  private readonly demandSignal = signal<readonly ModuleDemandRow[]>([]);
  /** How many companies wait for each planned module, the most asked for first. */
  readonly demand = this.demandSignal.asReadonly();

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
  private readonly subscriptionSignal = signal<PlatformSubscriptionRow | null>(null);
  private readonly openedSignal = signal<string | null>(null);
  /** The subscription of the company whose panel is open, null while it is being read or where there is none. */
  readonly subscription = this.subscriptionSignal.asReadonly();
  /** The company whose subscription panel is open. */
  readonly openedSubscription = this.openedSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** What the overview shows: the switches, the companies waiting, the counts and the demand for planned modules. */
  async load(): Promise<void> {
    this.busySignal.set(true);
    try {
      const [waiting, signup, every, accounts, demand] = await Promise.all([
        this.api.companies(WAITING_SEARCH),
        this.api.signup(),
        this.api.companies(COUNT_SEARCH),
        this.api.accounts(ACCOUNT_COUNT_SEARCH),
        this.api.moduleDemand(),
      ]);
      this.demandSignal.set(demand);
      this.waitingSignal.set(waiting.rows);
      this.waitingTotalSignal.set(waiting.total);
      this.everyCompanySignal.set(every.total);
      this.everyAccountSignal.set(accounts.total);
      this.signupSignal.set(signup);
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
      this.subscriptionSignal.set(await this.api.setSubscription(companyId, terms));
      await this.refreshCompanies();
      await this.auth.refresh();

      return true;
    });
  }

  async stopSubscription(companyId: string): Promise<boolean> {
    return this.write(async () => {
      await this.api.stopSubscription(companyId);
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
      const updated = await this.api.actOnAccount(userId, action);
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
      const companyId = await this.api.createCompany({
        name,
        countryCode,
        ...COMPANY_COUNTRIES[countryCode],
      });
      opened = true;
      await this.api.inviteOwner(companyId, ownerEmail);
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
      await this.api.inviteOwner(companyId, email);
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
      await this.api.setSignup(key, value);
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

  /** Reads again what the lists last showed and the overview's counts, since a company opened or decided moves them. */
  private async readCompanies(): Promise<void> {
    await Promise.all([this.refreshCompanies(), this.load()]);
  }

  private async refreshCompanies(): Promise<void> {
    if (this.companySearch !== null) await this.loadCompanies(this.companySearch);
  }

  /** A decided company leaves the waiting list, so the list is read again rather than edited in place. */
  private async decide(call: () => Promise<unknown>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
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
  status: 'pending',
  countryCode: null,
  order: { key: 'createdAt', direction: 'asc' },
};
const COUNT_SEARCH: PlatformCompanySearch = {
  page: 1,
  itemsPerPage: 1,
  q: '',
  status: null,
  countryCode: null,
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
