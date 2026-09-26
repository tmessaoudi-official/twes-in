// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';
import { sectionFolds } from './section-folds';

// docs/SPEC.md § 7, 2026-09-26 12:05 (row 152): each section folds from its heading, remembered per person; the one
// holding the current page always opens.
describe('sectionFolds', () => {
  const current = signal<string | null>(null);
  let settings: SettingsFacade;

  beforeEach(() => {
    current.set(null);
    TestBed.configureTestingModule({
      providers: [
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: Session, useValue: { me: signal(null) } },
      ],
    });
    settings = TestBed.inject(SettingsFacade);
  });

  const folds = () => TestBed.runInInjectionContext(() => sectionFolds('nav', current));

  it('opens every section until the person folds one, and remembers it under the menu’s name', () => {
    const nav = folds();
    expect(nav.isOpen('sell')).toBe(true);
    expect(nav.isOpen('manage')).toBe(true);

    nav.toggle('manage');
    expect(nav.isOpen('manage')).toBe(false);
    expect(nav.isOpen('sell')).toBe(true);
    expect(settings.value(PRESENTATION.foldedSections)()).toEqual(['nav.manage']);

    nav.toggle('manage');
    expect(nav.isOpen('manage')).toBe(true);
    expect(settings.value(PRESENTATION.foldedSections)()).toEqual([]);
  });

  it('keeps another menu’s folds apart', () => {
    settings.set(PRESENTATION.foldedSections, ['settings.manage']);
    expect(folds().isOpen('manage')).toBe(true);
  });

  it('opens the section holding the current page, and lets the person fold it again while there', () => {
    settings.set(PRESENTATION.foldedSections, ['nav.manage']);
    const nav = folds();
    expect(nav.isOpen('manage')).toBe(false);

    current.set('manage');
    TestBed.tick();
    expect(nav.isOpen('manage')).toBe(true);
    // Opened for the page, not unfolded for good.
    expect(settings.value(PRESENTATION.foldedSections)()).toEqual(['nav.manage']);

    nav.toggle('manage');
    expect(nav.isOpen('manage')).toBe(false);

    // Arriving in it again opens it again.
    current.set('sell');
    TestBed.tick();
    current.set('manage');
    TestBed.tick();
    expect(nav.isOpen('manage')).toBe(true);
  });
});
