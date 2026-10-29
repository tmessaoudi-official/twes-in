// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ValidatorFn } from '@angular/forms';
import { typedText } from './typed-text';

/**
 * The one rule every e-mail address a person writes follows, new or changed, the API's `ContactRules::email`: the
 * WHATWG HTML standard's valid address, its domain carrying at least one dot, at most 254 characters. Angular's
 * `Validators.email` takes an address with no dot in its domain, which the API then refuses. Signing in and asking for a
 * new password take the address as it was given, so they keep their looser check.
 */
export const EMAIL_MAX = 254;
/** As `ContactRules::EMAIL_PATTERN` writes it; `email-vectors.json` holds both to one list of addresses. */
export const EMAIL_PATTERN =
  "[a-zA-Z0-9.!#$%&'*+\\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+";
const EMAIL = new RegExp(`^(?:${EMAIL_PATTERN})$`);

/** Whether the rule takes an address, judged without the spaces at its ends, as the API keeps it. */
export function emailTaken(value: string): boolean {
  const address = typedText(value);
  return address.length <= EMAIL_MAX && EMAIL.test(address);
}

/** The rule as a form's validator, reporting `email` as Angular's own does; an empty value is left to `required`. */
export const emailRule: ValidatorFn = (control) => {
  const value: unknown = control.value;
  if (typeof value !== 'string' || typedText(value) === '') return null;
  return emailTaken(value) ? null : { email: true };
};

/**
 * The mail domains people in Tunisia and France write most, against which a slip of a key is recognised. Data, never
 * a check: an address at another domain is as good as one at these.
 */
export const COMMON_MAIL_DOMAINS: readonly string[] = [
  'gmail.com',
  'yahoo.com',
  'yahoo.fr',
  'hotmail.com',
  'hotmail.fr',
  'outlook.com',
  'outlook.fr',
  'live.com',
  'live.fr',
  'icloud.com',
  'orange.fr',
  'free.fr',
  'sfr.fr',
  'laposte.net',
  'wanadoo.fr',
  'gmx.fr',
  'protonmail.com',
  'topnet.tn',
  'planet.tn',
  'gnet.tn',
];

/**
 * The address a slip of a key most likely meant, « ali@gmial.com » read as « ali@gmail.com », or null when the domain
 * is one of the common ones or near none of them. Offered beside the field, never written into it: no domain is looked
 * up, and the person may well mean the one they wrote.
 */
export function emailTypo(value: string): string | null {
  const address = typedText(value);
  const at = address.lastIndexOf('@');
  if (at <= 0 || !emailTaken(address)) return null;
  const domain = address.slice(at + 1).toLowerCase();
  if (COMMON_MAIL_DOMAINS.includes(domain)) return null;
  let best: string | null = null;
  let bestDistance = Infinity;
  for (const common of COMMON_MAIL_DOMAINS) {
    const distance = editDistance(domain, common);
    // One slip in a short name, two in a longer one: further than that is another domain, not a typo.
    if (distance <= (common.length > 8 ? 2 : 1) && distance < bestDistance) {
      best = common;
      bestDistance = distance;
    }
  }
  return best === null ? null : `${address.slice(0, at)}@${best}`;
}

/** Letters added, removed, changed or two side by side swapped (the optimal string alignment distance). */
function editDistance(a: string, b: string): number {
  const rows = Array.from({ length: a.length + 1 }, (_, i) =>
    Array.from({ length: b.length + 1 }, (_, j) => (i === 0 ? j : j === 0 ? i : 0)),
  );
  for (let i = 1; i <= a.length; i++) {
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      let distance = Math.min(rows[i - 1][j] + 1, rows[i][j - 1] + 1, rows[i - 1][j - 1] + cost);
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) {
        distance = Math.min(distance, rows[i - 2][j - 2] + 1);
      }
      rows[i][j] = distance;
    }
  }
  return rows[a.length][b.length];
}
