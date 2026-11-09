// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * An answer the API gave that the reader has no words for: a code added later, a status nobody expected. It is never
 * said as a lost connection; every screen says it in one sentence naming the code, `feedback.unknown_refusal`.
 */
export interface UnknownRefusal {
  readonly unknown: string;
}

export const UNKNOWN_REFUSAL = 'feedback.unknown_refusal';

/** The words a screen says a refusal in: its own `<prefix>.<code>`, or the one sentence for an answer it does not know. */
export function refusalKey(
  prefix: string,
  code: string | UnknownRefusal,
): { key: string; params: Record<string, string> } {
  return typeof code === 'string'
    ? { key: `${prefix}.${code}`, params: {} }
    : { key: UNKNOWN_REFUSAL, params: { code: code.unknown } };
}

/** A refusal's code as an `Error`'s message, which is a string. */
export function codeText(code: string | UnknownRefusal): string {
  return typeof code === 'string' ? code : code.unknown;
}
