// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  computed,
  effect,
  inject,
  Injectable,
  type Signal,
  signal,
  untracked,
} from '@angular/core';
import type { AbstractControl, ValidatorFn } from '@angular/forms';
import { PasswordRuleApi, type PasswordLengths } from './password-rule-api';

export type { PasswordLengths };

/**
 * The length a new password must have, read once from the API for every page choosing one (signing up, accepting an
 * invitation, a reset by mail, « Mon compte »), so a password the API would refuse is said under its field as it is
 * typed, in the API's own numbers, never written into a screen or a text.
 */
@Injectable({ providedIn: 'root' })
export class PasswordRule {
  private readonly api = inject(PasswordRuleApi);
  private readonly lengthsSignal = signal<PasswordLengths | null>(null);
  private asked: Promise<void> | null = null;

  /** The lengths once read; null before, and while the API cannot be reached, when sending says what is wrong. */
  readonly lengths = this.lengthsSignal.asReadonly();

  /**
   * Reads the rule, once for the whole visit. An API out of reach leaves it unread, to be asked again by the next page
   * or attempt: whatever is sent meanwhile is refused by the API itself, in the same words.
   */
  read(): Promise<void> {
    this.asked ??= this.api.read().then(
      (lengths) => this.lengthsSignal.set(lengths),
      () => {
        this.asked = null;
      },
    );
    return this.asked;
  }

  /**
   * Holds a control to the shortest length as read, counted in characters as the API counts them, under Angular's own
   * key (`minlength`); nothing until the rule is read. The longest, thousands of characters, is left to the API.
   */
  readonly validator: ValidatorFn = (control) => {
    const lengths = this.lengthsSignal();
    const value: unknown = control.value;
    if (lengths === null || typeof value !== 'string' || value === '') return null;
    const length = [...value].length;
    return length < lengths.minLength
      ? { minlength: { requiredLength: lengths.minLength, actualLength: length } }
      : null;
  };
}

/**
 * Holds a page's new-password control to the rule, judged again the moment it is read, and answers the shortest
 * length for the hint and the refusal to say, null until read. Called where a page is built (its field initializers).
 */
export function heldToPasswordRule(control: AbstractControl): Signal<number | null> {
  const rule = inject(PasswordRule);
  control.addValidators(rule.validator);
  void rule.read();
  effect(() => {
    rule.lengths();
    untracked(() => control.updateValueAndValidity());
  });
  return computed(() => rule.lengths()?.minLength ?? null);
}

/** Whether a password typed is shorter than the rule as read, counted in characters; never before it is read. */
export function shorterThanRule(typed: string, min: number | null): boolean {
  return min !== null && typed !== '' && [...typed].length < min;
}
