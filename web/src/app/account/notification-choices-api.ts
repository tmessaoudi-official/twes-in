// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  NotificationPreference,
  NotificationPreferenceChange,
  NotificationPreferences,
} from '../api/types.gen';

/** One kind of notification a person is told, in one company or about their account, and how they chose it. */
export interface NotificationChoice {
  /** null for what is about the account rather than a company */
  readonly companyId: string | null;
  readonly companyName: string | null;
  /** a dotted code such as "stock.low" */
  readonly type: string;
  /** whether the bell counts it */
  readonly bell: boolean;
  readonly email: boolean;
  /** false for a kind that has a mail of its own, so has no e-mail switch */
  readonly mailed: boolean;
}

/** The HTTP edge of « Mon compte › Notifications »: the only code here that knows the endpoint and its types. */
@Injectable({ providedIn: 'root' })
export class NotificationChoicesApi {
  private readonly http = inject(HttpClient);

  /** Company by company in the switcher's order, then what is about the account. */
  async list(): Promise<NotificationChoice[]> {
    const answer = await firstValueFrom(
      this.http.get<NotificationPreferences>('/api/me/notification-preferences'),
    );
    return answer.preferences.map(toChoice);
  }

  async change(choice: NotificationChoice): Promise<void> {
    const body: NotificationPreferenceChange = {
      companyId: choice.companyId,
      type: choice.type,
      bell: choice.bell,
      email: choice.email,
    };
    await firstValueFrom(this.http.put<void>('/api/me/notification-preferences', body));
  }
}

function toChoice(preference: NotificationPreference): NotificationChoice {
  return {
    companyId: preference.companyId,
    companyName: preference.companyName,
    type: preference.type,
    bell: preference.bell,
    email: preference.email,
    mailed: preference.mailed,
  };
}
