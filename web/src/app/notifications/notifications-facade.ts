// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/common';
import { inject, Injectable, signal } from '@angular/core';
import { NotificationsApi } from './notifications-api';
import type { InboxEntry } from './notifications-types';
import { REALTIME_CONNECTOR, type RealtimeConnection } from './realtime-connector';

/**
 * The notification centre as signals. The API's rows are the truth (docs/SPEC.md § 7, 2026-09-13): a publication
 * on the realtime connection carries no state here, it only says "read the centre again".
 */
@Injectable({ providedIn: 'root' })
export class NotificationsFacade {
  private readonly api = inject(NotificationsApi);
  private readonly connector = inject(REALTIME_CONNECTOR);
  private readonly document = inject(DOCUMENT);
  private readonly itemsSignal = signal<readonly InboxEntry[]>([]);
  private readonly unreadSignal = signal(0);
  private readonly errorSignal = signal(false);
  private connection: RealtimeConnection | null = null;

  readonly items = this.itemsSignal.asReadonly();
  readonly unread = this.unreadSignal.asReadonly();
  /** The last request failed; what is shown is the last list that loaded. */
  readonly error = this.errorSignal.asReadonly();

  async refresh(): Promise<void> {
    try {
      const page = await this.api.list();
      this.itemsSignal.set(page.items);
      this.unreadSignal.set(page.unread);
      this.errorSignal.set(false);
    } catch {
      this.errorSignal.set(true);
    }
  }

  /** Opens the connection, closing any previous one: its token named the previous company's channel. */
  connect(): void {
    this.disconnect();
    const { protocol, host } = this.document.location;
    const scheme = protocol === 'https:' ? 'wss' : 'ws';
    this.connection = this.connector(
      `${scheme}://${host}/connection/websocket`,
      () => this.api.realtimeToken(),
      () => void this.refresh(),
    );
  }

  disconnect(): void {
    this.connection?.disconnect();
    this.connection = null;
  }

  async markRead(id: string): Promise<void> {
    await this.guarded(() => this.api.markRead(id));
  }

  async markAllRead(): Promise<void> {
    await this.guarded(() => this.api.markAllRead());
  }

  private async guarded(call: () => Promise<void>): Promise<void> {
    try {
      await call();
    } catch {
      this.errorSignal.set(true);
      return;
    }
    await this.refresh();
  }
}
