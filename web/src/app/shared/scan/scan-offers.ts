// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';

/** One of the choices a card offers, as a phone shows it: an id to send back and a translation key to show. */
export interface OfferedChoice {
  readonly id: string;
  readonly label: string;
}

/**
 * What a card opened for a scan offers, for a phone that sent the scan (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4):
 * a sentence as a translation key, the product as a customer sees it, and the same choices as its buttons.
 */
export interface ScanOffer {
  readonly code: string;
  readonly message: string;
  readonly params: Readonly<Record<string, string | number>>;
  /** The customer price for one unit, as the API keeps it; never the cost. */
  readonly product: { readonly name: string; readonly unitPrice: string } | null;
  readonly choices: readonly OfferedChoice[];
  /** Does what the card's button of that id does; an id it did not offer does nothing. */
  readonly choose: (id: string) => void;
}

/**
 * Where the card opened for a scan says what it offers, and where a paired phone waits for it: the card learns what a
 * code names from the API after it opens, so the phone's answer comes when the card has one, not when the scan ended.
 */
@Injectable({ providedIn: 'root' })
export class ScanOffers {
  private current: ScanOffer | null = null;
  private readonly waiting = new Map<string, ((offer: ScanOffer) => void)[]>();

  /** Offers until the returned function withdraws it: the card closing. */
  offer(offer: ScanOffer): () => void {
    this.current = offer;
    const waiters = this.waiting.get(offer.code) ?? [];
    this.waiting.delete(offer.code);
    for (const resolve of waiters) resolve(offer);
    return () => {
      if (this.current === offer) this.current = null;
    };
  }

  /** The offer for this code, standing now or made within `withinMs`; null when none comes. */
  next(code: string, withinMs: number): Promise<ScanOffer | null> {
    if (this.current?.code === code) return Promise.resolve(this.current);
    return new Promise((resolve) => {
      const waiter = (offer: ScanOffer | null) => {
        clearTimeout(timer);
        resolve(offer);
      };
      const timer = setTimeout(() => {
        const waiters = this.waiting.get(code) ?? [];
        this.waiting.set(
          code,
          waiters.filter((each) => each !== waiter),
        );
        resolve(null);
      }, withinMs);
      this.waiting.set(code, [...(this.waiting.get(code) ?? []), waiter]);
    });
  }
}
