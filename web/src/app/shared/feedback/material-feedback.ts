// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Feedback, type FeedbackAction } from './feedback';
import { Toast, type ToastData } from './toast';

export const SUCCESS_DURATION_MS = 4_000;
export const NOTICE_DURATION_MS = 6_000;
/** A success with a follow-up stays long enough to reach its button. */
export const ACTION_DURATION_MS = 8_000;

/**
 * Feedback as Material snack bars, one at a time, at the top of the window: the phone's navigation bar holds the
 * bottom. A success is announced politely and leaves; a failure is announced at once and stays until closed, since
 * nobody can be relied on to read it in four seconds.
 */
@Injectable({ providedIn: 'root' })
export class MaterialFeedback extends Feedback {
  private readonly snackBar = inject(MatSnackBar);

  success(key: string, params: Record<string, unknown> = {}, action?: FeedbackAction): void {
    this.snackBar.openFromComponent<Toast, ToastData>(Toast, {
      data:
        action === undefined
          ? { kind: 'success', key, params }
          : { kind: 'success', key, params, action },
      politeness: 'polite',
      duration: action === undefined ? SUCCESS_DURATION_MS : ACTION_DURATION_MS,
      verticalPosition: 'top',
      horizontalPosition: 'center',
      panelClass: ['twes-toast-panel', 'twes-toast-success'],
    });
  }

  notice(key: string, params: Record<string, unknown> = {}): void {
    this.snackBar.openFromComponent<Toast, ToastData>(Toast, {
      data: { kind: 'notice', key, params },
      politeness: 'polite',
      duration: NOTICE_DURATION_MS,
      verticalPosition: 'top',
      horizontalPosition: 'center',
      panelClass: ['twes-toast-panel', 'twes-toast-notice'],
    });
  }

  failure(key: string, params: Record<string, unknown> = {}): void {
    this.snackBar.openFromComponent<Toast, ToastData>(Toast, {
      data: { kind: 'failure', key, params },
      politeness: 'assertive',
      verticalPosition: 'top',
      horizontalPosition: 'center',
      panelClass: ['twes-toast-panel', 'twes-toast-failure'],
    });
  }
}
