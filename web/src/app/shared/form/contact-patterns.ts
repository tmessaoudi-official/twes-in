// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Every space a value may carry between its groups, the keyboard's and Unicode's others (`\p{Zs}`), as the API's
 * `ContactRules::SPACES` writes them: a figure copied from a hint, a refusal or a printed document keeps its groups
 * apart with a no-break one (« +216 71 900 868 »). Written out as the characters themselves, to sit inside a character
 * class of a pattern a form compiles with no flag.
 */
export const SPACES = ' \u00A0\u1680\u2000-\u200A\u202F\u205F\u3000';

/** A phone number, the API's `ContactRules::PHONE_PATTERN`. */
export const PHONE_PATTERN = `\\+?[0-9${SPACES}().\\-]{3,40}`;

/** An IBAN, or a bare Tunisian RIB (20 digits, its groups apart or not), which the API keeps as TN59 + RIB. */
export const IBAN_PATTERN = `(?:[A-Za-z]{2}[0-9]{2}[A-Za-z0-9${SPACES}]{8,38}|[0-9](?:[${SPACES}.\\-]?[0-9]){19})`;

/** A BIC, its groups apart or not, as a RIB prints it: the API drops the spaces before its own check. */
export const BIC_PATTERN = `[A-Za-z]{4}[${SPACES}]?[A-Za-z]{2}[${SPACES}]?[A-Za-z0-9]{2}([${SPACES}]?[A-Za-z0-9]{3})?`;
