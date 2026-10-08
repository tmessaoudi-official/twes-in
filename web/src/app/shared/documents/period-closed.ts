// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Whether the API refused a document because the company's books are closed through its day: the refusal names the
 * field `closedPeriod`, whichever document it was, so every feature's API adapter reads it the same way. It reads the
 * HTTP error by its shape, since only an adapter imports the HTTP client.
 */
export function isPeriodClosed(error: unknown): boolean {
  if (typeof error !== 'object' || error === null) return false;
  const { status, error: body } = error as { status?: unknown; error?: unknown };
  const detail = (body as { detail?: unknown } | null | undefined)?.detail;
  return status === 422 && typeof detail === 'string' && detail.startsWith('closedPeriod:');
}
