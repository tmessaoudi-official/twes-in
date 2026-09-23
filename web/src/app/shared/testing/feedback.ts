// SPDX-License-Identifier: AGPL-3.0-or-later

import { type Provider, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Feedback, type FeedbackAction } from '../feedback/feedback';
import { RequestActivity } from '../feedback/request-activity';

/** A Feedback a spec can read back: every toast it was asked for, in order. */
export class RecordedFeedback extends Feedback {
  readonly said: {
    kind: 'success' | 'notice' | 'failure';
    key: string;
    params?: Record<string, unknown>;
    action?: FeedbackAction;
  }[] = [];

  success(key: string, params?: Record<string, unknown>, action?: FeedbackAction): void {
    this.said.push({ kind: 'success', key, params, ...(action === undefined ? {} : { action }) });
  }

  notice(key: string, params?: Record<string, unknown>): void {
    this.said.push({ kind: 'notice', key, params });
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
        quiet: () => false,
        quietly: <T>(work: () => Promise<T>) => work(),
      },
    },
  ];
}

/** The keys of the success toasts a spec's page has asked for so far, in order (needs `provideQuietFeedback()`). */
export function successToasts(): string[] {
  return (TestBed.inject(Feedback) as RecordedFeedback).said
    .filter((toast) => toast.kind === 'success')
    .map((toast) => toast.key);
}
