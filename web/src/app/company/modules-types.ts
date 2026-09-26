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
  /**
   * The version a module not built yet is expected in (docs/SPEC.md § 7, 2026-09-26 10:08): shown « Bientôt », never on.
   * Absent once the module is real.
   */
  readonly planned?: 'v1' | 'later';
  /** On a planned module: whether the company asked to be told when it arrives (« Me prévenir »). */
  readonly interested?: boolean;
}
