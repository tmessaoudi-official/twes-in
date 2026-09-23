// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, inject, Injectable, type Signal, signal } from '@angular/core';
import { Feedback } from '../feedback/feedback';

/** Where a code came from: a scanner typing it, this device's camera, or a phone paired with this tab. */
export type ScanSource = 'wedge' | 'camera' | 'phone';

/** One code read, and how many times it counts: "5×" typed before it makes five. */
export interface Scan {
  readonly code: string;
  readonly source: ScanSource;
  readonly times: number;
}

/** What the screen on view made of a scan. */
export type ScanOutcome =
  | {
      readonly kind: 'done';
      readonly key: string;
      readonly params?: Record<string, unknown>;
      /** Takes back what the scan did; absent when nothing can be. */
      readonly undo?: () => void;
    }
  | { readonly kind: 'refused'; readonly key: string; readonly params?: Record<string, unknown> }
  /** Not something this screen acts on: the generic card takes it. */
  | { readonly kind: 'unclaimed' };

export type ScanHandler = (scan: Scan) => Promise<ScanOutcome>;

/** A scan as the log keeps it. */
export interface ScanLogEntry extends Scan {
  readonly outcome: ScanOutcome['kind'];
  readonly key: string | null;
  readonly undone: boolean;
}

/** How many scans the log keeps. */
export const SCAN_LOG_SIZE = 20;

interface Standing {
  readonly entry: ScanLogEntry;
  readonly undo: () => void;
}

/**
 * Where every scan goes, whatever read it (docs/SPEC.md § 7, 2026-09-23 09:30): to the screen on view when it acts on
 * scans — an invoice adds a line — and otherwise to the card of what the code names. Scans are worked through one at
 * a time, in the order they came, so a till's quick succession of the same code counts every one on the same line.
 */
@Injectable({ providedIn: 'root' })
export class ScanBus {
  private readonly feedback = inject(Feedback);
  private readonly handlers: ScanHandler[] = [];
  private unclaimed: ((scan: Scan) => void) | null = null;
  private queue: Promise<unknown> = Promise.resolve();
  private readonly standing: Standing[] = [];
  private readonly entries = signal<readonly ScanLogEntry[]>([]);

  /** The count typed before the next scan ("5×"); it applies to that scan alone. */
  readonly multiplier = signal<number | null>(null);

  /** The latest scans, newest first. */
  readonly log: Signal<readonly ScanLogEntry[]> = this.entries.asReadonly();

  /**
   * The screen's own reading of a scan, for as long as the screen lives; the screen declared last is the one on
   * view. Must be called from an injection context.
   */
  handle(handler: ScanHandler): void {
    this.handlers.push(handler);
    inject(DestroyRef).onDestroy(() => {
      const at = this.handlers.indexOf(handler);
      if (at !== -1) this.handlers.splice(at, 1);
    });
  }

  /** What takes a scan no screen claims: the shell's product card. Must be called from an injection context. */
  fallback(open: (scan: Scan) => void): void {
    this.unclaimed = open;
    inject(DestroyRef).onDestroy(() => {
      if (this.unclaimed === open) this.unclaimed = null;
    });
  }

  /** A code read by any source, worked through after the scans before it. */
  receive(code: string, source: ScanSource): Promise<ScanOutcome> {
    const scan: Scan = { code, source, times: this.multiplier() ?? 1 };
    this.multiplier.set(null);
    const run = this.queue.then(() => this.work(scan));
    this.queue = run.catch(() => undefined);
    return run;
  }

  /** Takes back the latest scan that can still be taken back; false when none can. */
  undoLast(): boolean {
    const last = this.standing.pop();
    if (last === undefined) return false;
    this.undo(last);
    return true;
  }

  private async work(scan: Scan): Promise<ScanOutcome> {
    const handler = this.handlers.at(-1);
    let outcome: ScanOutcome;
    try {
      outcome = handler === undefined ? { kind: 'unclaimed' } : await handler(scan);
    } catch {
      outcome = { kind: 'refused', key: 'scan.failed' };
    }

    const entry: ScanLogEntry = {
      ...scan,
      outcome: outcome.kind,
      key: outcome.kind === 'unclaimed' ? null : outcome.key,
      undone: false,
    };
    this.entries.update((entries) => [entry, ...entries].slice(0, SCAN_LOG_SIZE));

    if (outcome.kind === 'unclaimed') {
      this.unclaimed?.(scan);
    } else if (outcome.kind === 'refused') {
      this.feedback.failure(outcome.key, outcome.params);
    } else if (outcome.undo === undefined) {
      this.feedback.success(outcome.key, outcome.params);
    } else {
      const standing: Standing = { entry, undo: outcome.undo };
      this.standing.push(standing);
      this.feedback.success(outcome.key, outcome.params, {
        key: 'scan.undo',
        run: () => {
          const at = this.standing.indexOf(standing);
          if (at === -1) return;
          this.standing.splice(at, 1);
          this.undo(standing);
        },
      });
    }
    return outcome;
  }

  private undo(standing: Standing): void {
    standing.undo();
    this.entries.update((entries) =>
      entries.map((each) => (each === standing.entry ? { ...each, undone: true } : each)),
    );
    this.feedback.success('scan.undone', { code: standing.entry.code });
  }
}
