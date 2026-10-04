// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { WINDOW_CLASS, type WindowClass } from '../ui/window-class';
import { Select, type SelectOption } from './select';

const few: SelectOption[] = [
  { value: 'tnd', label: 'Tunisian dinar' },
  { value: 'eur', label: 'Euro' },
  { value: 'usd', label: 'US dollar' },
];

const many: SelectOption[] = [
  'Algeria',
  'Belgium',
  'Côte d’Ivoire',
  'Denmark',
  'Égypte',
  'France',
  'Germany',
  'Hungary',
].map((label) => ({ value: label.toLowerCase(), label }));

@Component({
  imports: [ReactiveFormsModule, Select],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span id="lbl">Currency</span>
    <app-select
      [formControl]="control"
      [label]="label()"
      [labelInside]="labelInside()"
      [translateLabels]="translateLabels()"
      [options]="options()"
      [multiple]="multiple()"
      labelledBy="lbl"
      testId="sel"
    />
  `,
})
class Host {
  readonly control = new FormControl<string | string[] | null>(null);
  readonly options = signal<SelectOption[]>(few);
  readonly multiple = signal(false);
  readonly label = signal('');
  readonly labelInside = signal(false);
  readonly translateLabels = signal(false);
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      select: {
        placeholder: 'Choose',
        search: 'Search',
        none_found: 'Nothing found',
        select_all: 'Select all',
        clear_all: 'Clear all',
        more: '+{{count}}',
      },
    });
  }
}

describe('Select', () => {
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`) as HTMLElement | null;
  /** An option's words, without its tick: the tick is an icon font ligature, whose name is text to the DOM. */
  const labelOf = (option: Element | null | undefined): string | undefined =>
    option?.querySelector('[data-option-label]')?.textContent?.trim();
  const optionLabels = (): (string | undefined)[] =>
    Array.from(document.body.querySelectorAll('[role="option"]')).map(labelOf);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function open(): Promise<void> {
    q('sel')!.click();
    await settle();
  }

  function press(target: Element, key: string): void {
    target.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
  }

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('is a combobox named by its label, closed, showing the placeholder', () => {
    const trigger = q('sel')!;
    expect(trigger.getAttribute('role')).toBe('combobox');
    expect(trigger.getAttribute('aria-haspopup')).toBe('listbox');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(trigger.getAttribute('aria-labelledby')).toContain('lbl');
    expect(trigger.textContent).toContain('Choose');
  });

  it('lists its options in a listbox when opened', async () => {
    await open();

    expect(q('sel')!.getAttribute('aria-expanded')).toBe('true');
    expect(document.body.querySelector('[role="listbox"]')).not.toBeNull();
    expect(optionLabels()).toEqual(['Tunisian dinar', 'Euro', 'US dollar']);
    // The box fills the overlay pane, which is as wide as the trigger: left to its content it shrinks under it.
    expect(document.body.querySelector('[role="listbox"]')!.closest('div.w-full')).not.toBeNull();
  });

  it('takes the clicked option as the control value, shows it and closes', async () => {
    await open();
    (document.body.querySelectorAll('[role="option"]')[1] as HTMLElement).click();
    await settle();

    expect(fixture.componentInstance.control.value).toBe('eur');
    expect(q('sel')!.textContent).toContain('Euro');
    expect(document.body.querySelector('[role="listbox"]')).toBeNull();
    expect(q('sel')!.getAttribute('aria-expanded')).toBe('false');
  });

  it('shows the option a control already holds, and marks it selected when opened', async () => {
    fixture.componentInstance.control.setValue('usd');
    await settle();
    expect(q('sel')!.textContent).toContain('US dollar');

    await open();
    const selected = Array.from(document.body.querySelectorAll('[aria-selected="true"]')).map(
      labelOf,
    );
    expect(selected).toEqual(['US dollar']);
  });

  it('moves with the arrow keys, takes Enter and gives focus back on Escape', async () => {
    await open();
    const listbox = document.body.querySelector('[role="listbox"]')!;
    press(listbox, 'ArrowDown');
    press(listbox, 'ArrowDown');
    await settle();
    const active = listbox.getAttribute('aria-activedescendant');
    expect(labelOf(document.getElementById(active!))).toBe('Euro');

    press(listbox, 'Enter');
    await settle();
    expect(fixture.componentInstance.control.value).toBe('eur');

    await open();
    press(document.body.querySelector('[role="listbox"]')!, 'Escape');
    await settle();
    expect(document.body.querySelector('[role="listbox"]')).toBeNull();
    expect(document.activeElement).toBe(q('sel'));
    expect(fixture.componentInstance.control.value).toBe('eur');
  });

  it('can draw its label on its own top edge, and is then named by that label rather than the outside one', async () => {
    fixture.componentInstance.label.set('Unité*');
    fixture.componentInstance.labelInside.set(true);
    await settle();

    const trigger = q('sel')!;
    const label = trigger.parentElement!.querySelector('span[id$="-label"]')!;
    expect(label.textContent?.trim()).toBe('Unité*');
    expect(trigger.getAttribute('aria-labelledby')).toContain(label.id);
    expect(trigger.getAttribute('aria-labelledby')).not.toContain('lbl');

    trigger.click();
    await settle();
    expect(document.body.querySelector('[role="listbox"]')!.getAttribute('aria-labelledby')).toBe(
      label.id,
    );
  });

  it('marks an option written in another language, as a language picker needs', async () => {
    fixture.componentInstance.options.set([
      { value: 'fr', label: 'Français', lang: 'fr' },
      { value: 'en', label: 'English', lang: 'en' },
    ]);
    await settle();
    await open();

    expect(
      Array.from(document.body.querySelectorAll('[role="option"]')).map((o) =>
        o.getAttribute('lang'),
      ),
    ).toEqual(['fr', 'en']);
  });

  it('translates a label with the parameters its option carries', async () => {
    fixture.componentInstance.translateLabels.set(true);
    fixture.componentInstance.options.set([
      { value: 'a', label: 'select.more', params: { count: 3 } },
    ]);
    await settle();
    await open();

    expect(optionLabels()).toEqual(['+3']);
  });

  it('shows how many rows an option stands for, at the end of its row and beside the chosen label', async () => {
    fixture.componentInstance.options.set([
      { value: 'a', label: 'All', count: 30 },
      { value: 'b', label: 'Active', count: 0 },
      { value: 'c', label: 'Archived' },
    ]);
    await settle();
    await open();

    const counts = Array.from(document.body.querySelectorAll('[role="option"]')).map(
      (option) => option.querySelector('[data-option-count]')?.textContent?.trim() ?? null,
    );
    expect(counts).toEqual(['30', '0', null]);
    document.body.querySelector<HTMLElement>('[role="option"]')!.click();
    await settle();
    expect(q('sel')!.querySelector('[data-trigger-count]')?.textContent?.trim()).toBe('30');
  });

  it('goes to the option a typed letter begins, opening from the trigger as a native select did', async () => {
    press(q('sel')!, 'e');
    await settle();

    const listbox = document.body.querySelector('[role="listbox"]')!;
    expect(labelOf(document.getElementById(listbox.getAttribute('aria-activedescendant')!))).toBe(
      'Euro',
    );

    // A pause, and a second letter is a new word rather than the end of « eu ».
    await new Promise((resolve) => setTimeout(resolve, 750));
    press(listbox, 'u');
    await settle();
    expect(labelOf(document.getElementById(listbox.getAttribute('aria-activedescendant')!))).toBe(
      'US dollar',
    );

    press(listbox, 'Enter');
    await settle();
    expect(fixture.componentInstance.control.value).toBe('usd');
  });

  it('reads letters typed in a row as one word, and leaves a shortcut chord alone', async () => {
    fixture.componentInstance.options.set([
      { value: 'tn', label: 'Tunisia' },
      { value: 'tr', label: 'Turkey' },
      { value: 'tg', label: 'Togo' },
    ]);
    await settle();
    await open();
    const listbox = document.body.querySelector('[role="listbox"]')!;
    const active = () =>
      labelOf(document.getElementById(listbox.getAttribute('aria-activedescendant')!));

    press(listbox, 't');
    press(listbox, 'u');
    press(listbox, 'r');
    await settle();
    expect(active()).toBe('Turkey');

    // A pause, then a chord on a letter that WOULD move on to « Togo »: a shortcut is not a word.
    await new Promise((resolve) => setTimeout(resolve, 750));
    listbox.dispatchEvent(new KeyboardEvent('keydown', { key: 't', ctrlKey: true, bubbles: true }));
    await settle();
    expect(active()).toBe('Turkey');
  });

  it('has no search box up to seven options', async () => {
    await open();
    expect(document.body.querySelector('[data-testid="sel-search"]')).toBeNull();
  });

  it('adds a search box past seven options, finding by what is typed without case or accents', async () => {
    fixture.componentInstance.options.set(many);
    await settle();
    await open();

    const search = q('sel-search') as HTMLInputElement;
    expect(search).not.toBeNull();
    search.value = 'egypte';
    search.dispatchEvent(new Event('input'));
    await settle();
    expect(optionLabels()).toEqual(['Égypte']);

    search.value = 'zzz';
    search.dispatchEvent(new Event('input'));
    await settle();
    expect(optionLabels()).toEqual([]);
    expect(document.body.textContent).toContain('Nothing found');
  });

  describe('multiple', () => {
    beforeEach(async () => {
      fixture.componentInstance.multiple.set(true);
      fixture.componentInstance.options.set(many);
      fixture.componentInstance.control.setValue([]);
      await settle();
    });

    it('toggles options into an array and stays open', async () => {
      await open();
      const options = () => document.body.querySelectorAll('[role="option"]');
      (options()[0] as HTMLElement).click();
      (options()[3] as HTMLElement).click();
      await settle();

      expect(fixture.componentInstance.control.value).toEqual(['algeria', 'denmark']);
      expect(document.body.querySelector('[role="listbox"]')).not.toBeNull();
      expect(
        document.body.querySelector('[role="listbox"]')!.getAttribute('aria-multiselectable'),
      ).toBe('true');

      (options()[0] as HTMLElement).click();
      await settle();
      expect(fixture.componentInstance.control.value).toEqual(['denmark']);
    });

    it('selects all and clears all', async () => {
      await open();
      q('sel-select-all')!.click();
      await settle();
      expect((fixture.componentInstance.control.value as string[]).length).toBe(many.length);

      q('sel-clear-all')!.click();
      await settle();
      expect(fixture.componentInstance.control.value).toEqual([]);
    });

    it('shows chips for what is chosen and « +N » past three', async () => {
      fixture.componentInstance.control.setValue(['algeria', 'belgium']);
      await settle();
      const chips = () =>
        Array.from(q('sel')!.querySelectorAll('[data-chip]')).map((c) => c.textContent?.trim());
      expect(chips()).toEqual(['Algeria', 'Belgium']);

      fixture.componentInstance.control.setValue([
        'algeria',
        'belgium',
        'france',
        'germany',
        'hungary',
      ]);
      await settle();
      expect(chips()).toEqual(['Algeria', 'Belgium', 'France']);
      expect(q('sel')!.textContent).toContain('+2');
    });
  });

  it('opens as a sheet at the bottom of a phone window, over a backdrop that closes it', async () => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: WINDOW_CLASS, useValue: signal<WindowClass>('compact') },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
    await open();

    expect(document.body.querySelector('.twes-select-sheet')).not.toBeNull();
    expect(document.body.querySelector('.twes-select-panel')).toBeNull();
    const backdrop = document.body.querySelector<HTMLElement>('.cdk-overlay-backdrop');
    expect(backdrop).not.toBeNull();
    expect(
      document.body.querySelector('[role="listbox"]')!.closest('div.rounded-b-none'),
    ).not.toBeNull();

    backdrop!.click();
    await settle();
    expect(document.body.querySelector('[role="listbox"]')).toBeNull();
  });

  it('cannot be opened while disabled', async () => {
    fixture.componentInstance.control.disable();
    await settle();

    expect((q('sel') as HTMLButtonElement).disabled).toBe(true);
    q('sel')!.click();
    await settle();
    expect(document.body.querySelector('[role="listbox"]')).toBeNull();
  });
});
