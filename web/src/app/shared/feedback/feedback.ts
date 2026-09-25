// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ActionKind } from '../actions/screen-action';

/**
 * The port every screen reports an outcome through: a short toast that says what happened (docs/SPEC.md § 7,
 * 2026-09-16, row 48). Keys are translation keys. What a person must act on — a refused field, a rule the domain
 * enforced — stays next to the form instead: a toast goes away, and an error must not.
 */
export abstract class Feedback {
  /**
   * Something was done: saved, created, removed. Announced politely, gone after a few seconds. An `action` offers the
   * one follow-up that makes sense at once, such as taking back a scan; the toast stays a little longer with it.
   */
  abstract success(key: string, params?: Record<string, unknown>, action?: FeedbackAction): void;

  /**
   * A consequential action was done: said like a success, with the word its confirmation used — Corrigeable,
   * Définitif — so what can be taken back reads the same before and after (docs/SPEC.md § 7, 2026-09-25 22:17).
   */
  abstract effect(key: string, params: Record<string, unknown>, kind: ActionKind): void;

  /**
   * Something another person or tab did to what is on this screen (docs/SPEC.md § 7, 2026-09-17): announced politely
   * and left a little longer, since nobody here was expecting it.
   */
  abstract notice(key: string, params?: Record<string, unknown>): void;

  /** Something could not be done and there is no form to say it beside. Announced at once, stays until closed. */
  abstract failure(key: string, params?: Record<string, unknown>): void;
}

/** A toast's one button: its label's translation key, and what it does. */
export interface FeedbackAction {
  readonly key: string;
  readonly run: () => void;
}
