// SPDX-License-Identifier: AGPL-3.0-or-later

/** Why the API refused a request outright, as the import screen translates it under `import.errors.*`. */
export type ImportError = 'network' | 'not_found' | 'file_too_large' | 'invalid';

/** How a file is read: only new things, or new things and the ones already there. */
export type ImportMode = 'create' | 'upsert';

/** A file refused WHOLE, before any row ran: its reason, and whatever that reason names. */
export interface ImportRefusal {
  reason:
    | 'invalid_request'
    | 'unreadable'
    | 'empty'
    | 'unknown_columns'
    | 'duplicate_columns'
    | 'missing_columns'
    | 'too_many_rows';
  /** The columns the reason names, empty when it names none. */
  columns: readonly string[];
  /** The row cap, for `too_many_rows`. */
  limit: number | null;
}

/** One column of the file, described as the person filling it in reads it. */
export interface ImportColumn {
  key: string;
  required: boolean;
  /** A key of this screen's catalogue; null when `label` carries the words already. */
  headingKey: string | null;
  /** The heading already in the person's words; null when `headingKey` is set. */
  label: string | null;
  example: string | null;
  noteKey: string | null;
}

/** What one subject's file holds, for one company: its columns, what a row is found again by, and the row cap. */
export interface ImportGuide {
  subject: string;
  /** The columns a row is found again by — a number, a reference, or a pair such as a product and its location. */
  identity: readonly string[];
  maxRows: number;
  columns: readonly ImportColumn[];
}

/** One row the file asked for and the rules refused. */
export interface ImportRejection {
  line: number;
  column: string | null;
  /** A stable reason, translated as `import.rejections.<code>`; `message` is the fallback for one we do not know. */
  code: string;
  params: Record<string, string | number>;
  message: string;
}

/** What a run did, or would do: a preview is the same run rolled back. */
export interface ImportReport {
  committed: boolean;
  /** The file's own line numbers, its empty lines counted. */
  created: readonly number[];
  updated: readonly number[];
  rejected: readonly ImportRejection[];
}
