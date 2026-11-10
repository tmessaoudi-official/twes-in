// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * An answer the API gave that the reader has no words for: a code added later, a status nobody expected. It is never
 * said as a lost connection; every screen says it in one sentence naming the code, `feedback.unknown_refusal`.
 */
export interface UnknownRefusal {
  readonly unknown: string;
}

export const UNKNOWN_REFUSAL = 'feedback.unknown_refusal';

/** The parts a screen reads through another feature beside its own record, each said in a sentence of its own. */
export type UnreadPartName =
  'custom_fields' | 'article_defaults' | 'customer_groups' | 'stock_locations' | 'vendors';

/**
 * A part another feature reads for a screen (its custom fields, its stock locations) that the API refused: said as that
 * part not read, with the code it answered, never in the screen's own words, which speak of its own record (« Produit
 * introuvable »), nor as a lost connection.
 */
export interface UnreadPart {
  readonly unread: UnreadPartName;
  readonly code: string;
}

/** The texts the parts' sentences live in, opened on every screen that may say one. */
export const UNREAD_TEXTS = 'unread';

/** Another feature's refusal of a part: the network stays the network, any other answer names the part. */
export function unreadPart(
  part: UnreadPartName,
  code: string | UnknownRefusal,
): 'network' | UnreadPart {
  return code === 'network' ? 'network' : { unread: part, code: codeText(code) };
}

/**
 * The words a screen says a refusal in: its own `<prefix>.<code>`, the one sentence for an answer it does not know, or
 * the sentence of a part another feature could not read for it.
 */
export function refusalKey(
  prefix: string,
  code: string | UnknownRefusal | UnreadPart,
): { key: string; params: Record<string, string> } {
  if (typeof code === 'string') return { key: `${prefix}.${code}`, params: {} };
  return 'unread' in code
    ? { key: `unread.${code.unread}`, params: { code: code.code } }
    : { key: UNKNOWN_REFUSAL, params: { code: code.unknown } };
}

/** A refusal's code as an `Error`'s message, which is a string. */
export function codeText(code: string | UnknownRefusal | UnreadPart): string {
  if (typeof code === 'string') return code;
  return 'unread' in code ? code.code : code.unknown;
}
