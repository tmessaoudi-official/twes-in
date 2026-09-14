// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  untracked,
} from '@angular/core';
import { MatBadgeModule } from '@angular/material/badge';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { MomentPipe } from '../shared/i18n/format-pipes';
import { NotificationsFacade } from './notifications-facade';
import { type InboxEntry, notificationKey } from './notifications-types';

/**
 * The bell in the shell's toolbar and the centre it opens. It owns the realtime connection's lifetime: open while
 * the shell is on screen, reopened whenever the session moves to another company, closed when the shell goes.
 */
@Component({
  selector: 'app-notification-bell',
  imports: [
    MatBadgeModule,
    MatButtonModule,
    MatIconModule,
    MatMenuModule,
    MomentPipe,
    TranslatePipe,
  ],
  templateUrl: './notification-bell.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NotificationBell {
  private readonly facade = inject(NotificationsFacade);

  protected readonly items = this.facade.items;
  protected readonly unread = this.facade.unread;
  protected readonly keyOf = notificationKey;

  constructor() {
    const auth = inject(AuthFacade);
    const companyId = computed(() => auth.me()?.company?.id ?? null);
    effect(() => {
      companyId();
      untracked(() => {
        this.facade.connect();
        void this.facade.refresh();
      });
    });
    inject(DestroyRef).onDestroy(() => this.facade.disconnect());
  }

  protected read(entry: InboxEntry): void {
    if (entry.readAt === null) {
      void this.facade.markRead(entry.id);
    }
  }

  protected readAll(): void {
    void this.facade.markAllRead();
  }
}
