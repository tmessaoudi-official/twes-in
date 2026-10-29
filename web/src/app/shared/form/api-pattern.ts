// SPDX-License-Identifier: AGPL-3.0-or-later

/** The flags a browser's pattern keeps the meaning of: `u`, characters as PCRE reads them, and `D`, `$` at the very end only. */
const HONOURED: ReadonlySet<string> = new Set(['u', 'D']);
/** PCRE's bracket delimiters close on their pair; any other delimiter closes on itself. */
const CLOSING: Readonly<Record<string, string>> = { '(': ')', '[': ']', '{': '}', '<': '>' };

/**
 * A pattern the API sends written as PHP writes one (`/^[A-Z]{3}$/`, `#^https://…$#`, `/^(…)?$/D`) as a form's field
 * takes it (`[A-Z]{3}`): the form anchors a string pattern at both ends itself. Throws on a pattern it cannot read, or
 * on a flag a browser's pattern cannot honour (`i`, `x`, `m`, `s`…): dropped, the field would refuse or take other
 * values than the API, which is what every API pattern is run through `api-patterns.json` to prevent.
 */
export function browserPattern(sent: string): string {
  const open = sent.charAt(0);
  if (open === '' || /[\p{L}\p{N}\s\\]/u.test(open)) {
    throw new Error(`The pattern ${sent} has no delimiter a form can read.`);
  }
  const end = sent.lastIndexOf(CLOSING[open] ?? open);
  const flags = end > 0 ? sent.slice(end + 1) : '';
  if (end <= 0 || !/^[a-zA-Z]*$/.test(flags)) {
    throw new Error(`The pattern ${sent} does not close on its delimiter ${open}.`);
  }
  const unhonoured = [...flags].filter((flag) => !HONOURED.has(flag));
  if (unhonoured.length > 0) {
    throw new Error(
      `The pattern ${sent} carries ${unhonoured.join('')}, which a form's pattern cannot honour.`,
    );
  }
  return unanchored(sent.slice(1, end));
}

/** The body without the anchors the form adds again: a `$` escaped (`\$`) is a dollar sign, and stays. */
function unanchored(body: string): string {
  const start = body.startsWith('^') ? 1 : 0;
  const end =
    body.endsWith('$') && !escapedAt(body, body.length - 1) ? body.length - 1 : body.length;
  return body.slice(start, Math.max(start, end));
}

function escapedAt(text: string, at: number): boolean {
  let backslashes = 0;
  for (let index = at - 1; index >= 0 && text.charAt(index) === '\\'; index -= 1) backslashes += 1;
  return backslashes % 2 === 1;
}
