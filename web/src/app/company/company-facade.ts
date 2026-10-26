// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { CompanyApi, CompanyRefused } from './company-api';
import type { CompanyError, CompanyOption } from './company-types';
import { sessionSignal } from '../shared/session/session-data';

/** How long after a support grant's end the list is read again, so the API has surely let it go. */
const AFTER_GRANT_ENDS_MS = 5_000;
/** The longest wait a browser's timer keeps: past it, `setTimeout` fires at once, and the list would be read in a loop. */
const LONGEST_TIMER_MS = 2_147_483_647;

/**
 * What the switcher shows and does. Switching changes the session on the server, so the signed-in state is
 * re-read from the API afterwards rather than patched here: the session is the truth, not this signal.
 */
@Injectable({ providedIn: 'root' })
export class CompanyFacade {
  private readonly api = inject(CompanyApi);
  private readonly auth = inject(AuthFacade);
  private readonly unsaved = inject(UnsavedChanges);
  private readonly feedback = inject(Feedback);
  // The companies the person belongs to: kept across a switch, forgotten at sign-out.
  private readonly companiesSignal = sessionSignal<readonly CompanyOption[]>([], 'person');
  private readonly switchingSignal = sessionSignal(false);
  private readonly errorSignal = sessionSignal<CompanyError | null>(null);
  private grantEnds: ReturnType<typeof setTimeout> | null = null;

  readonly companies = this.companiesSignal.asReadonly();
  readonly switching = this.switchingSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** The company the session is working in, as the auth state last reported it. */
  readonly current = computed(() => this.auth.me()?.company ?? null);

  /**
   * True when there is somewhere else to go, or somewhere to go at all: one company open needs no switcher, but with
   * none open the only one offered is chosen there (an operator reading a company under support access, docs/SPEC.md
   * § 7).
   */
  readonly canSwitch = computed(() => {
    const offered = this.companiesSignal().length;
    return offered > 1 || (offered > 0 && this.current() === null);
  });

  async load(): Promise<readonly CompanyOption[]> {
    try {
      const companies = await this.api.companies();
      this.companiesSignal.set(companies);
      this.errorSignal.set(null);
      this.readAgainWhenAGrantEnds(companies);
      return companies;
    } catch (error) {
      this.companiesSignal.set([]);
      this.errorSignal.set(error instanceof CompanyRefused ? error.code : 'network');
      return [];
    }
  }

  /**
   * Nothing is recorded when a support grant's time is up, so nothing is heard: the list is read again at that moment,
   * and the session with it when the company open was that one, so an ended grant is no longer offered.
   */
  private readAgainWhenAGrantEnds(companies: readonly CompanyOption[]): void {
    if (this.grantEnds !== null) clearTimeout(this.grantEnds);
    this.grantEnds = null;
    const ends = companies
      .map((company) =>
        company.supportAccessUntil === null ? NaN : Date.parse(company.supportAccessUntil),
      )
      .filter((end) => Number.isFinite(end));
    if (ends.length === 0) return;
    const first = Math.min(...ends);
    this.grantEnds = setTimeout(
      () => {
        this.grantEnds = null;
        if (this.auth.me() === null) return;
        const open = this.current()?.id;
        const ended = companies.some(
          (company) =>
            company.id === open &&
            company.supportAccessUntil !== null &&
            Date.parse(company.supportAccessUntil) <= Date.now(),
        );
        void this.load();
        if (ended) void this.auth.refresh();
      },
      Math.min(Math.max(0, first - Date.now()) + AFTER_GRANT_ENDS_MS, LONGEST_TIMER_MS),
    );
  }

  /** Pins the company every sign-in opens (null: the one last worked in), then reads the list that says so. */
  async pinAtSignIn(companyId: string | null): Promise<boolean> {
    this.errorSignal.set(null);
    try {
      await this.api.pinAtSignIn(companyId);
    } catch (error) {
      this.errorSignal.set(error instanceof CompanyRefused ? error.code : 'network');
      return false;
    }
    await this.load();
    return true;
  }

  /** Returns whether the session actually moved. */
  async switchTo(companyId: string): Promise<boolean> {
    if (companyId === this.current()?.id) {
      return true;
    }
    // Switching rebuilds every open screen for the company chosen, so anything typed and not saved goes with it.
    if (!(await firstValueFrom(this.unsaved.confirmLeave()))) {
      return false;
    }
    this.switchingSignal.set(true);
    this.errorSignal.set(null);
    try {
      await this.api.switchTo(companyId);
      // `load` here and NOT `refresh`, deliberately: the server has already moved, so keeping the old session on
      // a failed read would show one company while acting for another. Signing out is the safe end of that.
      await this.auth.load();
      return true;
    } catch (error) {
      // Said here, for every caller: the menu closes and the bar still names the company the session stayed in. The
      // list is read again, so a company suspended or a membership removed since it was read is no longer offered;
      // what stays in error is the switch, not that read.
      this.feedback.failure('company.switch_failed');
      await this.load();
      this.errorSignal.set(error instanceof CompanyRefused ? error.code : 'network');
      return false;
    } finally {
      this.switchingSignal.set(false);
    }
  }
}
