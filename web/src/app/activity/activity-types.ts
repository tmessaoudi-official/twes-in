// SPDX-License-Identifier: AGPL-3.0-or-later

/** One line of the company's journal: who did what to which record, when, and the names of what changed. */
export interface ActivityRow {
  readonly id: string;
  /** The moment, ISO 8601. */
  readonly at: string;
  /** `<kind>.<verb>`, as the API records it: `invoice.issued`. */
  readonly action: string;
  readonly entityType: string;
  readonly entityId: string | null;
  readonly actorId: string | null;
  readonly actorName: string | null;
  /** The names of what changed, never their values. */
  readonly fields: readonly string[];
  /** Only for a reader who manages the team. */
  readonly ip: string | null;
}

/** What the API is asked for one page of the journal. */
export interface ActivitySearch {
  readonly page: number;
  readonly itemsPerPage: number;
  readonly q: string;
  readonly actorIds: readonly string[];
  readonly entityTypes: readonly string[];
  readonly entityId: string | null;
  /** `at.from`, `at.to`, as the list keeps them. */
  readonly intervals: Readonly<Record<string, string>>;
  readonly direction: 'asc' | 'desc';
}

export type ActivityError = 'not_found' | 'invalid' | 'network';
