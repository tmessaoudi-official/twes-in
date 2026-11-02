// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { RecordColumns } from './record-columns';

@Component({
  imports: [RecordColumns],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-record-columns>
    <div recordSummary>
      @if (figures()) {
        <p data-testid="figures">Total</p>
      }
    </div>
    <p data-testid="form">Client</p>
    <p data-testid="lines">Lignes</p>
  </app-record-columns>`,
})
class Host {
  readonly figures = signal(true);
}

describe('RecordColumns', () => {
  async function render() {
    TestBed.configureTestingModule({ imports: [Host] });
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const root = fixture.nativeElement as HTMLElement;
    const q = (testId: string) => root.querySelector<HTMLElement>(`[data-testid="${testId}"]`);
    return { fixture, root, q };
  }

  it('puts the summary in its own track and everything else in the main one', async () => {
    const { q } = await render();

    expect(q('figures')?.closest('.twes-record-summary')).not.toBeNull();
    expect(q('form')?.closest('.twes-record-main')).not.toBeNull();
    expect(q('lines')?.closest('.twes-record-main')).not.toBeNull();
    expect(q('form')?.closest('.twes-record-summary')).toBeNull();
  });

  // One column (a phone, a rail, the page at 125 %) reads the summary first; two tracks place it at the inline end by
  // their grid, so the reading order is the same in both.
  it('comes before the main track in the reading order', async () => {
    const { root } = await render();
    const summary = root.querySelector('.twes-record-summary')!;
    const main = root.querySelector('.twes-record-main')!;

    expect(summary.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  // What the page shows when it has no figures yet is left to its stylesheet (one track, no gap), which reads only
  // whether the summary's slot holds an element: Angular's anchors are comments, never elements.
  it('leaves the summary slot holding no element while there are no figures', async () => {
    const { fixture, root } = await render();
    fixture.componentInstance.figures.set(false);
    await fixture.whenStable();

    const slot = root.querySelector('.twes-record-summary > [recordSummary]')!;
    expect(slot.children.length).toBe(0);
  });
});
