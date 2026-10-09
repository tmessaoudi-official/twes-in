// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/common';
import { DestroyRef, inject, Injectable, signal } from '@angular/core';
import { Feedback } from '../feedback/feedback';
import { FormatFacade } from '../i18n/format-facade';
import { tabId } from '../realtime/tab-interceptor';
import { Session } from '../session/session';
import { type PairingEcho, PairingApi, PairingRefused } from './pairing-api';
import { ScanBus, type ScanOutcome } from './scan-bus';
import { SCAN_DETAILS } from './scan-details';
import { type ScanOffer, ScanOffers } from './scan-offers';

/** How often the tab says it is still there; the API lets a pairing lapse after 90 seconds without a word. */
export const HEARTBEAT_MS = 30_000;

/** How long an unclaimed scan waits for its card to say what it offers before the phone hears it was not known. */
const OFFER_WAIT_MS = 4_000;

/** How many phone scan ids are remembered, so a publication heard twice acts once. */
const SEEN_MAX = 200;

/** What a screen made of a photo the phone took: said back to the phone, as a scan's outcome is. */
export interface PhotoOutcome {
  readonly outcome: 'done' | 'refused';
  /** A translation key the phone says. */
  readonly message: string;
  readonly params: Readonly<Record<string, string | number>>;
}

/** A screen that takes the phone's photos while it is on view, such as a product's photos. */
export type PhotoTaker = (photo: File) => Promise<PhotoOutcome>;

export interface PairingState {
  readonly id: string;
  /** What the phone opens: this address, with the single-use link after `#`, which no server log or Referer carries. */
  readonly url: string;
  readonly phone: 'waiting' | 'connected';
}

/**
 * This tab's phone, lent as a scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4). Each code the phone reads acts
 * here once, exactly as this tab's own scanner would, under this tab's session; the phone then hears what was made
 * of it — the sentence the toast said, the product's name and customer price, and the choices of the card when no
 * screen claimed the code — and a tap on one of those choices comes back to do it here.
 */
@Injectable({ providedIn: 'root' })
export class PhonePairing {
  private readonly api = inject(PairingApi);
  private readonly bus = inject(ScanBus);
  private readonly offers = inject(ScanOffers);
  private readonly details = inject(SCAN_DETAILS);
  private readonly session = inject(Session);
  private readonly format = inject(FormatFacade);
  private readonly feedback = inject(Feedback);
  private readonly origin = inject(DOCUMENT).location.origin;
  private readonly current = signal<PairingState | null>(null);
  private companyId: string | null = null;
  private heartbeat: ReturnType<typeof setInterval> | null = null;
  private readonly seen: string[] = [];
  private readonly takers: PhotoTaker[] = [];
  /** The last echo that offered choices, which is the only one a tap can answer. */
  private offered: { readonly echo: string; readonly offer: ScanOffer } | null = null;

  readonly state = this.current.asReadonly();

  constructor() {
    // Closing the tab ends the lending now, so the phone is told and stops instead of scanning into nothing.
    inject(DOCUMENT).defaultView?.addEventListener('pagehide', () => this.leave());
  }

  /** A new link; a phone already lent by this tab is let go first. */
  async open(): Promise<void> {
    const companyId = this.session.me()?.company?.id;
    if (companyId === undefined) return;
    this.end();
    const opened = await this.api.open(companyId);
    this.companyId = companyId;
    this.current.set({
      id: opened.id,
      // A tab on localhost shows a link no phone can follow; the API names the address a phone reaches, if any.
      url: `${opened.address ?? this.origin}/pair#${opened.link}`,
      phone: 'waiting',
    });
    this.heartbeat = setInterval(() => void this.renew(), HEARTBEAT_MS);
  }

  /**
   * The screen's own taking of the phone's photos, for as long as the screen lives; the screen declared last is the
   * one on view. Without a DestroyRef it must be called from an injection context.
   */
  takePhotos(taker: PhotoTaker, destroyRef: DestroyRef = inject(DestroyRef)): void {
    this.takers.push(taker);
    destroyRef.onDestroy(() => {
      const at = this.takers.indexOf(taker);
      if (at !== -1) this.takers.splice(at, 1);
    });
  }

  /** Lets the phone go at once; it hears so and stops. */
  end(): void {
    const state = this.current();
    const companyId = this.companyId;
    this.stop();
    if (state !== null && companyId !== null) {
      void this.api.end(companyId, state.id).catch(() => undefined);
    }
  }

  private leave(): void {
    const state = this.current();
    const companyId = this.companyId;
    this.stop();
    if (state !== null && companyId !== null) this.api.endOnLeave(companyId, state.id);
  }

  /**
   * A realtime publication on this user's channel. True when it was a pairing's, which is for this tab alone to act
   * on, and for nobody (the notification centre included) to read as anything else.
   */
  receive(data: unknown): boolean {
    if (!isRecord(data) || data['type'] !== 'pairing') return false;
    const state = this.current();
    if (state === null || data['tab'] !== tabId() || data['pairing'] !== state.id) return true;
    const event = data['event'];
    if (event === 'claimed') {
      this.current.set({ ...state, phone: 'connected' });
      this.feedback.success('scan.phone.connected');
    } else if (
      event === 'scan' &&
      typeof data['scan'] === 'string' &&
      typeof data['code'] === 'string'
    ) {
      void this.scanned(data['scan'], data['code']);
    } else if (
      event === 'photo' &&
      typeof data['scan'] === 'string' &&
      typeof data['photo'] === 'string'
    ) {
      void this.photographed(data['scan'], data['photo']);
    } else if (
      event === 'choice' &&
      typeof data['echo'] === 'string' &&
      typeof data['choice'] === 'string'
    ) {
      this.chosen(data['echo'], data['choice']);
    }
    return true;
  }

  private async scanned(scanId: string, code: string): Promise<void> {
    if (this.seen.includes(scanId)) return;
    this.seen.push(scanId);
    if (this.seen.length > SEEN_MAX) this.seen.shift();
    this.current.update((state) => (state === null ? null : { ...state, phone: 'connected' }));
    const outcome = await this.bus.receive(code, 'phone');
    const offer = outcome.kind === 'unclaimed' ? await this.offers.next(code, OFFER_WAIT_MS) : null;
    const lines = await this.detailsOf(code, outcome, offer);
    const echo = { ...this.echoOf(scanId, outcome, offer), details: lines };
    this.offered = offer !== null && echo.choices.length > 0 ? { echo: echo.id, offer } : null;
    await this.send(echo);
  }

  /**
   * A photo the phone took: taken at once, so none is left waiting, and handed to the screen on view that takes
   * photos; the phone hears what it made of it, or where to go when no screen on view takes one.
   */
  private async photographed(scanId: string, photoId: string): Promise<void> {
    const state = this.current();
    const companyId = this.companyId;
    if (state === null || companyId === null || this.seen.includes(scanId)) return;
    this.seen.push(scanId);
    if (this.seen.length > SEEN_MAX) this.seen.shift();
    let outcome: PhotoOutcome;
    try {
      const bytes = await this.api.takePhoto(companyId, state.id, photoId);
      const taker = this.takers.at(-1);
      outcome =
        taker === undefined
          ? { outcome: 'refused', message: 'scan.phone.photo.nowhere', params: {} }
          : await taker(new File([bytes], 'photo.jpg', { type: bytes.type || 'image/jpeg' }));
    } catch {
      // Too late to take, or the network: either way the photo did not arrive, and the phone may take it again.
      outcome = { outcome: 'refused', message: 'scan.phone.photo.lost', params: {} };
    }
    await this.send({
      id: crypto.randomUUID(),
      scan: scanId,
      outcome: outcome.outcome,
      message: outcome.message,
      params: flat(outcome.params),
      product: null,
      choices: [],
    });
  }

  /** What the phone is told of a product the scan named; nothing for a refusal or a code nobody holds. */
  private async detailsOf(
    code: string,
    outcome: ScanOutcome,
    offer: ScanOffer | null,
  ): Promise<string[]> {
    const named = outcome.kind === 'done' ? outcome.product : offer?.product;
    if (!named) return [];
    try {
      return [...(await this.details.of(code))].slice(0, 6);
    } catch {
      // The lines are an extra: the sentence and the price already say what happened.
      return [];
    }
  }

  private chosen(echo: string, choice: string): void {
    const offered = this.offered;
    if (offered === null || offered.echo !== echo) return;
    if (!offered.offer.choices.some((each) => each.id === choice)) return;
    this.offered = null;
    offered.offer.choose(choice);
  }

  private echoOf(scanId: string, outcome: ScanOutcome, offer: ScanOffer | null): PairingEcho {
    const base = { id: crypto.randomUUID(), scan: scanId };
    if (outcome.kind === 'unclaimed') {
      return {
        ...base,
        outcome: 'unclaimed',
        message: offer?.message ?? 'scan.phone.unknown',
        params: flat(offer?.params ?? {}),
        product: offer?.product ? this.shown(offer.product) : null,
        choices: (offer?.choices ?? []).map(({ id, label }) => ({ id, label })),
      };
    }
    return {
      ...base,
      outcome: outcome.kind,
      message: outcome.key,
      params: flat(outcome.params ?? {}),
      product: outcome.kind === 'done' && outcome.product ? this.shown(outcome.product) : null,
      choices: [],
    };
  }

  /** The price as the screen writes it, in the company's currency: what a customer reads, never the cost. */
  private shown(product: { name: string; unitPrice: string }): { name: string; price: string } {
    const currency = this.session.me()?.company?.currency ?? '';
    return {
      name: product.name,
      price: `${this.format.amount(product.unitPrice, null)} ${currency}`.trim(),
    };
  }

  private async send(echo: PairingEcho): Promise<void> {
    const state = this.current();
    if (state === null || this.companyId === null) return;
    try {
      await this.api.echo(this.companyId, state.id, echo);
    } catch (error) {
      this.lapsed(error);
    }
  }

  private async renew(): Promise<void> {
    const state = this.current();
    if (state === null || this.companyId === null) return;
    try {
      await this.api.renew(this.companyId, state.id);
    } catch (error) {
      this.lapsed(error);
    }
  }

  /** A refusal means the pairing is over (signed out elsewhere, the company closed); a lost network is retried. */
  private lapsed(error: unknown): void {
    if (!(error instanceof PairingRefused) || error.reason === 'network') return;
    this.stop();
    this.feedback.failure('scan.phone.ended');
  }

  private stop(): void {
    if (this.heartbeat !== null) clearInterval(this.heartbeat);
    this.heartbeat = null;
    this.current.set(null);
    this.companyId = null;
    this.offered = null;
  }
}

/** What an echo's parameters may hold: short text and whole numbers, one level deep. */
function flat(params: Readonly<Record<string, unknown>>): Record<string, string | number> {
  const kept: Record<string, string | number> = {};
  for (const [name, value] of Object.entries(params)) {
    if (!/^[a-z][a-zA-Z]{0,31}$/.test(name)) continue;
    if (typeof value === 'number' && Number.isInteger(value)) kept[name] = value;
    else if (typeof value === 'number') kept[name] = String(value);
    else if (typeof value === 'string') kept[name] = value.slice(0, 200);
  }
  return kept;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}
