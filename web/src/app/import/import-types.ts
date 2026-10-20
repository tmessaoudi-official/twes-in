// SPDX-License-Identifier: AGPL-3.0-or-later

/** Why the API refused a request outright, as the import screen translates it under `import.errors.*`. */
export type ImportError =
  | 'network'
  | 'not_found'
  | 'file_too_large'
  | 'step_up_required'
  | 'invalid'
  | 'import_undone'
  | 'import_final';

/** How a file is read: only new things, or new things and the ones already there. */
export type ImportMode = 'create' | 'upsert';

/** What a CSV's bytes are written in, as the API names it; a workbook has none. */
export type TextEncoding = 'utf-8' | 'windows-1252' | 'windows-1256' | 'utf-16';

/** The encodings a person may choose for a CSV, in the order the screen offers them. */
export const TEXT_ENCODINGS: readonly TextEncoding[] = [
  'utf-8',
  'windows-1252',
  'windows-1256',
  'utf-16',
];

/** A file refused WHOLE, before any row ran: its reason, and whatever that reason names. */
export interface ImportRefusal {
  reason:
    | 'invalid_request'
    | 'unreadable'
    | 'empty'
    | 'unknown_columns'
    | 'duplicate_columns'
    | 'missing_columns'
    | 'too_many_rows'
    | 'invalid_pairing'
    | 'unreadable_encoding';
  /** The columns the reason names, empty when it names none. */
  columns: readonly string[];
  /** The row cap, for `too_many_rows`. */
  limit: number | null;
  /** What a CSV was read as; null for a workbook. */
  encoding: TextEncoding | null;
}

/**
 * Where one of the file's own headings goes: a column of the subject, or nowhere (`''`), the column set aside. A file
 * written in the shop's own words is read once it is paired, and the pairing is kept with the import that used it.
 */
export interface ImportPairing {
  /** The heading as the file writes it. */
  heading: string;
  /** The column it is read as, or `''` for a column not imported. */
  column: string;
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

/** A choice the file offers for one run, off unless ticked: « Recompter ». */
export interface ImportSwitch {
  key: string;
  /** A key of this screen's catalogue. */
  labelKey: string;
  /** A key of this screen's catalogue saying when to tick it. */
  noteKey: string | null;
}

/** What one subject's file holds, for one company: its columns, what a row is found again by, and the row cap. */
export interface ImportGuide {
  subject: string;
  /** The columns a row is found again by — a number, a reference, or a pair such as a product and its location. */
  identity: readonly string[];
  maxRows: number;
  columns: readonly ImportColumn[];
  switches: readonly ImportSwitch[];
  /** How the last file imported here had its headings paired, offered again for the next one. */
  lastPairing: readonly ImportPairing[];
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

/** What a row imported was noted for, such as the reference its new product was given; it never stops the file. */
export interface ImportNote {
  line: number;
  column: string | null;
  /** A stable code, translated as `import.notes.<code>`. */
  code: string;
  params: Record<string, string | number>;
}

/** One cell a row found again changes, as the file writes it: an empty value is one the record did not hold. */
export interface ImportChange {
  line: number;
  column: string;
  before: string;
  after: string;
}

/** What a run did, or would do: a preview is the same run rolled back. */
export interface ImportReport {
  committed: boolean;
  /** The import kept, which « Annuler cet import » takes back; null for a preview or a file not kept. */
  run: string | null;
  /** The file's own line numbers, its empty lines counted. */
  created: readonly number[];
  /** The lines found again whose record changes. */
  updated: readonly number[];
  /** The lines found again whose record the row leaves as it is. */
  unchanged: readonly number[];
  rejected: readonly ImportRejection[];
  /** What each line updated changes, cell by cell, in the order of the columns. */
  changes: readonly ImportChange[];
  notes: readonly ImportNote[];
  /** When this very file was last imported into the subject; null when it never was. */
  alreadyImportedAt: string | null;
  /** What a CSV was read as, found on the file or chosen; null for a workbook. */
  encoding: TextEncoding | null;
}

/** One import of the last day into a subject the person may write, as « Imports récents » lists it. */
export interface ImportRunSummary {
  id: string;
  /** The subject as its path writes it: `customers`, `opening-stock`. */
  subject: string;
  /** ISO moments. */
  at: string;
  /** Who ran it; null for an account gone since. */
  by: string | null;
  created: number;
  updated: number;
  undoableUntil: string;
  /** When it was taken back; null while it stands. */
  undoneAt: string | null;
}

/** A record an import created and its undo left, with what names it now. */
export interface ImportKeptRecord {
  id: string;
  label: string;
  uses: readonly { table: string; column: string; count: number }[];
}

/**
 * What « Annuler cet import » did. The records nothing used went to « Récemment supprimés » under `removal`; the stock
 * the file brought was taken back where nothing moved since; what the file only changed stays as changed.
 */
export interface ImportUndone {
  /** The bin's entry, which puts it all back; null when the file created no record that could go. */
  removal: string | null;
  removed: readonly { id: string; label: string }[];
  kept: readonly ImportKeptRecord[];
  /** Rows that are no record of their own (a product's link to a vendor) left with why, `vendor_has_code`. */
  rowsKept: readonly { label: string; reason: string }[];
  stockTakenBack: readonly { reference: string; location: string; quantity: string }[];
  /** Why stock stayed: `counted_since` or `below_zero`. */
  stockKept: readonly { reference: string; location: string; reason: string }[];
  updated: number;
}
