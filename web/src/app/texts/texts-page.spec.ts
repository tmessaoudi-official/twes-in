// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { RowAction } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import {
  effectToasts,
  failureToasts,
  offeredNext,
  provideQuietFeedback,
  successToasts,
} from '../shared/testing/feedback';
import type { TextDialogData } from './text-dialog';
import { TextsFacade } from './texts-facade';
import { TextsPage } from './texts-page';
import type { TextRow } from './texts-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ texts: { where: { pdf: 'Documents imprimés', screen: 'Écrans' } } });
  }
}

const TITLE: TextRow = {
  domain: 'pdf',
  key: 'invoice.title',
  original: 'Facture',
  installation: 'Facture de vente',
  company: null,
  version: 0,
  originalChanged: false,
};

/**
 * « Textes » at either level: the list asks the catalogue for what it shows, a text is worded in a dialog and taken
 * back from its row, and each is said in a toast that offers « Annuler ».
 */
describe('TextsPage', () => {
  let fixture: ComponentFixture<TextsPage>;
  let reload: (() => Promise<unknown>) | undefined;
  let opened: TextDialogData | undefined;
  let closedWith: unknown;
  const rows = signal<readonly TextRow[]>([]);
  const facade = {
    rows,
    total: signal(0),
    busy: signal(false),
    failed: signal(false),
    load: vi.fn().mockResolvedValue(undefined),
    write: vi.fn(),
    reset: vi.fn(),
  };

  async function open(level: 'company' | 'installation' = 'company'): Promise<void> {
    reload = undefined;
    opened = undefined;
    closedWith = null;
    rows.set([]);
    facade.load.mockClear();
    facade.write.mockReset();
    facade.reset.mockReset();
    TestBed.configureTestingModule({
      imports: [TextsPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: Session, useValue: { me: () => null } },
        {
          provide: AuthFacade,
          useValue: { me: () => ({ company: { id: 'c1' } }), hasPermission: () => true },
        },
        { provide: TextsFacade, useValue: facade },
        {
          provide: LiveChanges,
          useValue: {
            reloadOn: (kinds: readonly string[], run: () => Promise<unknown>) => {
              if (kinds.includes('text')) reload = run;
            },
            on: () => undefined,
          },
        },
        {
          provide: MatDialog,
          useValue: {
            open: (_component: unknown, config: { data: TextDialogData }) => {
              opened = config.data;
              return { afterClosed: () => of(closedWith) };
            },
          },
        },
      ],
    });
    fixture = TestBed.createComponent(TextsPage);
    fixture.componentRef.setInput('level', level);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function action(id: string): RowAction<{ row: TextRow }> {
    const found = fixture.componentInstance['list']().actions?.find((each) => each.id === id);
    if (found === undefined) throw new Error(`No action ${id}`);
    return found as unknown as RowAction<{ row: TextRow }>;
  }

  function view(row: TextRow): { row: TextRow } {
    rows.set([row]);
    const shown = fixture.componentInstance['views']()[0];
    if (shown === undefined) throw new Error('No row shown');
    return shown;
  }

  it('reads the company’s texts in the screen’s language, the list’s words and filters as the catalogue’s', async () => {
    await open();
    expect(facade.load).toHaveBeenCalledWith(
      { kind: 'company', companyId: 'c1' },
      'fr',
      expect.objectContaining({ words: '', place: null, changed: false, page: 1 }),
    );

    fixture.componentInstance['onQuery']({
      query: 'facture',
      filters: { place: 'pdf', changed: 'changed' },
      sort: null,
      pageIndex: 1,
      pageSize: 50,
    });
    expect(facade.load).toHaveBeenLastCalledWith({ kind: 'company', companyId: 'c1' }, 'fr', {
      words: 'facture',
      place: 'pdf',
      changed: true,
      page: 2,
      itemsPerPage: 50,
    });

    fixture.componentInstance['choose']('ar');
    expect(facade.load).toHaveBeenLastCalledWith(
      { kind: 'company', companyId: 'c1' },
      'ar',
      expect.objectContaining({ words: 'facture', page: 2 }),
    );

    // A text worded otherwise elsewhere, by another tab or person, reads the same page again.
    facade.load.mockClear();
    await reload?.();
    expect(facade.load).toHaveBeenCalledWith(
      { kind: 'company', companyId: 'c1' },
      'ar',
      expect.objectContaining({ words: 'facture', page: 2 }),
    );
  });

  it('reads and writes the installation’s texts on the platform, with no company column', async () => {
    await open('installation');

    expect(facade.load).toHaveBeenCalledWith({ kind: 'installation' }, 'fr', expect.anything());
    const columns = fixture.componentInstance['list']().columns.map((column) => column.id);
    expect(columns).not.toContain('company');
    expect(columns).toContain('installation');
  });

  it('words a text in its dialog, from what the screens show now, and takes it back from the toast', async () => {
    await open();
    closedWith = { value: 'Note', version: 1 };

    action('edit').run?.(view(TITLE));
    await fixture.whenStable();

    expect(opened?.value).toBe('Facture de vente');
    expect(opened?.installation).toBe('Facture de vente');
    expect(opened?.original).toBe('Facture');
    void opened?.save('Note');
    expect(facade.write).toHaveBeenCalledWith(
      { kind: 'company', companyId: 'c1' },
      'fr',
      TITLE,
      'Note',
      0,
    );
    expect(effectToasts()).toEqual(['texts.saved:annulable']);

    // Nothing was worded at this level before: « Annuler » takes the new wording away.
    facade.reset.mockResolvedValue({ kept: true, answer: null });
    offeredNext()?.run();
    await fixture.whenStable();
    expect(facade.reset).toHaveBeenCalledWith({ kind: 'company', companyId: 'c1' }, 'fr', TITLE);
    expect(successToasts()).toContain('texts.undone');
  });

  it('puts back the wording a level had before a change, over the version the change left', async () => {
    await open();
    const mine = { ...TITLE, company: 'Facture client', version: 4 };
    closedWith = { value: 'Note', version: 5 };

    action('edit').run?.(view(mine));
    await fixture.whenStable();
    expect(opened?.value).toBe('Facture client');
    facade.write.mockResolvedValue({ kept: true, answer: { value: 'Facture client', version: 6 } });
    offeredNext()?.run();
    await fixture.whenStable();

    expect(facade.write).toHaveBeenLastCalledWith(
      { kind: 'company', companyId: 'c1' },
      'fr',
      mine,
      'Facture client',
      5,
    );
  });

  it('takes a level’s wording back without a question, and « Annuler » writes it again', async () => {
    await open();
    const mine = { ...TITLE, company: 'Facture client', version: 4 };
    expect(action('reset').shown?.(view(TITLE))).toBe(false);
    expect(action('reset').shown?.(view(mine))).toBe(true);
    expect(action('reset').confirm).toBeUndefined();
    facade.reset.mockResolvedValue({ kept: true, answer: null });

    action('reset').run?.(view(mine));
    await fixture.whenStable();

    expect(facade.reset).toHaveBeenCalledWith({ kind: 'company', companyId: 'c1' }, 'fr', mine);
    expect(effectToasts()).toEqual(['texts.reset_done:annulable']);
    facade.write.mockResolvedValue({ kept: true, answer: { value: 'Facture client', version: 1 } });
    offeredNext()?.run();
    await fixture.whenStable();
    expect(facade.write).toHaveBeenCalledWith(
      { kind: 'company', companyId: 'c1' },
      'fr',
      mine,
      'Facture client',
      0,
    );
  });

  it('offers to keep a wording whose original changed, written again over the version read', async () => {
    await open();
    const flagged = { ...TITLE, company: 'Facture client', version: 4, originalChanged: true };
    expect(action('keep').shown?.(view({ ...flagged, originalChanged: false }))).toBe(false);
    expect(action('keep').shown?.(view(flagged))).toBe(true);
    expect(action('reset').labelOf?.(view(flagged))).toBe('texts.actions.take_original');
    facade.write.mockResolvedValueOnce({
      kept: true,
      answer: { value: 'Facture client', version: 5 },
    });

    action('keep').run?.(view(flagged));
    await fixture.whenStable();

    expect(facade.write).toHaveBeenCalledWith(
      { kind: 'company', companyId: 'c1' },
      'fr',
      flagged,
      'Facture client',
      4,
    );
    expect(successToasts()).toEqual(['texts.kept']);

    facade.write.mockResolvedValueOnce({ kept: false, refusal: 'changed' });
    action('keep').run?.(view(flagged));
    await fixture.whenStable();
    expect(failureToasts()).toEqual(['texts.refusals.changed']);
  });
});
