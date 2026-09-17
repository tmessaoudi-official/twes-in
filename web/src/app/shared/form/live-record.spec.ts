// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FormControl, FormGroup } from '@angular/forms';
import { Feedback } from '../feedback/feedback';
import { type LiveChange, LiveChanges } from '../realtime/live-changes';
import { provideQuietFeedback, type RecordedFeedback } from '../testing/feedback';
import type { DescriptorFormGroup } from './form-builder';
import type { FieldValue, FormValues } from './form-types';
import { type LivePart, liveRecord } from './live-record';

function group(values: Record<string, FieldValue>): DescriptorFormGroup {
  return new FormGroup(
    Object.fromEntries(Object.entries(values).map(([id, value]) => [id, new FormControl(value)])),
  );
}

const nadia = { id: 'u2', name: 'Nadia' };
const saved = { name: 'Durand', phone: '0612' };

describe('liveRecord', () => {
  let handlers: { kinds: readonly string[]; handler: (changes: readonly LiveChange[]) => void }[];
  let reloads: number;

  function setUp(
    initial: FormValues = saved,
    parts: LivePart[] = [],
    matches?: (change: LiveChange) => boolean,
  ) {
    handlers = [];
    reloads = 0;
    TestBed.configureTestingModule({
      providers: [
        ...provideQuietFeedback(),
        {
          provide: LiveChanges,
          useValue: {
            on: (kinds: readonly string[], handler: (changes: readonly LiveChange[]) => void) =>
              handlers.push({ kinds, handler }),
          },
        },
      ],
    });
    const id = signal<string | null>('c1');
    const form = signal<DescriptorFormGroup | null>(group(initial));
    const current = signal<FormValues | null>(initial);
    let next: FormValues | null = initial;
    const sync = TestBed.runInInjectionContext(() =>
      liveRecord({
        kind: 'customer',
        id,
        form,
        reload: async () => {
          reloads++;
          current.set(next);
        },
        saved: current,
        parts,
        ...(matches ? { matches } : {}),
      }),
    );
    TestBed.tick();
    const feedback = TestBed.inject(Feedback) as RecordedFeedback;
    return {
      id,
      form,
      sync,
      feedback,
      savedElsewhere: async (values: FormValues | null, change: Partial<LiveChange> = {}) => {
        next = values;
        handlers[0].handler([
          { kind: 'customer', id: 'c1', action: 'customer.revised', actor: nadia, ...change },
        ]);
        await new Promise((resolve) => setTimeout(resolve));
      },
    };
  }

  it('listens to its own kind, and ignores another record of that kind', async () => {
    const page = setUp();

    await page.savedElsewhere({ ...saved, phone: '0698' }, { id: 'c2' });

    expect(handlers.map((listener) => listener.kinds)).toEqual([['customer']]);
    expect(reloads).toBe(0);
    expect(page.form()?.controls['phone'].value).toBe('0612');
  });

  it('on a quiet form, reads the record again, takes it and says who changed it', async () => {
    const page = setUp();

    await page.savedElsewhere({ ...saved, phone: '0698' });

    expect(reloads).toBe(1);
    expect(page.form()?.controls['phone'].value).toBe('0698');
    expect([...page.sync.updated()]).toEqual(['phone']);
    expect(page.feedback.said).toEqual([
      { kind: 'notice', key: 'live.notice', params: { name: 'Nadia' } },
    ]);
  });

  it('stays silent when the other save changed nothing this form shows', async () => {
    const page = setUp();

    await page.savedElsewhere({ ...saved });

    expect(reloads).toBe(1);
    expect(page.feedback.said).toEqual([]);
  });

  it('hears the changes a page says are its own, whatever record they name', async () => {
    const page = setUp(saved, [], (change) => change.kind === 'customer');

    await page.savedElsewhere({ ...saved, phone: '0698' }, { id: 'setting-row-7' });

    expect(reloads).toBe(1);
    expect(page.form()?.controls['phone'].value).toBe('0698');
  });

  it('says someone changed it when the actor has no name', async () => {
    const page = setUp();

    await page.savedElsewhere({ ...saved, phone: '0698' }, { actor: null });

    expect(page.feedback.said).toEqual([
      { kind: 'notice', key: 'live.notice_someone', params: {} },
    ]);
  });

  it('while typing, keeps the typed field and shows the banner instead of a toast', async () => {
    const page = setUp();
    page.form()?.controls['phone'].setValue('0613');

    await page.savedElsewhere({ ...saved, phone: '0698' });

    expect(page.form()?.controls['phone'].value).toBe('0613');
    expect(page.sync.changedBy()).toEqual(nadia);
    expect(page.feedback.said).toEqual([]);
  });

  it('says the record was deleted, and merges nothing', async () => {
    const page = setUp();

    await page.savedElsewhere(null, { action: 'customer.deleted' });

    expect(page.form()?.controls['phone'].value).toBe('0612');
    expect(page.feedback.said).toEqual([
      { kind: 'notice', key: 'live.deleted', params: { name: 'Nadia' } },
    ]);
  });

  it('resolves a conflict with the saved value or with what is typed', async () => {
    const page = setUp();
    const form = page.form() as DescriptorFormGroup;
    form.controls['phone'].setValue('0613');
    form.controls['name'].setValue('Durand & fils');
    await page.savedElsewhere({ name: 'Durand SARL', phone: '0698' });

    page.sync.resolve(form, { field: 'phone', choice: 'theirs' });
    page.sync.resolve(form, { field: 'name', choice: 'mine' });

    expect(form.getRawValue()).toEqual({ name: 'Durand & fils', phone: '0698' });
    expect(page.sync.conflicts()).toEqual([]);
  });

  it('merges against the form it was rebuilt from, and against what this tab saved', async () => {
    const page = setUp();
    page.form.set(group({ ...saved, phone: '0600' }));
    TestBed.tick();

    await page.savedElsewhere({ ...saved, phone: '0600', name: 'Durand SARL' });
    expect(page.sync.conflicts()).toEqual([]);

    const form = page.form() as DescriptorFormGroup;
    form.controls['phone'].setValue('0611');
    form.markAsDirty();
    page.sync.savedHere(form);
    expect(form.dirty).toBe(false);

    await page.savedElsewhere({ name: 'Durand SARL', phone: '0611' });
    expect(page.sync.changedBy()).toBeNull();
    expect(page.sync.updated().size).toBe(0);
  });

  describe('with lines, which count as one field', () => {
    function lines(initial: string) {
      const state = { saved: initial, shown: initial, taken: 0 };
      const part: LivePart = {
        field: 'lines',
        saved: () => state.saved,
        shown: () => state.shown,
        take: () => {
          state.taken++;
          state.shown = state.saved;
        },
      };
      return { state, part };
    }

    it('takes saved lines nobody changed here, and highlights them', async () => {
      const { state, part } = lines('A');
      const page = setUp(saved, [part]);
      state.saved = 'B';

      await page.savedElsewhere(saved);

      expect(state.taken).toBe(1);
      expect([...page.sync.updated()]).toEqual(['lines']);
      expect(page.feedback.said).toEqual([
        { kind: 'notice', key: 'live.notice', params: { name: 'Nadia' } },
      ]);
    });

    it('stands on the saved lines when the form is built, before the page has finished showing them', async () => {
      const { state, part } = lines('A');
      state.shown = '';
      const page = setUp(saved, [part]);
      state.shown = 'A';

      await page.savedElsewhere({ ...saved, phone: '0698' });

      expect(page.sync.changedBy()).toBeNull();
    });

    it('stands on what this tab shows once it saved, in whatever shape the API answered', async () => {
      const { state, part } = lines('A');
      const page = setUp(saved, [part]);
      state.shown = 'B typed';
      state.saved = 'B as answered';
      page.sync.savedHere(page.form() as DescriptorFormGroup);

      await page.savedElsewhere({ ...saved, phone: '0698' });

      expect(page.sync.changedBy()).toBeNull();
    });

    it('does nothing to lines the other save left as they were', async () => {
      const { state, part } = lines('A');
      const page = setUp(saved, [part]);

      await page.savedElsewhere({ ...saved, phone: '0698' });

      expect(state.taken).toBe(0);
      expect([...page.sync.updated()]).toEqual(['phone']);
    });

    it('keeps lines being edited here, and offers the saved ones when both changed', async () => {
      const { state, part } = lines('A');
      const page = setUp(saved, [part]);
      state.shown = 'mine';
      state.saved = 'theirs';

      await page.savedElsewhere(saved);

      expect(state.taken).toBe(0);
      expect(page.sync.changedBy()).toEqual(nadia);
      expect(page.sync.conflicts()).toEqual([{ field: 'lines', mine: '', theirs: '' }]);
      expect(page.feedback.said).toEqual([]);

      page.sync.resolve(page.form() as DescriptorFormGroup, { field: 'lines', choice: 'theirs' });
      expect(state.shown).toBe('theirs');
      expect(page.sync.conflicts()).toEqual([]);
    });

    it('counts lines being edited as typing, even when only the header was saved elsewhere', async () => {
      const { state, part } = lines('A');
      const page = setUp(saved, [part]);
      state.shown = 'mine';

      await page.savedElsewhere({ ...saved, phone: '0698' });

      expect(page.sync.changedBy()).toEqual(nadia);
      expect(page.sync.conflicts()).toEqual([]);
    });

    it('reloads the saved lines with the saved header, and starts again from what this tab saved', async () => {
      const { state, part } = lines('A');
      const page = setUp(saved, [part]);
      state.shown = 'mine';
      state.saved = 'theirs';
      await page.savedElsewhere(saved);

      page.sync.reloadSaved(page.form() as DescriptorFormGroup);
      expect(state.shown).toBe('theirs');
      expect(page.sync.changedBy()).toBeNull();

      state.shown = 'saved here';
      state.saved = 'saved here';
      page.sync.savedHere(page.form() as DescriptorFormGroup);
      await page.savedElsewhere(saved);
      expect(page.sync.changedBy()).toBeNull();
      expect(state.taken).toBe(1);
    });
  });
});
