// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialogRef } from '@angular/material/dialog';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import type { ScreenAction } from './screen-action';
import { ScreenActions } from './screen-actions';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';
import { DEFAULT_SHORTCUTS } from './shortcuts';
import { globalShortcuts, ShortcutsSheet } from './shortcuts-sheet';

@Component({ template: '' })
class Holder {
  readonly actions = signal<readonly ScreenAction[]>([]);
  constructor() {
    TestBed.inject(ScreenActions).declare(this.actions);
  }
}

describe('ShortcutsSheet', () => {
  let fixture: ComponentFixture<ShortcutsSheet>;
  let closed = 0;

  function text(): string {
    return fixture.nativeElement.textContent as string;
  }

  function testId(id: string): HTMLElement | null {
    return fixture.nativeElement.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
  }

  function keysOf(id: string): string[] {
    return [...(testId(id)?.querySelectorAll('kbd') ?? [])].map(
      (kbd) => kbd.textContent?.trim() ?? '',
    );
  }

  beforeEach(async () => {
    closed = 0;
    TestBed.configureTestingModule({
      imports: [ShortcutsSheet, Holder],
      providers: [
        provideTranslateService(),
        { provide: MatDialogRef, useValue: { close: () => (closed += 1) } },
        { provide: Session, useValue: { me: signal(null) } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    TestBed.inject(TranslateService).setTranslation('fr', {
      shell: {
        shortcuts: {
          title: 'Raccourcis clavier',
          page: 'Sur cette page',
          everywhere: 'Partout',
          none: 'Cette page ne propose aucun raccourci.',
          palette: 'Ouvrir les commandes',
          sidebar: 'Réduire ou déployer le menu',
          help: 'Afficher cette liste',
          close: 'Fermer',
        },
      },
      invoices: { issue: 'Émettre' },
    });
    TestBed.inject(TranslateService).use('fr');
    await TestBed.inject(TranslateService).use('fr').toPromise();
  });

  function open(actions: readonly ScreenAction[] = []): void {
    const holder = TestBed.createComponent(Holder);
    holder.componentInstance.actions.set(actions);
    holder.detectChanges();
    fixture = TestBed.createComponent(ShortcutsSheet);
    fixture.detectChanges();
  }

  it('lists what the screen on view offers, each with the key that runs it', () => {
    open([{ id: 'validate', label: 'invoices.issue', shortcut: 'v', run: () => undefined }]);

    expect(testId('shortcut-validate')?.textContent).toContain('Émettre');
    expect(keysOf('shortcut-validate')).toEqual(['V']);
  });

  it('lists the next step under the key the shell runs it with, beside its own key if it has one', () => {
    // docs/SPEC.md § 7, 2026-09-24 22:51: E runs the state's next step, whichever screen it is on.
    open([
      { id: 'issue', label: 'invoices.issue', next: true, run: () => undefined },
      { id: 'pay', label: 'invoices.pay', shortcut: 'p', next: true, run: () => undefined },
    ]);

    expect(keysOf('shortcut-issue')).toEqual(['E']);
    // Only the first next step is what E runs; the second keeps its own key alone.
    expect(keysOf('shortcut-pay')).toEqual(['P']);
  });

  it('leaves out an action the screen declared without a key', () => {
    open([
      { id: 'issue', label: 'invoices.issue', shortcut: 'v', run: () => undefined },
      { id: 'pay', label: 'invoices.pay', run: () => undefined },
    ]);

    expect(testId('shortcut-issue')).not.toBeNull();
    expect(testId('shortcut-pay')).toBeNull();
  });

  it('says so on a page that offers none, rather than showing an empty heading', () => {
    open();

    expect(text()).toContain('aucun raccourci');
  });

  it('always lists the keys that work everywhere, whatever the page offers', () => {
    // These are the sheet's whole reason on a page with no keys of its own: they are also the only way to discover
    // the palette, which nothing on the screen names.
    open();

    for (const shortcut of globalShortcuts(DEFAULT_SHORTCUTS)) {
      expect(testId(`shortcut-${shortcut.id}`), shortcut.id).not.toBeNull();
    }
    expect(text()).toContain('Ctrl');
  });

  it('names the shell’s keys from the one list the shell answers them by', () => {
    open();

    expect(keysOf('shortcut-palette')).toEqual([DEFAULT_SHORTCUTS.search, 'Ctrl K']);
    expect(keysOf('shortcut-create')).toEqual([DEFAULT_SHORTCUTS.create.toUpperCase()]);
    expect(keysOf('shortcut-new')).toEqual([DEFAULT_SHORTCUTS.new.toUpperCase()]);
    expect(keysOf('shortcut-next')).toEqual([DEFAULT_SHORTCUTS.next.toUpperCase()]);
  });

  it('names the keys this person chose, on the page’s next step too (row 125)', () => {
    TestBed.inject(SettingsFacade).set(PRESENTATION.shortcuts, {
      ...DEFAULT_SHORTCUTS,
      create: 'k',
      next: 'j',
    });
    open([{ id: 'issue', label: 'invoices.issue', next: true, run: () => undefined }]);

    expect(keysOf('shortcut-create')).toEqual(['K']);
    expect(keysOf('shortcut-next')).toEqual(['J']);
    expect(keysOf('shortcut-issue')).toEqual(['J']);
  });

  it('closes when asked', () => {
    open();
    testId('shortcuts-close')?.click();

    expect(closed).toBe(1);
  });
});
