// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  type DestroyRef,
  DOCUMENT,
  inject,
  Injectable,
  InjectionToken,
  type Signal,
  signal,
} from '@angular/core';
import { Session } from '../session/session';

/** One end of a channel between the windows of this browser. */
export interface DisplayChannel {
  post(data: unknown): void;
  listen(hear: (data: unknown) => void): void;
  close(): void;
}

/** Opens a channel of that name, or answers null where the browser has none. */
export type DisplayChannelOpener = (name: string) => DisplayChannel | null;

/** The browser's own BroadcastChannel: every window of this browser, on this origin, that opened the same name. */
export const broadcastChannelOpener: DisplayChannelOpener = (name) => {
  if (typeof BroadcastChannel === 'undefined') return null;
  const channel = new BroadcastChannel(name);
  return {
    post: (data) => channel.postMessage(data),
    listen: (hear) => {
      channel.onmessage = (event) => hear(event.data);
    },
    close: () => channel.close(),
  };
};

export const CUSTOMER_DISPLAY_CHANNEL = new InjectionToken<DisplayChannelOpener>(
  'CUSTOMER_DISPLAY_CHANNEL',
  { providedIn: 'root', factory: () => broadcastChannelOpener },
);

/** The line a scan last went onto: its product, how many the line now holds, and one's price, taxes included. */
export interface DisplayItem {
  readonly name: string;
  readonly quantity: string;
  readonly unitPrice: string;
}

/** What the customer display shows: the last line scanned and, once the sale was saved, what it comes to. */
export interface DisplayState {
  readonly item: DisplayItem | null;
  readonly total: string | null;
  readonly currency: string;
}

type Message = { readonly kind: 'state'; readonly state: DisplayState } | { readonly kind: 'ask' };

function isMessage(data: unknown): data is Message {
  return (
    typeof data === 'object' &&
    data !== null &&
    ((data as Message).kind === 'ask' || (data as Message).kind === 'state')
  );
}

/**
 * The customer display (docs/SPEC.md § 7, 2026-09-23 slice 6): a second window of this browser, turned towards the
 * customer, showing what the sale on the counter's screen just scanned and, once saved, what it comes to. The two
 * windows speak over a BroadcastChannel named for the company, so nothing leaves the browser.
 *
 * A tab says nothing until a scan goes onto one of its sale's lines: browsing documents never shows a customer
 * another's total. Emptied, it stays quiet again until the next scan.
 */
@Injectable({ providedIn: 'root' })
export class CustomerDisplay {
  private readonly open = inject(CUSTOMER_DISPLAY_CHANNEL);
  private readonly session = inject(Session);
  private readonly document = inject(DOCUMENT);
  private sender: { readonly name: string; readonly channel: DisplayChannel | null } | null = null;
  private state: DisplayState | null = null;

  constructor() {
    // Closing or reloading the counter's tab destroys no screen, and would leave its last line facing the customer.
    this.document.defaultView?.addEventListener('pagehide', () => this.clear());
  }

  /** Shows the line a scan just went onto; what the sale came to before is no longer what it comes to. */
  show(item: DisplayItem): void {
    const currency = this.session.me()?.company?.currency;
    if (currency === undefined) return;
    this.send({ item, total: null, currency });
  }

  /** What the sale comes to as saved; said only by a tab whose scans the display is showing. */
  total(amount: string): void {
    if (this.state === null || this.state.item === null) return;
    this.send({ ...this.state, total: amount });
  }

  /** The sale left the screen: the display waits for the next, and this tab goes quiet. */
  clear(): void {
    if (this.state === null) return;
    const emptied: DisplayState = { item: null, total: null, currency: this.state.currency };
    this.send(emptied);
    this.state = null;
  }

  /** Opens the display in a window of its own, to drag onto the screen that faces the customer; again, it comes forward. */
  openWindow(): void {
    this.document.defaultView?.open('/customer-display', 'twes-customer-display', 'popup');
  }

  /**
   * What the display window shows, from what any tab of this browser sends for the company. It asks once on opening,
   * so a display opened in the middle of a sale shows it at once.
   */
  watch(destroyRef: DestroyRef): Signal<DisplayState | null> {
    const shown = signal<DisplayState | null>(null);
    const name = this.channelName();
    const channel = name === null ? null : this.open(name);
    if (channel === null) return shown.asReadonly();
    channel.listen((data) => {
      if (isMessage(data) && data.kind === 'state') shown.set(data.state);
    });
    destroyRef.onDestroy(() => channel.close());
    channel.post({ kind: 'ask' } satisfies Message);
    return shown.asReadonly();
  }

  private send(state: DisplayState): void {
    const channel = this.senderChannel();
    this.state = state;
    channel?.post({ kind: 'state', state } satisfies Message);
  }

  /** The company's channel, opened once; another company's replaces it. */
  private senderChannel(): DisplayChannel | null {
    const name = this.channelName();
    if (name === null) return null;
    if (this.sender?.name !== name) {
      this.sender?.channel?.close();
      const channel = this.open(name);
      channel?.listen((data) => {
        if (isMessage(data) && data.kind === 'ask' && this.state !== null) {
          channel.post({ kind: 'state', state: this.state } satisfies Message);
        }
      });
      this.sender = { name, channel };
    }
    return this.sender.channel;
  }

  private channelName(): string | null {
    const id = this.session.me()?.company?.id;
    return id === undefined ? null : `twes.customer-display.${id}`;
  }
}
