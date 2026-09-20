// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * A role a company may give its members, as the roles screen holds it (docs/SPEC.md § 7, 2026-09-20 11:30, row 104).
 *
 * `builtIn` and `wildcard` are separate because they answer different questions. The three built-in roles are the
 * release's and cannot be edited here; the owner additionally holds everything there is, now and in later releases,
 * which the screen draws as "everything" rather than as every box ticked — ticking them all would be a different
 * and smaller promise.
 */
export interface RoleRow {
  readonly id: string;
  readonly name: string;
  readonly builtIn: boolean;
  readonly wildcard: boolean;
  readonly permissions: readonly string[];
  readonly memberCount: number;
}

/** One heading of the matrix, and the permissions under it. */
export interface PermissionGroupRow {
  readonly key: string;
  readonly labelKey: string;
  readonly permissions: readonly string[];
}
