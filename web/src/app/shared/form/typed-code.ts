// SPDX-License-Identifier: AGPL-3.0-or-later

/** Every space and every invisible format character, wherever it stands: what `typedText` takes only at the ends. */
const SPACES = /[\s\0\p{Z}\p{Cf}]/gu;

/**
 * A code a person types or pastes, an authenticator's six digits or a recovery code, as the API reads it: an app shows
 * its code in two groups (« 123 456 »), a copy brings a no-break space or a direction mark, and none of them is part of
 * the code. Every field asking a code sends it through this, so no field refuses one for a character nobody can see.
 */
export function typedCode(value: string): string {
  return value.replace(SPACES, '');
}
