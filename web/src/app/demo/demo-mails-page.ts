// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { MomentPipe } from '../shared/i18n/format-pipes';
import { LiveChanges } from '../shared/realtime/live-changes';
import { DEMO_MAIL_KIND, DemoMailsApi, type DemoMail } from './demo-mails-api';

/**
 * « Courriers de la démo »: what the application would have mailed the visitor, kept instead, so a demo shows its
 * reminders and notices without a mail leaving. The latest first, one opened at a time. Each mail is our own rendered
 * HTML, still bound through Angular's sanitizer, so nothing in it runs.
 */
@Component({
  selector: 'app-demo-mails-page',
  imports: [MatIconModule, MomentPipe, TranslatePipe],
  templateUrl: './demo-mails-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DemoMailsPage implements OnInit {
  private readonly api = inject(DemoMailsApi);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  /** Null until the first read answers. */
  protected readonly mails = signal<readonly DemoMail[] | null>(null);
  protected readonly failed = signal(false);
  private readonly openedId = signal<string | null>(null);
  /** The mail picked, else the latest: a mail that arrives while one is read leaves that one open. */
  protected readonly opened = computed(() => {
    const mails = this.mails() ?? [];
    return mails.find((mail) => mail.id === this.openedId()) ?? mails[0] ?? null;
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn([DEMO_MAIL_KIND], () => this.load(companyId), this.destroyRef);
    await this.load(companyId);
  }

  protected open(mail: DemoMail): void {
    this.openedId.set(mail.id);
  }

  private async load(companyId: string): Promise<void> {
    try {
      this.mails.set(await this.api.list(companyId));
      this.failed.set(false);
    } catch {
      this.failed.set(true);
    }
  }
}
