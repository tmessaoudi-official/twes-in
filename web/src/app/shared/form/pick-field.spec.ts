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
      (scanned)="scans.push($event)"
    />
  `,
})
class Host {
  readonly outside = signal(false);
  readonly chosen = signal<PickOption | null>(null);
  readonly taken = signal<PickOption | null | undefined>(undefined);
  readonly asked: string[] = [];
  readonly scans: string[] = [];
  answer: readonly PickOption[] = [SCREW, BOLT];
  /** When set, the question asked on no words waits for this before answering, as a slow API does. */
  firstAnswer: Promise<void> | null = null;

  readonly search = async (words: string): Promise<readonly PickOption[]> => {
    this.asked.push(words);
    if (words === '' && this.firstAnswer !== null) {
      await this.firstAnswer;
      return [BOLT, SCREW];
    }

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
    // What was scanned travels with the pick, so the screen can ask what the code counts (a pack of twelve).
    expect(host.scans).toEqual(['6191234567897']);
    // The box reads the row taken, not the code the scanner typed, whether or not the screen hands it back.
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    expect(input.value).toBe('VIS-6X40 · Vis 6x40');
    // The form around the field must not receive that Enter as a submit.
    expect(stopped).toBe(true);
  });

  /**
   * A page busy for a moment (rendering a list) delivers a scanner's keys late and together. They are timed by when
   * they were typed, which each event carries, not by when the field got to them: read the second way, the moment the
   * page was busy splits one scan into two and the Enter lands as a person's.
   */
  it('reads a burst the page delivered late as one scan, by when its keys were typed', async () => {
    host.answer = [SCREW];
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    const typedAt = performance.now();
    const code = '6191234567897';
    for (let at = 1; at <= code.length; at += 1) {
      // The page was busy for 100 ms before the last key: the gap the field decides on.
      if (at === code.length) vi.advanceTimersByTime(100);
      input.value = code.slice(0, at);
      const typed = new Event('input');
      Object.defineProperty(typed, 'timeStamp', { value: typedAt + at });
      input.dispatchEvent(typed);
    }
    const enter = new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, cancelable: true });
    input.dispatchEvent(enter);
    await settle();
    await Promise.resolve();
    await settle();

    expect(enter.defaultPrevented).toBe(true);
    expect(host.scans).toEqual([code]);
  });

  /**
   * A scan right after the field is focused: the question the focus asked (no words) answers in the middle of the
   * burst. Its rows belong to before the scan, and the Enter must not take the first of them.
   */
  it('takes the scan’s own match even when an older answer lands during the burst', async () => {
    let release!: () => void;
    fixture = TestBed.createComponent(Host);
    host = fixture.componentInstance;
    host.firstAnswer = new Promise<void>((resolve) => (release = resolve));
    host.answer = [SCREW];
    await settle();

    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    const code = '6191234567897';
    for (let at = 1; at <= code.length; at += 1) {
      input.value = code.slice(0, at);
      input.dispatchEvent(new Event('input'));
      if (at === 6) {
        release();
        await Promise.resolve();
        await Promise.resolve();
        fixture.detectChanges();
      }
    }
    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true, cancelable: true }),
    );
    await settle();
    await Promise.resolve();
    await settle();

    expect(host.taken()).toEqual(SCREW);
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
    expect(host.scans).toEqual([]);
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

  /**
   * A screen that builds the picked row afresh on every check — a document line reading it from its controls — hands
   * over a new but equal object each time. That is the same pick, and what the person types over it must stay.
   */
  it('keeps what is typed over a pick when the screen hands the same pick again', async () => {
    host.chosen.set(SCREW);
    await settle();
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;

    input.value = 'BOU';
    input.dispatchEvent(new Event('input'));
    host.chosen.set({ ...SCREW });
    await settle();

    expect(input.value).toBe('BOU');

    // Another pick is another pick: the box shows it.
    host.chosen.set(BOLT);
    await settle();
    expect(input.value).toBe('BOU-001 · Boulon inox');
  });

  /**
   * Words typed over a pick and then abandoned would leave the box saying one thing while the record holds another
   * (docs/SPEC.md § 7, 2026-09-24, overnight row 6): leaving the field puts the pick's own words back.
   */
  it('puts the pick’s words back when the person leaves without choosing', async () => {
    host.chosen.set(SCREW);
    await settle();
    await type('bou');
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    expect(input.value).toBe('bou');

    input.dispatchEvent(new Event('blur'));
    await settle();

    expect(input.value).toBe('VIS-6X40 · Vis 6x40');
    // Nothing was chosen, so nothing is emitted: the record keeps its pick.
    expect(host.taken()).toBeUndefined();

    // The list is asked afresh with no words, so it does not reopen on what was abandoned.
    vi.advanceTimersByTime(400);
    await settle();
    expect(host.asked).toEqual(['', 'bou', '']);
  });

  it('keeps what is typed on leaving when nothing was ever picked', async () => {
    await type('bou');
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;

    input.dispatchEvent(new Event('blur'));
    await settle();

    expect(input.value).toBe('bou');
  });

  /** A click on a row moves the focus out of the box before the row is taken: the list must still take it. */
  it('still takes a row clicked while the list is open', async () => {
    host.chosen.set(SCREW);
    await settle();
    await type('bou');
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    await settle();

    input.dispatchEvent(new Event('blur'));
    await settle();
    expect(input.value).toBe('bou');

    options()
      .find((option) => option.textContent?.includes('BOU-001'))
      ?.click();
    await settle();

    expect(host.taken()).toEqual(BOLT);
    expect(input.value).toBe('BOU-001 · Boulon inox');
  });

  it('puts the pick’s words back when the list closes after the person has left', async () => {
    host.chosen.set(SCREW);
    await settle();
    await type('bou');
    const input = fixture.nativeElement.querySelector('[data-testid="pick"]') as HTMLInputElement;
    input.dispatchEvent(new Event('focusin'));
    await settle();
    input.dispatchEvent(new Event('blur'));
    await settle();

    // Material closes the list on a click outside it; Escape closes it the same way.
    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, bubbles: true, cancelable: true }),
    );
    await settle();

    expect(input.value).toBe('VIS-6X40 · Vis 6x40');
    expect(host.taken()).toBeUndefined();
  });
});
