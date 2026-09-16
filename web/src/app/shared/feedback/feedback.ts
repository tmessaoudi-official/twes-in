// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The port every screen reports an outcome through: a short toast that says what happened (docs/SPEC.md § 7,
 * 2026-09-16, row 48). Keys are translation keys. What a person must act on — a refused field, a rule the domain
 * enforced — stays next to the form instead: a toast goes away, and an error must not.
 */
export abstract class Feedback {
  /** Something was done: saved, created, removed. Announced politely, gone after a few seconds. */
  abstract success(key: string, params?: Record<string, unknown>): void;

  /** Something could not be done and there is no form to say it beside. Announced at once, stays until closed. */
  abstract failure(key: string, params?: Record<string, unknown>): void;
}
