// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, linkedSignal, type Signal } from '@angular/core';
import { SettingsFacade } from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';

/** Which sections of one menu show their entries, and the way to fold or unfold one. */
export interface SectionFolds {
  /** Reads signals: call it from a template or a `computed`. */
  isOpen(section: string): boolean;
  toggle(section: string): void;
}

/**
 * A menu's foldable sections (docs/SPEC.md § 7, 2026-09-26 12:05, row 152). What a person folded is theirs, kept in
 * `presentation.folded-sections` as `<menu>.<section>` so each menu keeps its own. The section holding the current
 * page opens whenever the person arrives in it, without unfolding it for good, and can be folded again while there.
 *
 * Must be called from an injection context.
 */
export function sectionFolds(menu: string, current: Signal<string | null>): SectionFolds {
  const settings = inject(SettingsFacade);
  const stored = settings.value(PRESENTATION.foldedSections);
  const folded = computed(() => new Set(stored()));
  /** The section opened for the page on view; reset whenever the person arrives in another section. */
  const opened = linkedSignal(() => current());

  const isOpen = (section: string): boolean =>
    !folded().has(`${menu}.${section}`) || opened() === section;

  return {
    isOpen,
    toggle(section: string): void {
      const name = `${menu}.${section}`;
      const rest = stored().filter((each) => each !== name);
      if (isOpen(section)) {
        if (opened() === section) opened.set(null);
        settings.set(PRESENTATION.foldedSections, [...rest, name]);
      } else {
        settings.set(PRESENTATION.foldedSections, rest);
      }
    },
  };
}
