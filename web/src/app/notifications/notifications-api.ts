// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { NotificationItem, NotificationPage, RealtimeToken } from '../api/types.gen';
import type { InboxEntry, InboxPage } from './notifications-types';

/** The HTTP edge of the notification centre: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class NotificationsApi {
  private readonly http = inject(HttpClient);

  async list(): Promise<InboxPage> {
    const page = await firstValueFrom(this.http.get<NotificationPage>('/api/me/notifications'));
    return { items: page.items.map(toEntry), unread: page.unread };
  }

  async markRead(id: string): Promise<void> {
    await firstValueFrom(
      this.http.post<void>(`/api/me/notifications/${encodeURIComponent(id)}/read`, null),
    );
  }

  async markAllRead(): Promise<void> {
    await firstValueFrom(this.http.post<void>('/api/me/notifications/read-all', null));
  }

  /** A fresh connection token; its channels are whatever the session may hear right now. */
  async realtimeToken(): Promise<string> {
    return (await firstValueFrom(this.http.get<RealtimeToken>('/api/me/realtime-token'))).token;
  }
}

function toEntry(item: NotificationItem): InboxEntry {
  return {
    id: item.id,
    type: item.type,
    payload: item.payload,
    companyId: item.companyId,
    createdAt: item.createdAt,
    readAt: item.readAt,
  };
}
