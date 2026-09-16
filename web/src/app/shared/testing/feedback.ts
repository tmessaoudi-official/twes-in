// SPDX-License-Identifier: AGPL-3.0-or-later

import { type Provider, signal } from '@angular/core';
import { Feedback } from '../feedback/feedback';
import { RequestActivity } from '../feedback/request-activity';

/** A Feedback a spec can read back: every toast it was asked for, in order. */
export class RecordedFeedback extends Feedback {
  readonly said: { kind: 'success' | 'failure'; key: string; params?: Record<string, unknown> }[] =
    [];

  success(key: string, params?: Record<string, unknown>): void {
    this.said.push({ kind: 'success', key, params });
  }

  failure(key: string, params?: Record<string, unknown>): void {
    this.said.push({ kind: 'failure', key, params });
  }
}

/**
 * For a spec rendering a page that carries the activity bar (the shell, every signed-out page) or reports an
 * outcome: nothing is waited for, and toasts are recorded instead of shown. Inject `Feedback` as `RecordedFeedback`
 * to read them.
 */
export function provideQuietFeedback(): Provider[] {
  return [
    { provide: Feedback, useClass: RecordedFeedback },
    {
      provide: RequestActivity,
      useValue: {
        busy: signal(false),
        slow: signal(false),
        unavailable: signal(false),
        retryIn: signal(null),
        offline: signal(false),
        sessionExpired: signal(false),
        retryNow: () => undefined,
        acknowledgeExpiry: () => undefined,
      },
    },
  ];
}
