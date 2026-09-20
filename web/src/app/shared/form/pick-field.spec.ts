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
      (picked)="taken.set($event)"
    />
  `,
})
class Host {
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
