// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { RecordDeletes } from '../shared/records/record-deletes';
import { RecordRemovalsApi, type RecordRemoval } from '../shared/records/record-removals-api';
import { RecentlyDeletedPage } from './recently-deleted-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      records: {
        recent: {
          title: 'Récemment supprimés',
          intro: 'Gardé un jour.',
          empty: 'Rien de supprimé.',
          restore: 'Remettre',
          removed_at: 'Supprimé le {{moment}}',
          until: 'Jusqu’au {{moment}}',
          merged_into: 'Fusionnés dans {{kept}} :',
          merged_at: 'Fusionné le {{moment}}',
          merge_until: 'Annulable jusqu’au {{moment}}',
          undo_merge: 'Annuler la fusion',
          subjects: {
            vendor: 'Fournisseurs : {{count}}',
            product_category: 'Catégories : {{count}}',
          },
        },
        errors: { network: 'Le serveur ne répond pas.' },
      },
    });
  }
}

const two: RecordRemoval = {
  id: 'r2',
  subject: 'vendor',
  kind: 'delete',
  kept: null,
  items: [
    { id: 'v2', label: 'F-002 Visserie' },
    { id: 'v3', label: 'F-003 Quincaillerie du Sud' },
  ],
  removedAt: '2026-10-20T05:00:00+00:00',
  effectiveAt: '2026-10-21T05:00:00+00:00',
  state: 'pending',
};
const one: RecordRemoval = {
  id: 'r1',
  subject: 'vendor',
  kind: 'delete',
  kept: null,
  items: [{ id: 'v1', label: 'F-001 Sotumag' }],
  removedAt: '2026-10-20T04:00:00+00:00',
  effectiveAt: '2026-10-21T04:00:00+00:00',
  state: 'pending',
};

const merge: RecordRemoval = {
  id: 'm1',
  subject: 'product_category',
  kind: 'merge',
  kept: { id: 'k1', label: 'Visserie' },
  items: [{ id: 'k2', label: 'Vis' }],
  removedAt: '2026-10-20T06:00:00+00:00',
  effectiveAt: '2026-10-21T06:00:00+00:00',
  state: 'pending',
};

describe('RecentlyDeletedPage', () => {
  const me = signal<unknown>({ user: { id: 'u1' }, company: { id: 'c1' } });
  const api = { pending: vi.fn() };
  const deletes = { restore: vi.fn() };
  const live = { reloadOn: vi.fn() };
  let fixture: ComponentFixture<RecentlyDeletedPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  /** Until what is awaited after a press is done: `whenStable()` does not wait for a promise chained on a promise. */
  async function until(check: () => void): Promise<void> {
    await vi.waitFor(() => {
      fixture.detectChanges();
      check();
    });
  }

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(RecentlyDeletedPage);
    await settle();
  }

  beforeEach(() => {
    api.pending.mockReset().mockResolvedValue([two, one]);
    deletes.restore.mockReset().mockResolvedValue(true);
    live.reloadOn.mockReset();
    TestBed.configureTestingModule({
      imports: [RecentlyDeletedPage],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: AuthFacade, useValue: { me } },
        { provide: FormatFacade, useValue: { moment: (value: string) => value } },
        { provide: RecordRemovalsApi, useValue: api },
        { provide: RecordDeletes, useValue: deletes },
        { provide: LiveChanges, useValue: live },
      ],
    });
  });

  it('lists each delete still restorable, the newest first, with what it took and until when', async () => {
    await open();

    expect(api.pending).toHaveBeenCalledWith('c1');
    const shown = [...fixture.nativeElement.querySelectorAll('li[data-testid^="removal-r"]')].map(
      (each) => (each as HTMLElement).getAttribute('data-testid'),
    );
    expect(shown).toEqual(['removal-r2', 'removal-r1']);
    const first = q('removal-r2')!;
    expect(first.textContent).toContain('Fournisseurs : 2');
    expect(
      [...first.querySelectorAll('bdi')].map((each) => (each as HTMLElement).textContent),
    ).toEqual(['F-002 Visserie', 'F-003 Quincaillerie du Sud']);
    expect(first.textContent).toContain('Supprimé le 2026-10-20T05:00:00+00:00');
    expect(first.textContent).toContain('Jusqu’au 2026-10-21T05:00:00+00:00');
  });

  it('puts a delete back, then reads the list again, refused or not', async () => {
    await open();
    api.pending.mockResolvedValue([two]);

    q('removal-restore-r1')!.click();
    await settle();

    await until(() => {
      expect(q('removal-r1')).toBeNull();
      expect((q('removal-restore-r2') as HTMLButtonElement).disabled).toBe(false);
    });
    expect(deletes.restore).toHaveBeenCalledWith('c1', one);

    // Refused because its day ran out meanwhile: the list read again no longer holds it.
    deletes.restore.mockResolvedValue(false);
    api.pending.mockResolvedValue([]);
    q('removal-restore-r2')!.click();
    await until(() => expect(q('removals-empty')).not.toBeNull());
    expect(q('removal-r2')).toBeNull();
  });

  it('keeps a delete whose restore was refused while it is still restorable, its button free again', async () => {
    deletes.restore.mockResolvedValue(false);
    await open();

    q('removal-restore-r1')!.click();
    await until(() => expect((q('removal-restore-r1') as HTMLButtonElement).disabled).toBe(false));
    expect(api.pending).toHaveBeenCalledTimes(2);
    expect(q('removal-r1')).not.toBeNull();
  });

  it('holds every button while one delete is put back', async () => {
    let answer: (restored: boolean) => void = () => undefined;
    deletes.restore.mockReturnValue(new Promise<boolean>((resolve) => (answer = resolve)));
    await open();

    q('removal-restore-r1')!.click();
    fixture.detectChanges();
    expect(
      ['r1', 'r2'].map((id) => (q(`removal-restore-${id}`) as HTMLButtonElement).disabled),
    ).toEqual([true, true]);

    answer(true);
    await until(() => expect((q('removal-restore-r2') as HTMLButtonElement).disabled).toBe(false));
  });

  it('reads the list again when a delete or a restore is made anywhere', async () => {
    await open();
    expect(live.reloadOn.mock.calls[0][0]).toEqual([
      'record_removal',
      'vendor',
      'product',
      'product_category',
      'expense_category',
    ]);

    api.pending.mockResolvedValue([]);
    await (live.reloadOn.mock.calls[0][1] as () => Promise<void>)();
    fixture.detectChanges();

    expect(q('removals-empty')?.textContent).toContain('Rien de supprimé.');
  });

  it('says when the list cannot be read', async () => {
    api.pending.mockRejectedValue(new Error('down'));
    await open();

    expect(q('removals-error')?.getAttribute('role')).toBe('alert');
    expect(q('removals-empty')).toBeNull();
  });

  it('shows a merge with the record kept, and undoes it from its own button', async () => {
    api.pending.mockResolvedValue([merge, one]);
    await open();

    const shown = q('removal-m1')!;
    expect(shown.textContent).toContain('Catégories : 1');
    expect(shown.textContent).toContain('Fusionnés dans Visserie :');
    expect(shown.textContent).toContain('Fusionné le 2026-10-20T06:00:00+00:00');
    expect(shown.textContent).toContain('Annulable jusqu’au 2026-10-21T06:00:00+00:00');
    expect(shown.textContent).not.toContain('Supprimé le');
    expect(q('removal-restore-m1')?.textContent).toContain('Annuler la fusion');
    expect(q('removal-restore-r1')?.textContent).toContain('Remettre');

    q('removal-restore-m1')!.click();
    await until(() => expect(deletes.restore).toHaveBeenCalledWith('c1', merge));
  });
});
