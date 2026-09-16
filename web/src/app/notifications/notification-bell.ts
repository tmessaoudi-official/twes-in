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
import { MatDialog, type MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { NotificationPanel } from './notification-panel';
import { NotificationsFacade } from './notifications-facade';
import { Label } from '../shared/a11y/label';

/**
 * The bell in the shell's toolbar and the centre it opens as a panel. It owns the realtime connection's lifetime:
 * open while the shell is on screen, reopened whenever the session moves to another company, closed when the shell
 * goes, taking an open panel with it.
 */
@Component({
  selector: 'app-notification-bell',
  imports: [Label, MatBadgeModule, MatButtonModule, MatIconModule, TranslatePipe],
  templateUrl: './notification-bell.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NotificationBell {
  private readonly facade = inject(NotificationsFacade);
  private readonly dialog = inject(MatDialog);
  private panel: MatDialogRef<NotificationPanel> | null = null;

  protected readonly unread = this.facade.unread;
  /** The count on the bell; the accessible name carries the exact number. */
  protected readonly badge = computed(() => (this.unread() > 9 ? '9+' : String(this.unread())));

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
    inject(DestroyRef).onDestroy(() => {
      this.panel?.close();
      this.facade.disconnect();
    });
  }

  protected open(): void {
    if (this.panel !== null) return;
    const panel = this.dialog.open(NotificationPanel, {
      position: { top: '0', right: '0' },
      height: '100dvh',
      width: 'min(26rem, 100vw)',
      maxWidth: '100vw',
      panelClass: 'notification-panel',
    });
    this.panel = panel;
    panel.afterClosed().subscribe(() => (this.panel = null));
  }
}
