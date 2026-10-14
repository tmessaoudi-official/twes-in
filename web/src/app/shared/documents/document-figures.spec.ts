// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  type DocumentFigures,
  type LineFigures,
  liveFigures,
  negated,
  PREVIEW_DELAY,
  toDocumentFigures,
} from './document-figures';

const line = (net: string): LineFigures => ({
  amount: net,
  discount: '0.000',
  net,
  documentDiscount: '0.000',
  taxes: [],
  total: net,
});

/** A line as the preview answers it: its figures and the line sent it was worked out from. */
const sent = (figures: LineFigures, sentFrom: number | null) => ({ ...figures, sentFrom });

const figures = (total: string): DocumentFigures =>
  toDocumentFigures({ lines: [sent(line(total), 0)], total }, [0], 1);

describe('toDocumentFigures', () => {
  it('puts each line worked out back at its place, the lines not sent yet left without figures', () => {
    const read = toDocumentFigures(
      {
        lines: [sent(line('10.000'), 0), sent(line('30.000'), 1)],
        total: '40.000',
        netToPay: '40.000',
      },
      [0, 2],
      3,
    );
    expect(read.lines).toEqual([line('10.000'), null, line('30.000')]);
    expect(read.total).toBe('40.000');
    expect(read.netToPay).toBe('40.000');
    expect(read.taxes).toEqual([]);
  });

  it('puts each line on the row it was sent from, the deposit lines a credit note of part of an invoice leaves out keeping none', () => {
    // Sent: a deposit line, a sold line, a deposit line, a sold line; answered: the two sold lines alone.
    const read = toDocumentFigures(
      { lines: [sent(line('60.000'), 1), sent(line('1125.000'), 3)], total: '-1185.000' },
      [0, 1, 2, 3],
      4,
    );
    expect(read.lines).toEqual([null, line('60.000'), null, line('1125.000')]);
  });

  it('follows the order the API kept the lines in, and puts a line it added itself on no row', () => {
    // Sent: rows 0 and 2; answered: row 2's line, row 0's, then a deposit line the API adds to a whole credit note.
    const read = toDocumentFigures(
      {
        lines: [sent(line('60.000'), 1), sent(line('-675.000'), 0), sent(line('500.000'), null)],
        total: '-115.000',
      },
      [0, 2],
      3,
    );
    expect(read.lines).toEqual([line('-675.000'), null, line('60.000')]);
  });
});

describe('negated', () => {
  it("takes an amount off, whatever sign the document gave it: a credit note's discount is already negative", () => {
    expect(negated('3.750')).toBe('-3.750');
    expect(negated('-3.750')).toBe('3.750');
    expect(negated('0.000')).toBe('-0.000');
  });
});

describe('liveFigures', () => {
  const ask = vi.fn<(draft: string) => Promise<DocumentFigures | null>>();
  const draft = signal<string | null>(null);

  async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) await Promise.resolve();
  }

  function create() {
    TestBed.configureTestingModule({ providers: [{ provide: PREVIEW_DELAY, useValue: 250 }] });
    const shown = TestBed.runInInjectionContext(() => liveFigures(() => draft(), ask));
    TestBed.tick();
    return shown;
  }

  beforeEach(() => {
    vi.useFakeTimers();
    ask.mockReset().mockImplementation(async (body) => figures(body));
    draft.set(null);
  });

  afterEach(() => vi.useRealTimers());

  it('asks once typing rests, for the last draft only', async () => {
    const shown = create();
    draft.set('1.000');
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(100);
    draft.set('12.000');
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(249);
    expect(ask).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1);
    await settle();
    expect(ask).toHaveBeenCalledExactlyOnceWith('12.000');
    expect(shown()?.total).toBe('12.000');
  });

  it('does not ask again for a draft built anew with the same content', async () => {
    // A keystroke that changes nothing (a field touched, a value typed back) builds a new draft object.
    const typed = signal(0);
    const asked = vi.fn(async (body: { total: string }) => figures(body.total));
    TestBed.configureTestingModule({ providers: [{ provide: PREVIEW_DELAY, useValue: 250 }] });
    TestBed.runInInjectionContext(() => liveFigures(() => (typed(), { total: '5.000' }), asked));
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(250);
    typed.set(1);
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(250);
    expect(asked).toHaveBeenCalledTimes(1);
  });

  it('never shows an earlier draft answering late over a later one', async () => {
    let answerFirst: (value: DocumentFigures) => void = () => undefined;
    ask.mockImplementationOnce(
      () => new Promise<DocumentFigures>((resolve) => (answerFirst = resolve)),
    );
    const shown = create();
    draft.set('1.000');
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(250);
    draft.set('2.000');
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(250);
    await settle();
    expect(shown()?.total).toBe('2.000');

    answerFirst(figures('1.000'));
    await settle();
    expect(shown()?.total).toBe('2.000');
  });

  it('shows nothing for a draft the API refuses, nor for no draft at all', async () => {
    const shown = create();
    draft.set('3.000');
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(250);
    await settle();
    expect(shown()?.total).toBe('3.000');

    ask.mockResolvedValueOnce(null);
    draft.set('refused');
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(250);
    await settle();
    expect(shown()).toBeNull();

    draft.set('4.000');
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(250);
    await settle();
    draft.set(null);
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(250);
    await settle();
    expect(shown()).toBeNull();
    expect(ask).toHaveBeenCalledTimes(3);
  });
});
