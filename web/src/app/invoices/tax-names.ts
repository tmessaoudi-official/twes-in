// SPDX-License-Identifier: AGPL-3.0-or-later

/** As much of one of the company's taxes as naming it takes. */
export interface NamedTax {
  readonly code: string;
  readonly name: string;
  readonly rate: string | null;
}

/**
 * The name a document's total shows for a tax: the name the company gave it, which already says its rate, while it
 * still has that tax at that rate; null otherwise, and the screen falls back to the code and the rate the document
 * froze, which stay true (audit 2026-10-06, V-31). The code itself belongs to the tax settings.
 */
export function taxNames(
  taxes: readonly NamedTax[],
): (code: string, rate: string | null) => string | null {
  const byCode = new Map(taxes.map((tax) => [tax.code, tax]));
  return (code, rate) => {
    const tax = byCode.get(code);
    if (tax === undefined) return null;
    if (rate !== null && tax.rate !== null && Number(rate) !== Number(tax.rate)) return null;
    return tax.name;
  };
}
