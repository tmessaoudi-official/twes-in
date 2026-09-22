// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { PickField, type PickOption } from './pick-field';

const SCREW: PickOption = { id: 'p1', code: 'VIS-6X40', name: 'Vis 6x40' };
const BOLT: PickOption = { id: 'p2', code: 'BOU-001', name: 'Boulon inox' };

@Component({
  imports: [PickField],
  template: `
    <app-pick-field
      label="Produit"
      testId="pick"
      [search]="search"
      [value]="chosen()"
      [clearable]="true"
      noneLabel="Aucun produit"
      noneFoundLabel="Rien trouvé"
      [inputId]="outside() ? 'expense-form-vendorId' : ''"
      [labelInside]="!outside()"
      (picked)="taken.set($event)"
    />
  `,
})
class Host {
  readonly outside = signal(false);
  readonly chosen = signal<PickOption | null>(null);
  readonly taken = signal<PickOption | null | undefined>(undefined);
  readonly asked: string[] = [];
  answer: readonly PickOption[] = [SCREW, BOLT];

  readonly search = async (words: string): Promise<readonly PickOption[]> => {
    this.asked.push(words);

    return this.answer;
  };
}

describe('PickField', () => {
  let fixture: ComponentFixture<Host>;
  let host: Host;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  /** The field waits for the typing to stop, so the clock has to be moved for anything to be asked. */
  async function type(words: string): Promise<void> {
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.value = words;
    input.dispatchEvent(new Event('input'));
    await settle();
    vi.advanceTimersByTime(400);
    await settle();
    await Promise.resolve();
    await settle();
  }

  const options = (): HTMLElement[] =>
    Array.from(document.querySelectorAll('mat-option')) as HTMLElement[];

  /**
   * What a keyboard wedge does: every character at once, then Enter, with nothing moving the clock between them.
   * The Enter is dispatched in the same synchronous block as the last character, which is what makes it a burst —
   * a pause there would be a person typing, and the field must then behave as it always has.
   */
  function scan(code: string): boolean {
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    for (let at = 1; at <= code.length; at += 1) {
      input.value = code.slice(0, at);
      input.dispatchEvent(new Event('input'));
    }
    const enter = new KeyboardEvent('keydown', {
      key: 'Enter',
      keyCode: 13,
      bubbles: true,
      cancelable: true,
    });
    input.dispatchEvent(enter);

    return enter.defaultPrevented;
  }

  /** Material's autocomplete reads `keyCode`, as a real browser sets it and jsdom does not. */
  async function pressEnter(): Promise<void> {
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true, cancelable: true }),
    );
    await settle();
  }

  beforeEach(async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [{ provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } }],
    });
    fixture = TestBed.createComponent(Host);
    host = fixture.componentInstance;
    await settle();
    await Promise.resolve();
    await settle();
  });

  afterEach(() => vi.useRealTimers());

  it('asks the API once with nothing typed, so the box opens on the first few', () => {
    expect(host.asked).toEqual(['']);
  });

  it('waits for the typing to stop before asking, and asks once for a burst', async () => {
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    for (const words of ['b', 'bo', 'bou']) {
      input.value = words;
      input.dispatchEvent(new Event('input'));
      await settle();
      vi.advanceTimersByTime(100);
    }
    expect(host.asked).toEqual(['']);

    vi.advanceTimersByTime(400);
    await settle();
    await Promise.resolve();
    await settle();

    // One question for the burst, not one per keystroke.
    expect(host.asked).toEqual(['', 'bou']);
  });

  it('offers what the API answered, and emits the whole row when one is taken', async () => {
    await type('bou');
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    await settle();

    const shown = options().map((option) => option.textContent?.trim());
    expect(shown).toContain('BOU-001 · Boulon inox');

    const bolt = options().find((option) => option.textContent?.includes('BOU-001'));
    bolt?.click();
    await settle();

    expect(host.taken()).toEqual(BOLT);
    expect(input.value).toBe('BOU-001 · Boulon inox');
  });

  it('offers "none of them" only where nothing is an answer, and emits null for it', async () => {
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    await settle();

    const none = options().find((option) => option.textContent?.includes('Aucun produit'));
    expect(none).toBeDefined();
    none?.click();
    await settle();

    expect(host.taken()).toBeNull();
  });

  it('says nothing was found only once an answer has come back', async () => {
    host.answer = [];
    await type('zzz');
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    await settle();

    expect(options().map((o) => o.textContent?.trim())).toContain('Rien trouvé');
  });

  /**
   * Inside a descriptor form every field's label sits ABOVE its box, with "· facultatif" beside it. A picker there
   * takes that label instead of drawing its own inside the box, and wears the id the label points at, so what names
   * it is the one label a person reads.
   */
  it('leaves its label to the form around it when that form names the control', async () => {
    host.outside.set(true);
    await settle();

    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    expect(input.id).toBe('expense-form-vendorId');
    expect(fixture.nativeElement.querySelector('mat-label')).toBeNull();
  });

  /** Standing alone it names itself, and keeps the id Material gives every input of its own accord. */
  it('draws its own label when nothing else names it', () => {
    expect(fixture.nativeElement.querySelector('mat-label')?.textContent).toContain('Produit');
    expect(
      (fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement).id,
    ).not.toBe('expense-form-vendorId');
  });

  /**
   * Enter takes the row under the cursor. The trap this pins: "none of them" is an option like any other, so a
   * list that offers it FIRST would hand Enter a null on every clearable field — a person typing a reference and
   * pressing Enter would clear the field instead of filling it.
   */
  it('takes the first row on Enter, never "none of them"', async () => {
    await type('bou');
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    await settle();

    await pressEnter();

    expect(host.taken()).toEqual(SCREW);
  });

  /**
   * A scanner types faster than any hand and presses Enter itself. The 300 ms pause the field waits for never
   * elapses, so without this the search is never asked and the Enter submits the form around it.
   */
  it('reads a scanner burst and takes the one match on its Enter', async () => {
    host.answer = [SCREW];

    const stopped = scan('6191234567897');
    await settle();
    await Promise.resolve();
    await settle();

    expect(host.asked).toContain('6191234567897');
    expect(host.taken()).toEqual(SCREW);
    // The form around the field must not receive that Enter as a submit.
    expect(stopped).toBe(true);
  });

  /** Several matches is a question, not a guess: the list is offered and nothing is emitted. */
  it('offers the choice instead of guessing when a burst matches several', async () => {
    host.answer = [SCREW, BOLT];

    scan('ATL-VIS');
    await settle();
    await Promise.resolve();
    await settle();

    // Asked for the scanned code, not merely showing what the empty first search had left on screen.
    expect(host.asked).toContain('ATL-VIS');
    expect(host.taken()).toBeUndefined();
    const shown = options().map((option) => option.textContent?.trim());
    expect(shown).toContain('VIS-6X40 · Vis 6x40');
    expect(shown).toContain('BOU-001 · Boulon inox');
  });

  /** Typing at a human pace is not a burst: the field keeps waiting for the pause, as it always did. */
  it('leaves a person typing alone', async () => {
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    for (const words of ['b', 'bo', 'bou']) {
      input.value = words;
      input.dispatchEvent(new Event('input'));
      await settle();
      vi.advanceTimersByTime(100);
    }
    const enter = new KeyboardEvent('keydown', {
      key: 'Enter',
      keyCode: 13,
      bubbles: true,
      cancelable: true,
    });
    input.dispatchEvent(enter);
    await settle();

    // Nothing was asked ahead of the pause, and the Enter was left to the form around the field.
    expect(host.asked).toEqual(['']);
    expect(enter.defaultPrevented).toBe(false);
  });

  /** The field never looks a record up: what it shows is what the record itself says. */
  it('shows what was picked before without asking the API for it', async () => {
    host.chosen.set(SCREW);
    await settle();

    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    expect(input.value).toBe('VIS-6X40 · Vis 6x40');
    // Showing what is already picked asks nothing.
    expect(host.asked).toEqual(['']);
  });
});
