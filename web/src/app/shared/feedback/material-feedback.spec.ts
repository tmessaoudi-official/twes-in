// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import {
  ACTION_DURATION_MS,
  MaterialFeedback,
  NOTICE_DURATION_MS,
  SUCCESS_DURATION_MS,
} from './material-feedback';
import { Toast } from './toast';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      saved: 'Client enregistré : {{name}}',
      refused: 'Suppression impossible',
      feedback: { close: 'Fermer' },
      undo: 'Annuler',
      issued: 'Facture émise',
      actions: { kind: { corrigeable: 'Corrigeable', definitif: 'Définitif' } },
    });
  }
}

describe('MaterialFeedback', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({
          lang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    });
  });

  it('says a success politely, briefly, away from the phone bottom bar', () => {
    const open = vi.spyOn(TestBed.inject(MatSnackBar), 'openFromComponent');
    TestBed.inject(MaterialFeedback).success('saved', { name: 'Acme' });

    expect(open).toHaveBeenCalledWith(
      Toast,
      expect.objectContaining({
        data: { kind: 'success', key: 'saved', params: { name: 'Acme' } },
        politeness: 'polite',
        duration: SUCCESS_DURATION_MS,
        verticalPosition: 'top',
      }),
    );
  });

  it('says a failure at once and keeps it until it is closed', () => {
    const open = vi.spyOn(TestBed.inject(MatSnackBar), 'openFromComponent');
    TestBed.inject(MaterialFeedback).failure('refused');

    const config = open.mock.calls[0]?.[1];
    expect(config?.politeness).toBe('assertive');
    expect(config?.duration).toBeUndefined();
    expect(config?.data).toEqual({ kind: 'failure', key: 'refused', params: {} });
  });

  it('says what someone else changed politely, a little longer, with its own look', () => {
    const open = vi.spyOn(TestBed.inject(MatSnackBar), 'openFromComponent');
    TestBed.inject(MaterialFeedback).notice('saved', { name: 'Acme' });

    const config = open.mock.calls[0]?.[1];
    expect(config?.politeness).toBe('polite');
    expect(config?.duration).toBe(NOTICE_DURATION_MS);
    expect(NOTICE_DURATION_MS).toBeGreaterThan(SUCCESS_DURATION_MS);
    expect(config?.data).toEqual({ kind: 'notice', key: 'saved', params: { name: 'Acme' } });
    expect(config?.panelClass).toContain('twes-toast-notice');
  });

  it('offers a success its one follow-up, runs it once and closes', async () => {
    const run = vi.fn();
    TestBed.inject(MaterialFeedback).success('saved', { name: 'Acme' }, { key: 'undo', run });
    await vi.waitFor(() =>
      expect(document.querySelector('[data-testid="toast-action"]')).not.toBeNull(),
    );
    const button = document.querySelector<HTMLButtonElement>('[data-testid="toast-action"]');

    expect(button?.textContent?.trim()).toBe('Annuler');
    expect(ACTION_DURATION_MS).toBeGreaterThan(SUCCESS_DURATION_MS);
    button?.click();
    expect(run).toHaveBeenCalledTimes(1);
    await vi.waitFor(() => expect(document.querySelector('[data-testid="toast"]')).toBeNull());
  });

  it('says what was done and whether it can be taken back, in the words the confirmation used', () => {
    const open = vi.spyOn(TestBed.inject(MatSnackBar), 'openFromComponent');
    TestBed.inject(MaterialFeedback).effect('issued', {}, 'corrigeable');

    const config = open.mock.calls[0]?.[1];
    expect(config?.politeness).toBe('polite');
    expect(config?.duration).toBe(SUCCESS_DURATION_MS);
    expect(config?.data).toEqual({
      kind: 'success',
      key: 'issued',
      params: {},
      effect: 'corrigeable',
    });
  });

  it('offers what was just done its next step, and stays long enough to reach it', () => {
    const open = vi.spyOn(TestBed.inject(MatSnackBar), 'openFromComponent');
    const next = { key: 'invoices.suggest.record_payment', run: vi.fn() };
    TestBed.inject(MaterialFeedback).effect('issued', {}, 'corrigeable', next);

    const config = open.mock.calls[0]?.[1];
    expect(config?.duration).toBe(ACTION_DURATION_MS);
    expect(config?.data).toEqual({
      kind: 'success',
      key: 'issued',
      params: {},
      effect: 'corrigeable',
      action: next,
    });
  });

  it('draws the kind after the message, with its icon', async () => {
    TestBed.inject(MaterialFeedback).effect('issued', {}, 'definitif');
    await vi.waitFor(() =>
      expect(document.querySelector('[data-testid="toast-effect"]')).not.toBeNull(),
    );
    const effect = document.querySelector<HTMLElement>('[data-testid="toast-effect"]');
    expect(effect?.getAttribute('data-kind')).toBe('definitif');
    expect(effect?.textContent).toContain('Définitif');
    expect(effect?.textContent).toContain('lock');
    expect(document.querySelector('[data-testid="toast"]')?.textContent).toContain('Facture émise');
  });

  it('shows the translated message with a named way to close it', async () => {
    TestBed.inject(MaterialFeedback).success('saved', { name: 'Acme' });
    await vi.waitFor(() => expect(document.querySelector('[data-testid="toast"]')).not.toBeNull());
    const toast = document.querySelector<HTMLElement>('[data-testid="toast"]');

    expect(toast?.getAttribute('data-kind')).toBe('success');
    expect(toast?.textContent).toContain('Client enregistré : Acme');
    expect(document.querySelector('[data-testid="toast-close"]')?.getAttribute('aria-label')).toBe(
      'Fermer',
    );
  });
});
