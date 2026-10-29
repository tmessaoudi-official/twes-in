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
import {
  type InboxEntry,
  notificationRecord,
  type NotificationRecord,
  sentenceKey,
} from './notifications-types';
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
  /** The last read or mark failed: what shows is the list read before, or nothing. */
  protected readonly failed = this.facade.error;
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
          textKey: sentenceKey(entry, record),
          params: this.params(entry, record),
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
   * The words' parameters: the payload as the API sent it, each amount written as the screen writes amounts (at the
   * payload's `scale`, or as sent when an older one has none), each quantity without the zeros that say nothing and
   * followed by its unit when the payload names one (an older notice names none), each
   * moment and day in the screen's order of a day, and, when it names something by its translation key (`label_key`, such as
   * the module that arrived), that thing's name in the screen's language as `label`. A value that is not what it should
   * be is written as it came.
   */
  private params(entry: InboxEntry, record: NotificationRecord): Record<string, unknown> {
    const scale = entry.payload['scale'];
    const params: Record<string, unknown> = { ...entry.payload };
    const write = (keys: readonly string[] | undefined, as: (value: string) => string) => {
      for (const key of keys ?? []) {
        const value = entry.payload[key];
        if (typeof value === 'string') {
          params[key] = as(value);
        }
      }
    };
    write(record.amounts, (value) =>
      this.format.amount(value, typeof scale === 'number' ? scale : null),
    );
    const unit = record.unit === undefined ? undefined : entry.payload[record.unit];
    write(record.quantities, (value) =>
      `${this.format.amount(value.includes('.') ? value.replace(/\.?0+$/, '') : value, null)} ${typeof unit === 'string' ? unit : ''}`.trim(),
    );
    write(record.moments, (value) => {
      const [, day, time] = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/.exec(value) ?? [];
      return day === undefined ? value : `${this.format.day(day)} ${time}`;
    });
    write(record.days, (value) =>
      /^\d{4}-\d{2}-\d{2}$/.test(value) ? this.format.day(value) : value,
    );
    const labelKey = entry.payload['label_key'];
    if (typeof labelKey === 'string') {
      params['label'] = this.translate.instant(labelKey) as string;
    }
    return params;
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
