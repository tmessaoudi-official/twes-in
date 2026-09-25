// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import type { ActionConfirm } from '../actions/screen-action';
import { ConfirmDialog } from './confirm-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      actions: {
        kind: { annulable: 'Annulable', corrigeable: 'Corrigeable', definitif: 'Définitif' },
        kind_help: {
          annulable: 'Vous pourrez revenir en arrière depuis le message qui suit.',
          corrigeable: 'Cela ne s’annule pas ; une erreur se corrige ensuite.',
          definitif: 'Cela ne pourra être ni annulé ni corrigé.',
        },
      },
      d: { title: 'Émettre ?', message: 'Elle reçoit son numéro.', run: 'Émettre', keep: 'Garder' },
    });
  }
}

// docs/SPEC.md § 7, 2026-09-25 22:17: every consequential action says whether it can be taken back, before it runs.
describe('ConfirmDialog', () => {
  function render(kind: ActionConfirm['kind']): HTMLElement {
    TestBed.configureTestingModule({
      imports: [ConfirmDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close: vi.fn() } },
        {
          provide: MAT_DIALOG_DATA,
          useValue: {
            kind,
            title: 'd.title',
            message: 'd.message',
            confirmLabel: 'd.run',
            keepLabel: 'd.keep',
          } satisfies ActionConfirm,
        },
      ],
    });
    const fixture = TestBed.createComponent(ConfirmDialog);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const kindOf = (root: HTMLElement) =>
    root.querySelector<HTMLElement>('[data-testid="confirm-kind"]');

  it('says a corrected-afterwards action is « Corrigeable », and what that means', () => {
    const kind = kindOf(render('corrigeable'));
    expect(kind?.getAttribute('data-kind')).toBe('corrigeable');
    expect(kind?.textContent).toContain('Corrigeable');
    expect(kind?.textContent).toContain('une erreur se corrige ensuite');
  });

  it('says a final action is « Définitif », apart from the question', () => {
    const root = render('definitif');
    const kind = kindOf(root);
    expect(kind?.textContent).toContain('Définitif');
    expect(kind?.textContent).toContain('ni annulé ni corrigé');
    expect(root.querySelector('[data-testid="confirm-message"]')?.textContent).not.toContain(
      'Définitif',
    );
  });

  it('marks its kind for the tours', () => {
    expect(kindOf(render('annulable'))?.getAttribute('data-tour')).toBe('action-kind');
  });
});
