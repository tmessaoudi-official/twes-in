// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { CommandPalette } from './command-palette';
import type { Command } from './commands';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      nav: { home: 'Accueil', expenses: 'Dépenses' },
      expenses: { new_title: 'Nouvelle dépense' },
      modules: { quotes: 'Devis' },
      shell: {
        soon: 'Bientôt',
        commands: {
          title: 'Commandes',
          placeholder: 'Aller à ou créer…',
          create: 'Créer',
          goto: 'Aller à',
          empty: 'Aucune commande ne correspond',
          hint: 'Chaque module ajoute ses propres commandes',
        },
      },
    });
  }
}

const COMMANDS: readonly Command[] = [
  { key: 'goto-home', labelKey: 'nav.home', icon: 'home', route: '/', group: 'goto' },
  {
    key: 'goto-expenses',
    labelKey: 'nav.expenses',
    icon: 'receipt',
    route: '/expenses',
    group: 'goto',
  },
  {
    key: 'new-expense',
    labelKey: 'expenses.new_title',
    icon: 'add',
    route: '/expenses/new',
    group: 'create',
  },
];

describe('CommandPalette', () => {
  const close = vi.fn();

  beforeEach(async () => {
    close.mockReset();
    await TestBed.configureTestingModule({
      imports: [CommandPalette],
      providers: [
        provideRouter([]),
        // A fresh object per test: a case that adds commands must not leave them to the next.
        { provide: MAT_DIALOG_DATA, useFactory: () => ({ commands: COMMANDS }) },
        { provide: MatDialogRef, useValue: { close } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(CommandPalette);
    await fixture.whenStable();
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    const input = el.querySelector<HTMLInputElement>('[data-testid="command-input"]')!;
    const options = () =>
      [...el.querySelectorAll<HTMLElement>('[role="option"] span')].map((label) =>
        label.textContent?.trim(),
      );
    const type = async (text: string) => {
      input.value = text;
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      await fixture.whenStable();
    };
    const key = async (name: string) => {
      input.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }));
      fixture.detectChanges();
      await fixture.whenStable();
    };
    return { fixture, el, input, options, type, key };
  }

  it('is a combobox over a list of options, creations first, the first one active', async () => {
    const { el, input, options } = await render();

    expect(input.getAttribute('role')).toBe('combobox');
    expect(input.getAttribute('aria-expanded')).toBe('true');
    const listbox = el.querySelector('[role="listbox"]');
    expect(input.getAttribute('aria-controls')).toBe(listbox?.id);
    expect(options()).toEqual(['Nouvelle dépense', 'Accueil', 'Dépenses']);
    expect(input.getAttribute('aria-activedescendant')).toBe('command-new-expense');
    expect(el.querySelector('#command-new-expense')?.getAttribute('aria-selected')).toBe('true');
    expect(
      [...el.querySelectorAll('[role="group"]')].map((group) => group.getAttribute('aria-label')),
    ).toEqual(['Créer', 'Aller à']);
  });

  it('narrows the list as the person types, and says so when nothing matches', async () => {
    const { el, options, type } = await render();

    await type('depen');
    expect(options()).toEqual(['Nouvelle dépense', 'Dépenses']);

    await type('facture');
    expect(options()).toEqual([]);
    expect(el.querySelector('[data-testid="command-empty"]')?.textContent).toContain(
      'Aucune commande ne correspond',
    );
  });

  it('moves the active option with the arrow keys, wrapping at both ends', async () => {
    const { input, key } = await render();

    await key('ArrowDown');
    expect(input.getAttribute('aria-activedescendant')).toBe('command-goto-home');
    await key('ArrowDown');
    await key('ArrowDown');
    expect(input.getAttribute('aria-activedescendant')).toBe('command-new-expense');
    await key('ArrowUp');
    expect(input.getAttribute('aria-activedescendant')).toBe('command-goto-expenses');
  });

  it('runs the active command with Enter: goes to its route and closes', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const { key, type } = await render();

    await type('accueil');
    await key('Enter');

    expect(navigate).toHaveBeenCalledWith('/');
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('runs a command picked with the pointer', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const { el, fixture } = await render();

    el.querySelector<HTMLElement>('#command-goto-expenses')?.click();
    await fixture.whenStable();

    expect(navigate).toHaveBeenCalledWith('/expenses');
    expect(close).toHaveBeenCalledTimes(1);
  });

  // Row 150: a planned module's line is marked « Bientôt » and opens its page, like any destination.
  it('marks what is not built yet and takes the person to its page', async () => {
    TestBed.inject(MAT_DIALOG_DATA).commands = [
      ...COMMANDS,
      {
        key: 'goto-quotes',
        labelKey: 'modules.quotes',
        icon: 'request_quote',
        route: '/coming/quotes',
        group: 'goto',
        coming: true,
      },
    ];
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const { el } = await render();

    const quotes = el.querySelector<HTMLElement>('[data-testid="command-goto-quotes"]')!;
    expect(quotes.querySelector('[data-testid="soon"]')?.textContent).toContain('Bientôt');
    expect(el.querySelector('[data-testid="command-goto-home"] [data-testid="soon"]')).toBeNull();
    quotes.click();
    expect(navigate).toHaveBeenCalledWith('/coming/quotes');
  });

  it('does nothing on Enter when nothing matches', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const { key, type } = await render();

    await type('facture');
    await key('Enter');

    expect(navigate).not.toHaveBeenCalled();
    expect(close).not.toHaveBeenCalled();
  });
});
