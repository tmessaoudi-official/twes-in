// SPDX-License-Identifier: AGPL-3.0-or-later

/** Long enough for any page of the app with its search; a longer one is not one of ours. */
const MAX_LENGTH = 2048;

/**
 * Where signing in returns: a page of this app, or null. The address arrives in the sign-in's own query, where anyone
 * can write one into a mailed link, so only a path of this origin is kept: one slash and no backslash, which browsers
 * read as a slash, so `//host` and `/\host` name another site; no scheme, no control character, and never the sign-in
 * itself, which would only come back to it.
 */
export function returnAddress(value: string | null | undefined): string | null {
  if (typeof value !== 'string' || value.length > MAX_LENGTH || !value.startsWith('/')) return null;
  if (value === '/' || value.startsWith('//') || value.includes('\\')) return null;
  if ([...value].some((char) => char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127)) return null;
  return value === '/login' || /^\/login[?#/]/.test(value) ? null : value;
}

/** The sign-in page, saying the session ended when it did, and where to return once signed in when there is somewhere. */
export function signInAddress(from: string | undefined, expired = false): string {
  const query = new URLSearchParams();
  if (expired) query.set('expired', '1');
  const back = returnAddress(from);
  if (back !== null) query.set('returnTo', back);
  const written = query.toString();
  return written === '' ? '/login' : `/login?${written}`;
}
