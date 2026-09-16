// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { MaterialFeedback, SUCCESS_DURATION_MS } from './material-feedback';
import { Toast } from './toast';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      saved: 'Client enregistré : {{name}}',
      refused: 'Suppression impossible',
      feedback: { close: 'Fermer' },
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
