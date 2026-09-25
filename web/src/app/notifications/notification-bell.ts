// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
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
 * The bell and the centre it opens as a panel: a row at the foot of the rail, or an icon in a phone's bar. It owns the realtime connection's lifetime:
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

  /** A row of the rail, or an icon in a bar. */
  readonly variant = input<'icon' | 'row'>('icon');
  /** The rail folded to icons: the row keeps its icon, its count on the icon and its name in a tooltip. */
  readonly folded = input(false);

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
    // It slides over the side it was opened from: the rail's row sits at the start of the window.
    const panel = this.dialog.open(NotificationPanel, {
      position: this.variant() === 'row' ? { top: '0', left: '0' } : { top: '0', right: '0' },
      height: '100dvh',
      width: 'min(26rem, 100vw)',
      maxWidth: '100vw',
      panelClass: 'notification-panel',
    });
    this.panel = panel;
    panel.afterClosed().subscribe(() => (this.panel = null));
  }
}
