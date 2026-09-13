// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * What the sidebar offers. G2a declares the entry shape and the core entries; from G5 the module registry supplies
 * entries of the same shape for each enabled module, and the shell does not change (docs/SPEC.md § 3 Modules).
 */
export type NavSection = 'main' | 'admin';

export interface NavEntry {
  readonly key: string;
  readonly labelKey: string;
  /** A Material Symbols ligature. */
  readonly icon: string;
  readonly route: string;
  readonly section: NavSection;
  /** The permission string the entry needs; without one, every signed-in user sees it. */
  readonly permission?: string;
  /** Present in development builds only, such as the design checkpoint screens. */
  readonly devOnly?: boolean;
}

export interface NavGroup {
  readonly section: NavSection;
  readonly entries: readonly NavEntry[];
}

const SECTION_ORDER: readonly NavSection[] = ['main', 'admin'];

export const CORE_NAV: readonly NavEntry[] = [
  { key: 'home', labelKey: 'nav.home', icon: 'home', route: '/', section: 'main' },
  {
    key: 'members',
    labelKey: 'nav.members',
    icon: 'group',
    route: '/members',
    section: 'admin',
    permission: 'user.read',
  },
  // The design checkpoint's fixture screens: a development build only, never shipped.
  {
    key: 'design',
    labelKey: 'nav.design',
    icon: 'palette',
    route: '/design',
    section: 'admin',
    devOnly: true,
  },
];

/** The entries this user may see in this build. Hiding is a courtesy; the API refuses what the voter refuses. */
export function visibleEntries(
  entries: readonly NavEntry[],
  can: (permission: string) => boolean,
  developmentBuild: boolean,
): readonly NavEntry[] {
  return entries.filter(
    (entry) =>
      (entry.devOnly !== true || developmentBuild) &&
      (entry.permission === undefined || can(entry.permission)),
  );
}

export function navSections(entries: readonly NavEntry[]): readonly NavGroup[] {
  return SECTION_ORDER.map((section) => ({
    section,
    entries: entries.filter((entry) => entry.section === section),
  })).filter((group) => group.entries.length > 0);
}
