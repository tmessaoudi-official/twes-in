// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { ActionConfirm } from '../shared/actions/screen-action';
import { TextsParts } from '../shared/i18n/texts-part';
import { Feedback } from '../shared/feedback/feedback';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { refusedByTheDemo } from '../shared/testing/said-answers';
import { InventoryRefused } from './inventory-api';
import { InventoryFacade } from './inventory-facade';
import type { StockMoveFailureRow } from './inventory-types';
import { StockMoveFailures } from './stock-move-failures';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      stock_moves: {
        failed: { delivery_note: 'Le stock n’a pas bougé, le {{moment}}.' },
        replay: 'Remettre le stock à jour',
        settle: 'C’est réglé',
        ask_writer: 'Une personne qui gère le stock peut le remettre à jour.',
      },
    });
  }
}

const failure: StockMoveFailureRow = {
  id: 'f1',
  move: 'delivery_note',
  documentId: 'd1',
  number: 'BL-0007',
  failedAt: '2026-10-26T03:10:00+00:00',
};

// Workflow hunt, CON-1: a failed stock move is put right from the document's page, never from a console.
describe('StockMoveFailures', () => {
  let permissions: string[];
  let answer: boolean | undefined;
  const asked: ActionConfirm[] = [];
  const facade = {
    failedMoves:
      vi.fn<(companyId: string, documentId: string) => Promise<StockMoveFailureRow[] | null>>(),
    replayMove: vi.fn<(companyId: string, failureId: string) => Promise<boolean>>(),
    settleMove: vi.fn<(companyId: string, failureId: string) => Promise<void>>(),
  };
  const dialog = {
    open: vi.fn((_component: unknown, config: { data: ActionConfirm }) => {
      asked.push(config.data);
      return { afterClosed: () => of(answer) };
    }),
  };
  const texts = { open: vi.fn() };
  let fixture: ComponentFixture<StockMoveFailures>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const said = (): RecordedFeedback['said'] => (TestBed.inject(Feedback) as RecordedFeedback).said;

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(StockMoveFailures);
    fixture.componentRef.setInput('documentId', 'd1');
    fixture.detectChanges();
    await settled();
  }

  async function settled(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve));
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    permissions = ['stock.read', 'stock.write'];
    answer = true;
    asked.length = 0;
    facade.failedMoves.mockReset().mockResolvedValue([failure]);
    facade.replayMove.mockReset().mockResolvedValue(true);
    facade.settleMove.mockReset().mockResolvedValue(undefined);
    dialog.open.mockClear();
    texts.open.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [StockMoveFailures],
      providers: [
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        provideQuietFeedback(),
        { provide: InventoryFacade, useValue: facade },
        { provide: MatDialog, useValue: dialog },
        { provide: TextsParts, useValue: texts },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
            hasModule: (key: string) => key === 'inventory',
            hasPermission: (permission: string) => permissions.includes(permission),
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('says a recorded failure with what puts it right, in its own texts', async () => {
    await open();

    expect(texts.open).toHaveBeenCalledWith('stock-moves');
    expect(facade.failedMoves).toHaveBeenCalledWith('c1', 'd1');
    expect(q('stock-move-failure')?.textContent).toContain('Le stock n’a pas bougé');
    expect(q('stock-move-replay')).not.toBeNull();
    expect(q('stock-move-settle')).not.toBeNull();
  });

  it('shows nothing where no failure is recorded', async () => {
    facade.failedMoves.mockResolvedValue([]);
    await open();

    expect(q('stock-move-failures')).toBeNull();
  });

  it('asks nothing of whoever cannot read the stock', async () => {
    permissions = [];
    await open();

    expect(facade.failedMoves).not.toHaveBeenCalled();
    expect(q('stock-move-failures')).toBeNull();
  });

  it('offers a stock reader no action, and says who can', async () => {
    permissions = ['stock.read'];
    await open();

    expect(q('stock-move-replay')).toBeNull();
    expect(q('stock-move-settle')).toBeNull();
    expect(q('stock-move-ask')).not.toBeNull();
  });

  it('moves the stock again, says so, and reads the failures again', async () => {
    await open();
    facade.failedMoves.mockResolvedValue([]);

    q('stock-move-replay')?.click();
    await settled();

    expect(facade.replayMove).toHaveBeenCalledWith('c1', 'f1');
    expect(said()).toEqual([
      { kind: 'success', key: 'stock_moves.replayed', params: { number: 'BL-0007' } },
    ]);
    expect(q('stock-move-failures')).toBeNull();
  });

  it('says a move that failed again, and keeps it', async () => {
    facade.replayMove.mockResolvedValue(false);
    await open();

    q('stock-move-replay')?.click();
    await settled();

    expect(said()).toEqual([
      { kind: 'failure', key: 'stock_moves.failed_again', params: { number: 'BL-0007' } },
    ]);
    expect(q('stock-move-failure')).not.toBeNull();
  });

  it('says a refusal once, unless it was said already', async () => {
    facade.replayMove.mockRejectedValueOnce(new InventoryRefused('not_found'));
    await open();
    q('stock-move-replay')?.click();
    await settled();
    expect(said().map((toast) => toast.key)).toEqual(['stock_moves.refused']);

    facade.replayMove.mockRejectedValueOnce(refusedByTheDemo(new InventoryRefused('invalid')));
    q('stock-move-replay')?.click();
    await settled();
    expect(said().map((toast) => toast.key)).toEqual(['stock_moves.refused']);
  });

  it('asks before forgetting a failure, as correctable, and forgets it only on yes', async () => {
    await open();

    answer = false;
    q('stock-move-settle')?.click();
    await settled();
    expect(asked.map((confirm) => [confirm.kind, confirm.messageParams])).toEqual([
      ['corrigeable', { number: 'BL-0007' }],
    ]);
    expect(facade.settleMove).not.toHaveBeenCalled();

    answer = true;
    facade.failedMoves.mockResolvedValue([]);
    q('stock-move-settle')?.click();
    await settled();
    expect(facade.settleMove).toHaveBeenCalledWith('c1', 'f1');
    expect(said()).toEqual([
      {
        kind: 'success',
        key: 'stock_moves.settled',
        params: { number: 'BL-0007' },
        effect: 'corrigeable',
      },
    ]);
    expect(q('stock-move-failures')).toBeNull();
  });

  it('reads itself again when the stock changes elsewhere', async () => {
    await open();
    facade.failedMoves.mockResolvedValue([]);

    await announceSaved('stock', 'd1');
    await settled();

    expect(q('stock-move-failures')).toBeNull();
  });
});
