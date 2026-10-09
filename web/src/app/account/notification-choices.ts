// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import { type MatSlideToggleChange, MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslatePipe } from '@ngx-translate/core';
import { NotificationsFacade } from '../notifications/notifications-facade';
import { notificationKindKey } from '../notifications/notifications-types';
import { Feedback } from '../shared/feedback/feedback';
import { type NotificationChoice, NotificationChoicesApi } from './notification-choices-api';

/** The kinds told in one company, or about the account when `companyId` is null. */
interface ChoiceGroup {
  readonly companyId: string | null;
  readonly companyName: string | null;
  readonly choices: readonly NotificationChoice[];
}

/**
 * « Mon compte › Notifications »: for each company, and for what is about the account, what the bell counts. A
 * muted kind is still listed in the centre, only no longer counted, so the switch is kept as soon as it moves, as the
 * other preferences of this page are, and put back when the API did not keep it.
 */
@Component({
  selector: 'app-notification-choices',
  imports: [MatSlideToggleModule, TranslatePipe],
  templateUrl: './notification-choices.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NotificationChoices implements OnInit {
  private readonly api = inject(NotificationChoicesApi);
  private readonly bell = inject(NotificationsFacade);
  private readonly feedback = inject(Feedback);

  private readonly choices = signal<NotificationChoice[] | null>(null);
  protected readonly unreachable = signal(false);
  protected readonly kindKey = notificationKindKey;

  /** In the order the API answered: the companies as the switcher lists them, then the account. */
  protected readonly groups = computed<ChoiceGroup[]>(() => {
    const groups: ChoiceGroup[] = [];
    for (const choice of this.choices() ?? []) {
      const last = groups.at(-1);
      if (last !== undefined && last.companyId === choice.companyId) {
        groups[groups.length - 1] = { ...last, choices: [...last.choices, choice] };
      } else {
        groups.push({
          companyId: choice.companyId,
          companyName: choice.companyName,
          choices: [choice],
        });
      }
    }
    return groups;
  });

  ngOnInit(): void {
    void this.load();
  }

  protected testId(choice: NotificationChoice): string {
    return `notification-${choice.companyId ?? 'account'}-${choice.type}`;
  }

  protected async ring(choice: NotificationChoice, event: MatSlideToggleChange): Promise<void> {
    const changed = { ...choice, bell: event.checked };
    this.replace(choice, changed);
    try {
      await this.api.change(changed);
    } catch {
      this.replace(changed, choice);
      // The binding reads the same value it last drew, so it would not move the switch back by itself.
      event.source.checked = choice.bell;
      this.feedback.failure('account.notifications.failed');
      return;
    }
    // The bell counts on the API's side, and this tab hears no live change it caused itself.
    void this.bell.refresh();
  }

  private replace(before: NotificationChoice, after: NotificationChoice): void {
    this.choices.update((choices) =>
      (choices ?? []).map((each) =>
        each.companyId === before.companyId && each.type === before.type ? after : each,
      ),
    );
  }

  private async load(): Promise<void> {
    try {
      this.choices.set(await this.api.list());
      this.unreachable.set(false);
    } catch {
      this.unreachable.set(true);
    }
  }
}
