// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { type StopMailError, StopMailApi, StopMailRefused } from './stop-mail-api';

/**
 * The page a notification mail's stop link opens, signed out. Opening it changes nothing, since mail clients and link
 * scanners open links on their own: its button turns that one kind's mail off, and the page then says where it is
 * turned back on.
 */
@Component({
  selector: 'app-stop-mail-page',
  imports: [SignedOutLayout, RouterLink, MatButtonModule, TranslatePipe],
  templateUrl: './stop-mail-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StopMailPage {
  /** Bound from the route parameter by withComponentInputBinding(). */
  readonly token = input('');

  private readonly api = inject(StopMailApi);

  protected readonly busy = signal(false);
  protected readonly done = signal(false);
  protected readonly error = signal<StopMailError | null>(null);

  protected async stop(): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set(null);
    try {
      await this.api.stop(this.token());
      this.done.set(true);
    } catch (refused) {
      this.error.set(refused instanceof StopMailRefused ? refused.code : 'network');
    } finally {
      this.busy.set(false);
    }
  }
}
