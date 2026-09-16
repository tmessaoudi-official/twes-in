// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, DestroyRef, DOCUMENT, inject, Injectable, signal } from '@angular/core';
import { type Subscription, take } from 'rxjs';
import { HealthApi } from '../health/health-api';
import { Feedback } from './feedback';

/** A request answered sooner than this shows nothing, so a fast page never flickers. */
export const BUSY_AFTER_MS = 300;
/** A request still running after this says it is taking longer than usual. */
export const SLOW_AFTER_MS = 8_000;
/** How long to wait before each new health check while the API cannot be reached, the last one repeating. */
export const RETRY_DELAYS_S = [2, 4, 8, 16, 30] as const;

/** A status that means the API itself did not answer: no response at all, or a gateway answering for it. */
const UNREACHABLE = new Set([0, 502, 503, 504]);
/** A 401 here is a refused sign-in or nobody signed in yet, not a session that ended. */
const AUTH_PATHS = /^\/api\/auth\//;

/**
 * What the application is doing with the API, for the activity bar to show (docs/SPEC.md § 8 row 48): whether it
 * is working, whether that is slow, whether the API can be reached or the browser is offline, and whether the
 * session ended while the page was open. The activity interceptor reports every API request here.
 */
@Injectable({ providedIn: 'root' })
export class RequestActivity {
  private readonly health = inject(HealthApi);
  private readonly feedback = inject(Feedback);

  private inFlight = 0;
  private busyTimer: ReturnType<typeof setTimeout> | null = null;
  private slowTimer: ReturnType<typeof setTimeout> | null = null;
  private retryTimer: ReturnType<typeof setInterval> | null = null;
  private attempt = 0;
  private check: Subscription | null = null;

  private readonly busySignal = signal(false);
  private readonly slowSignal = signal(false);
  private readonly retryInSignal = signal<number | null>(null);
  private readonly offlineSignal = signal(false);
  private readonly sessionExpiredSignal = signal(false);

  readonly busy = this.busySignal.asReadonly();
  readonly slow = this.slowSignal.asReadonly();
  readonly unavailable = computed(() => this.retryInSignal() !== null);
  /** Seconds before the next health check while unavailable, null while the API answers. */
  readonly retryIn = this.retryInSignal.asReadonly();
  readonly offline = this.offlineSignal.asReadonly();
  readonly sessionExpired = this.sessionExpiredSignal.asReadonly();

  constructor() {
    const view = inject(DOCUMENT).defaultView;
    if (view !== null) {
      this.offlineSignal.set(view.navigator.onLine === false);
      const offline = () => this.offlineSignal.set(true);
      const online = () => {
        this.offlineSignal.set(false);
        if (this.unavailable()) this.retryNow();
      };
      view.addEventListener('offline', offline);
      view.addEventListener('online', online);
      inject(DestroyRef).onDestroy(() => {
        view.removeEventListener('offline', offline);
        view.removeEventListener('online', online);
        this.stopRetrying();
      });
    }
  }

  /** A request began; call what it returns when it ends, however it ends. */
  started(): () => void {
    this.inFlight++;
    if (this.inFlight === 1) {
      this.busyTimer = setTimeout(() => this.busySignal.set(true), BUSY_AFTER_MS);
      this.slowTimer = setTimeout(() => this.slowSignal.set(true), SLOW_AFTER_MS);
    }
    let ended = false;
    return () => {
      if (ended) return;
      ended = true;
      this.inFlight--;
      if (this.inFlight === 0) this.idle();
    };
  }

  /** An answer arrived from the API, so it can be reached. */
  reached(): void {
    if (this.unavailable()) this.recovered();
  }

  failed(status: number, url: string): void {
    if (UNREACHABLE.has(status)) {
      if (!this.unavailable()) {
        this.attempt = 0;
        this.waitForNextCheck();
      }
      return;
    }
    this.reached();
    if (status === 401 && !AUTH_PATHS.test(url)) this.sessionExpiredSignal.set(true);
  }

  retryNow(): void {
    this.clearRetryTimer();
    this.check?.unsubscribe();
    this.check = this.health
      .status()
      .pipe(take(1))
      .subscribe((status) => {
        if (status === 'ok') {
          this.recovered();
        } else {
          this.attempt = Math.min(this.attempt + 1, RETRY_DELAYS_S.length - 1);
          this.waitForNextCheck();
        }
      });
  }

  /** The shell has sent the person back to sign in. */
  acknowledgeExpiry(): void {
    this.sessionExpiredSignal.set(false);
  }

  private waitForNextCheck(): void {
    this.clearRetryTimer();
    this.retryInSignal.set(RETRY_DELAYS_S[this.attempt]);
    this.retryTimer = setInterval(() => {
      const left = (this.retryInSignal() ?? 1) - 1;
      if (left > 0) {
        this.retryInSignal.set(left);
      } else {
        this.retryNow();
      }
    }, 1_000);
  }

  private recovered(): void {
    this.stopRetrying();
    this.feedback.success('feedback.restored');
  }

  private stopRetrying(): void {
    this.clearRetryTimer();
    this.check?.unsubscribe();
    this.check = null;
    this.attempt = 0;
    this.retryInSignal.set(null);
  }

  private clearRetryTimer(): void {
    if (this.retryTimer !== null) clearInterval(this.retryTimer);
    this.retryTimer = null;
  }

  private idle(): void {
    if (this.busyTimer !== null) clearTimeout(this.busyTimer);
    if (this.slowTimer !== null) clearTimeout(this.slowTimer);
    this.busyTimer = this.slowTimer = null;
    this.busySignal.set(false);
    this.slowSignal.set(false);
  }
}
