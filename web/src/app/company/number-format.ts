// SPDX-License-Identifier: AGPL-3.0-or-later

/** The API's limit on a numbering format. */
export const NUMBER_FORMAT_MAX_LENGTH = 64;

const MAX_PADDING = 12;
const TOKEN = /(\{[^{}]*\})/;
const LITERAL = /^[A-Za-z0-9 _./-]*$/;

/**
 * The number a series would give a document issued on that day, or null for a format the API would refuse: the same
 * rules as the API's NumberFormat, so a person sees the result while typing. The API's own preview, in the
 * company's time zone, stays the reference once saved.
 */
export function renderNumber(
  format: string,
  nextNumber: number,
  date: Date,
  establishmentCode: string,
): string | null {
  if (format.trim() === '' || format.length > NUMBER_FORMAT_MAX_LENGTH) return null;
  if (!Number.isInteger(nextNumber) || nextNumber < 1) return null;

  let sequences = 0;
  let out = '';
  for (const part of format.split(TOKEN).filter((piece) => piece !== '')) {
    if (!part.startsWith('{')) {
      if (!LITERAL.test(part)) return null;
      out += part;
      continue;
    }
    const token = part.slice(1, -1);
    const year = String(date.getFullYear());
    const known: Record<string, string> = {
      YYYY: year,
      YY: year.slice(-2),
      MM: String(date.getMonth() + 1).padStart(2, '0'),
      EST: establishmentCode,
    };
    if (token in known) {
      out += known[token];
      continue;
    }
    const sequence = /^SEQ(?::([0-9]{1,2}))?$/.exec(token);
    if (sequence === null) return null;
    const padding = sequence[1] === undefined ? 1 : Number(sequence[1]);
    if (padding < 1 || padding > MAX_PADDING) return null;
    sequences += 1;
    out += String(nextNumber).padStart(padding, '0');
  }
  return sequences === 1 ? out : null;
}
