// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it, vi } from 'vitest';
import { rareOn } from '../list/list-types';
import { menuOrder, onRecord, onRows, type RecordAction } from './record-action';
import { kindOf } from './screen-action';

interface Doc {
  id: string;
  status: 'draft' | 'issued';
}

const draft: Doc = { id: 'd1', status: 'draft' };
const issued: Doc = { id: 'i1', status: 'issued' };

function declared(run = vi.fn()): RecordAction<Doc>[] {
  return [
    {
      id: 'pay',
      label: 'pay',
      icon: 'payments',
      group: 'state',
      frequency: 'next',
      effect: { kind: 'plain' },
      shown: (doc) => doc.status === 'issued',
      run,
    },
    {
      id: 'pdf',
      label: (doc) => (doc.status === 'draft' ? 'pdf_draft' : 'pdf'),
      icon: 'picture_as_pdf',
      group: 'send',
      frequency: (doc) => (doc.status === 'draft' ? 'rare' : 'frequent'),
      effect: { kind: 'plain' },
      shown: () => true,
      href: (doc) => `/pdf/${doc.id}`,
    },
    {
      id: 'abandon',
      label: 'abandon',
      icon: 'delete_forever',
      group: 'state',
      frequency: 'rare',
      effect: {
        kind: 'definitif',
        preview: (doc) => ({
          title: 'abandon_title',
          message: 'abandon_message',
          messageParams: { id: doc.id },
          confirmLabel: 'abandon_confirm',
          keepLabel: 'keep',
        }),
      },
      shown: (doc) => doc.status === 'draft',
      run,
    },
  ];
}

describe('RecordAction', () => {
  it('is bound to the record on view as the screen actions the bar, the keys and the palette read', () => {
    const run = vi.fn();
    const onIssued = onRecord(declared(run), issued, false);
    const pay = onIssued.find((action) => action.id === 'pay')!;

    expect(pay).toMatchObject({ primary: true, next: true, shown: true, disabled: false });
    pay.run?.();
    expect(run).toHaveBeenCalledWith(issued);
    expect(onIssued.find((action) => action.id === 'pdf')).toMatchObject({
      label: 'pdf',
      href: '/pdf/i1',
      rare: false,
      group: 'send',
    });
    expect(onIssued.find((action) => action.id === 'abandon')?.shown).toBe(false);
  });

  it('turns a définitif effect into a destructive action that asks with its preview of this record', () => {
    const abandon = onRecord(declared(), draft, false).find((action) => action.id === 'abandon')!;

    expect(abandon).toMatchObject({ destructive: true, rare: true, shown: true });
    expect(abandon.confirm).toEqual({
      kind: 'definitif',
      title: 'abandon_title',
      message: 'abandon_message',
      messageParams: { id: 'd1' },
      confirmLabel: 'abandon_confirm',
      keepLabel: 'keep',
    });
    expect(onRecord(declared(), draft, false).find((action) => action.id === 'pdf')).toMatchObject({
      label: 'pdf_draft',
      rare: true,
    });
  });

  it('offers nothing while no record is on view, and refuses everything while one is busy', () => {
    expect(onRecord(declared(), null, false).every((action) => action.shown === false)).toBe(true);
    expect(onRecord(declared(), issued, true).every((action) => action.disabled === true)).toBe(
      true,
    );
  });

  it('is bound to every row of a list as row actions, the next step marked and the preview naming the row', () => {
    const rows = onRows(declared());
    const pay = rows.find((action) => action.id === 'pay')!;
    const abandon = rows.find((action) => action.id === 'abandon')!;
    const pdf = rows.find((action) => action.id === 'pdf')!;

    expect(pay.next?.(issued)).toBe(true);
    expect(pay.shown?.(draft)).toBe(false);
    expect(abandon.destructive).toBe(true);
    expect(abandon.confirm?.(draft).messageParams).toEqual({ id: 'd1' });
    expect(pdf.href?.(issued)).toBe('/pdf/i1');
    expect(rareOn(pdf, draft)).toBe(true);
    expect(rareOn(pdf, issued)).toBe(false);
    expect(rareOn(abandon, draft)).toBe(true);
  });

  it('orders a « ⋮ » by its groups: open, create from this, send and print, change state, correct', () => {
    const order = menuOrder([
      { id: 'credit', group: 'correct' },
      { id: 'recurring', group: 'state' },
      { id: 'duplicata', group: 'send' },
      { id: 'copy', group: 'create' },
      { id: 'loose' },
      { id: 'open', group: 'open' },
    ]);

    expect(order.map((action) => action.id)).toEqual([
      'open',
      'copy',
      'duplicata',
      'recurring',
      'credit',
      'loose',
    ]);
  });

  it('cannot be declared corrigeable or définitif without the preview its question says', () => {
    const unasked: RecordAction<Doc> = {
      id: 'issue',
      label: 'issue',
      icon: 'send',
      group: 'state',
      frequency: 'next',
      // @ts-expect-error a corrigeable action states exactly what it will do before it does it
      effect: { kind: 'corrigeable' },
      shown: () => true,
    };
    const annulableAsking: RecordAction<Doc> = {
      id: 'remove',
      label: 'remove',
      icon: 'delete',
      group: 'state',
      frequency: 'rare',
      effect: {
        kind: 'annulable',
        // @ts-expect-error what can be taken back runs at once and is undone from its toast, never asked first
        preview: () => ({ title: '', message: '', confirmLabel: '', keepLabel: '' }),
      },
      shown: () => true,
    };

    expect([unasked.id, annulableAsking.id]).toEqual(['issue', 'remove']);
  });

  it('says an annulable effect is annulable without asking, as its toast will', () => {
    const [remove] = onRecord<Doc>(
      [
        {
          id: 'remove',
          label: 'remove',
          icon: 'delete',
          group: 'correct',
          frequency: 'rare',
          effect: { kind: 'annulable' },
          shown: () => true,
          run: vi.fn(),
        },
      ],
      draft,
      false,
    );

    expect([remove?.confirm, remove === undefined ? null : kindOf(remove)]).toEqual([
      undefined,
      'annulable',
    ]);
    expect(kindOf({ undoable: true })).toBe('annulable');
    expect(kindOf({})).toBeUndefined();
  });
});
