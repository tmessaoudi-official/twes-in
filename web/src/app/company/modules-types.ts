// SPDX-License-Identifier: AGPL-3.0-or-later

/** A module the company can have, and whether it has it on (docs/SPEC.md § 3 Modules). */
export interface ModuleRow {
  readonly key: string;
  /** The translation key of the module's name. */
  readonly labelKey: string;
  /** The keys of the modules it needs on. */
  readonly dependencies: readonly string[];
  /** The permissions its screens and resources check. */
  readonly permissions: readonly string[];
  readonly enabled: boolean;
}
