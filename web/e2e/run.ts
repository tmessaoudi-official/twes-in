// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Six digits naming a scenario's own records in the shared database. Digits rather than letters: a list gives a column
 * way whole when its values do not fit, so a scenario reading a low column on a wide window must give the same width
 * every run, and digits draw alike where « MWQG » is wider than « ILJT ».
 */
export function runDigits(): string {
  return String(Date.now() % 1_000_000).padStart(6, '0');
}
