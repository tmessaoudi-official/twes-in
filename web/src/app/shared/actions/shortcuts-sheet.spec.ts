// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialogRef } from '@angular/material/dialog';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import type { ScreenAction } from './screen-action';
import { ScreenActions } from './screen-actions';
import { GLOBAL_SHORTCUTS, ShortcutsSheet } from './shortcuts-sheet';

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

  beforeEach(async () => {
    closed = 0;
    TestBed.configureTestingModule({
      imports: [ShortcutsSheet, Holder],
      providers: [
        provideTranslateService(),
        { provide: MatDialogRef, useValue: { close: () => (closed += 1) } },
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
    open([{ id: 'issue', label: 'invoices.issue', shortcut: 'e', run: () => undefined }]);

    expect(testId('shortcut-issue')?.textContent).toContain('Émettre');
    expect(testId('shortcut-issue')?.textContent).toContain('E');
  });

  it('leaves out an action the screen declared without a key', () => {
    open([
      { id: 'issue', label: 'invoices.issue', shortcut: 'e', run: () => undefined },
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

    for (const shortcut of GLOBAL_SHORTCUTS) {
      expect(testId(`shortcut-${shortcut.id}`), shortcut.id).not.toBeNull();
    }
    expect(text()).toContain('Ctrl');
  });

  it('closes when asked', () => {
    open();
    testId('shortcuts-close')?.click();

    expect(closed).toBe(1);
  });
});
