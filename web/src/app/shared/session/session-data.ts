// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, inject, Injectable, signal, type WritableSignal } from '@angular/core';

/**
 * How far a kept value reaches: `company`, what one company holds (its lists, records, counts), forgotten when the
 * session leaves that company or ends; `person`, what the person holds across their companies (the companies they
 * belong to, what waits in each), forgotten only when the session ends.
 */
export type SessionReach = 'company' | 'person';

/** Who the session belongs to and where it works, as far as forgetting needs to know. */
export interface SessionMark {
  readonly user: { readonly id: string };
  readonly company: { readonly id: string } | null;
}

/**
 * Where everything the application keeps for a session is forgotten, in one place. The facades are shared by the whole
 * application and outlive every screen: without this, a person signing out left their lists, records and company logo
 * in memory for whoever signed in next on the same browser, and a company switched away from showed its rows on the
 * next company's screens until each was read again. The signed-in state tells it every session it holds (`heard`);
 * the facades keep their values through `sessionSignal`, which puts each back to where it started.
 */
@Injectable({ providedIn: 'root' })
export class SessionData {
  private readonly forgets = new Map<() => void, SessionReach>();
  private person: string | null = null;
  private company: string | null = null;

  /** Puts `forget` among what is forgotten, until whatever kept it is destroyed. */
  keep(forget: () => void, reach: SessionReach, destroyRef: DestroyRef): void {
    this.forgets.set(forget, reach);
    destroyRef.onDestroy(() => this.forgets.delete(forget));
  }

  /**
   * The session as the API last told it, null once nobody is signed in. Forgets only on a change: a refused sign-in
   * with nobody signed in forgets nothing, so the sign-in page keeps what it read (whether signup is open).
   */
  heard(state: SessionMark | null): void {
    const person = state?.user.id ?? null;
    const company = state?.company?.id ?? null;
    const personEnded = this.person !== null && person !== this.person;
    const companyLeft = personEnded || (this.company !== null && company !== this.company);
    this.person = person;
    this.company = company;
    if (!companyLeft) return;
    for (const [forget, reach] of [...this.forgets]) {
      if (personEnded || reach === 'company') forget();
    }
  }
}

/** A facade's value for the session: put back to `initial` when the session leaves its company (or ends, for `person`). */
export function sessionSignal<T>(initial: T, reach: SessionReach = 'company'): WritableSignal<T> {
  const value = signal(initial);
  inject(SessionData).keep(() => value.set(initial), reach, inject(DestroyRef));
  return value;
}
