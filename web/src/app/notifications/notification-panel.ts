// SPDX-License-Identifier: AGPL-3.0-or-later

import { NgTemplateOutlet } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';
import { groupByDay, relativeTime } from './notification-days';
import { NotificationsFacade } from './notifications-facade';
import { type InboxEntry, notificationKey, notificationRecord } from './notifications-types';
import { Label } from '../shared/a11y/label';

type Filter = 'all' | 'unread';

/**
 * The notification centre, opened by the bell as a panel against the right edge: the entries grouped by the company's
 * day, each with its icon, its words, how long ago and a link to its record where there is one, all or only unread.
 */
@Component({
  selector: 'app-notification-panel',
  imports: [
    Label,
    MatButtonModule,
    MatDialogModule,
    MatIconModule,
    NgTemplateOutlet,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './notification-panel.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'flex h-full flex-col' },
})
export class NotificationPanel {
  private readonly facade = inject(NotificationsFacade);
  private readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);
  private readonly translate = inject(TranslateService);
  private readonly panel = inject<MatDialogRef<NotificationPanel>>(MatDialogRef);

  protected readonly unread = this.facade.unread;
  protected readonly filters: readonly Filter[] = ['all', 'unread'];
  protected readonly filter = signal<Filter>('all');
  /** Moves once a minute, so "il y a 5 minutes" keeps up while the panel stays open. */
  private readonly now = signal(new Date());

  protected readonly groups = computed(() => {
    const now = this.now();
    const locale = this.format.locale();
    const timeZone =
      this.auth.me()?.company?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;
    const entries =
      this.filter() === 'unread'
        ? this.facade.items().filter((entry) => entry.readAt === null)
        : this.facade.items();
    return groupByDay(entries, timeZone, now).map((group) => ({
      key: group.key,
      labelKey: group.when === 'earlier' ? null : `notifications.${group.when}`,
      day: this.format.day(group.key),
      rows: group.entries.map((entry) => {
        const record = notificationRecord(entry.type);
        const allowed = record.permission === null || this.auth.hasPermission(record.permission);
        return {
          entry,
          icon: record.icon,
          textKey: notificationKey(entry.type),
          params: this.params(entry),
          route: allowed ? record.route : null,
          ago: relativeTime(entry.createdAt, now, locale),
          at: this.format.moment(entry.createdAt, timeZone),
        };
      }),
    }));
  });

  constructor() {
    const timer = setInterval(() => this.now.set(new Date()), 60_000);
    inject(DestroyRef).onDestroy(() => clearInterval(timer));
  }

  /**
   * The words' parameters: the payload as the API sent it, and, when it names something by its translation key
   * (`label_key`, such as the module that arrived), that thing's name in the screen's language as `label`.
   */
  private params(entry: InboxEntry): Record<string, unknown> {
    const labelKey = entry.payload['label_key'];
    return typeof labelKey === 'string'
      ? { ...entry.payload, label: this.translate.instant(labelKey) as string }
      : entry.payload;
  }

  protected read(entry: InboxEntry): void {
    if (entry.readAt === null) {
      void this.facade.markRead(entry.id);
    }
  }

  /** Following an entry to its record reads it and gets the panel out of the way. */
  protected follow(entry: InboxEntry): void {
    this.read(entry);
    this.panel.close();
  }

  protected readAll(): void {
    void this.facade.markAllRead();
  }
}
