// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Provider } from '@angular/core';
import { type PasswordLengths, PasswordRuleApi } from '../password/password-rule-api';

/** A page choosing a password, given the rule the API would answer without an HTTP backend. */
export function provideKnownPasswordRule(
  lengths: PasswordLengths = { minLength: 12, maxLength: 4096 },
): Provider {
  return { provide: PasswordRuleApi, useValue: { read: () => Promise.resolve(lengths) } };
}
