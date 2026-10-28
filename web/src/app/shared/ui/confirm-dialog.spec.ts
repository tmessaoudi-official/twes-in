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
        kind: {
          annulable: 'Annulable',
          corrigeable: 'Corrigeable',
          definitif: 'Définitif',
          definitif_a_terme: 'Définitif à terme',
        },
        kind_help: {
          annulable: 'Vous pourrez revenir en arrière depuis le message qui suit.',
          corrigeable: 'Cela ne s’annule pas ; une erreur se corrige ensuite.',
          definitif: 'Cela ne pourra être ni annulé ni corrigé.',
          definitif_a_terme:
            'Vous pourrez revenir en arrière pendant un délai ; ensuite, c’est définitif.',
        },
        typed: 'Pour confirmer, tapez « {{word}} ».',
      },
      d: {
        title: 'Émettre ?',
        message: 'Elle reçoit son numéro.',
        run: 'Émettre',
        keep: 'Garder',
        word: 'SUPPRIMER',
        reason: 'Motif (facultatif)',
        reason_hint: 'Il figure dans le courrier envoyé.',
      },
    });
  }
}

// docs/SPEC.md § 7, 2026-09-25 22:17: every consequential action says whether it can be taken back, before it runs.
describe('ConfirmDialog', () => {
  const close = vi.fn();

  function render(
    kind: ActionConfirm['kind'],
    typed?: ActionConfirm['typed'],
    note?: ActionConfirm['note'],
  ): HTMLElement {
    close.mockReset();
    TestBed.configureTestingModule({
      imports: [ConfirmDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close } },
        {
          provide: MAT_DIALOG_DATA,
          useValue: {
            kind,
            title: 'd.title',
            message: 'd.message',
            confirmLabel: 'd.run',
            keepLabel: 'd.keep',
            ...(typed === undefined ? {} : { typed }),
            ...(note === undefined ? {} : { note }),
          } satisfies ActionConfirm,
        },
      ],
    });
    const fixture = TestBed.createComponent(ConfirmDialog);
    fixture.detectChanges();
    rendered = fixture;
    return fixture.nativeElement as HTMLElement;
  }

  let rendered: { detectChanges(): void } | undefined;

  const run = (root: HTMLElement) =>
    root.querySelector<HTMLButtonElement>('[data-testid="confirm-run"]');

  function type(root: HTMLElement, text: string): void {
    const field = root.querySelector<HTMLInputElement>('[data-testid="confirm-typed"]');
    if (field === null) throw new Error('no typed field');
    field.value = text;
    field.dispatchEvent(new Event('input'));
    rendered?.detectChanges();
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

  // § 7 2026-10-13 17:01: what cannot be taken back, now or after a delay, is drawn as danger, and nothing else is.
  it.each(['definitif', 'definitif_a_terme'] as const)(
    'draws a %s action in the danger colour',
    (kind) => {
      expect(run(render(kind))?.classList).toContain('twes-danger');
    },
  );

  it.each(['annulable', 'corrigeable'] as const)(
    'keeps a %s action in the primary colour',
    (kind) => {
      expect(run(render(kind))?.classList).not.toContain('twes-danger');
    },
  );

  it('says what « Définitif à terme » means, with its hourglass', () => {
    const kind = kindOf(render('definitif_a_terme'));
    expect(kind?.textContent).toContain('Définitif à terme');
    expect(kind?.textContent).toContain('pendant un délai');
    expect(kind?.querySelector('mat-icon')?.textContent?.trim()).toBe('hourglass_top');
  });

  describe('a typed confirmation', () => {
    it('asks for the word and refuses until it is typed', () => {
      const root = render('definitif_a_terme', { word: 'd.word' });
      expect(root.textContent).toContain('Pour confirmer, tapez « SUPPRIMER ».');
      expect(run(root)?.disabled).toBe(true);
      type(root, 'SUPPRIME');
      expect(run(root)?.disabled).toBe(true);
    });

    it('takes the word whatever its case and the spaces around it', () => {
      const root = render('definitif_a_terme', { word: 'd.word' });
      type(root, '  supprimer ');
      expect(run(root)?.disabled).toBe(false);
      run(root)?.click();
      expect(close).toHaveBeenCalledWith(true);
    });

    it('takes a company name with either apostrophe, any case and its spaces collapsed', () => {
      const root = render('definitif', { name: 'Atelier L’Étoile  du Sud' });
      expect(root.textContent).toContain('« Atelier L’Étoile  du Sud »');
      type(root, "atelier l'étoile du sud");
      expect(run(root)?.disabled).toBe(false);
    });

    it('confirms on Enter only once the word matches', () => {
      const root = render('definitif_a_terme', { word: 'd.word' });
      const field = root.querySelector<HTMLInputElement>('[data-testid="confirm-typed"]');
      type(root, 'non');
      field?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
      expect(close).not.toHaveBeenCalled();
      type(root, 'supprimer');
      field?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
      expect(close).toHaveBeenCalledWith(true);
    });

    it('asks nothing to be typed when the action does not say so', () => {
      const root = render('definitif');
      expect(root.querySelector('[data-testid="confirm-typed"]')).toBeNull();
      expect(run(root)?.disabled).toBe(false);
    });
  });

  // A reason the person may give with the action, which the mail it sends says (docs/SPEC.md § 7, 2026-10-27 21:37).
  describe('a note', () => {
    const note = { label: 'd.reason', hint: 'd.reason_hint', maxLength: 500 };
    const field = (root: HTMLElement) =>
      root.querySelector<HTMLTextAreaElement>('[data-testid="confirm-note"]');

    it('offers a field that may stay empty, says what becomes of it and how much it holds', () => {
      const root = render('corrigeable', undefined, note);
      expect(field(root)?.maxLength).toBe(500);
      expect(root.textContent).toContain('Motif (facultatif)');
      expect(root.textContent).toContain('Il figure dans le courrier envoyé.');
      expect(root.textContent).toContain('0 / 500');
      expect(run(root)?.disabled).toBe(false);

      run(root)?.click();
      expect(close).toHaveBeenCalledWith('');
    });

    it('answers with what was written', () => {
      const root = render('corrigeable', undefined, note);
      const area = field(root)!;
      area.value = 'Facture impayée';
      area.dispatchEvent(new Event('input'));
      rendered?.detectChanges();
      expect(root.textContent).toContain('15 / 500');

      run(root)?.click();
      expect(close).toHaveBeenCalledWith('Facture impayée');
    });

    it('keeps answering no when the person keeps things as they are', () => {
      const root = render('corrigeable', undefined, note);
      root.querySelector<HTMLButtonElement>('[data-testid="confirm-keep"]')?.click();
      expect(close).toHaveBeenCalledWith(false);
    });

    it('asks no note when the action does not say so', () => {
      expect(field(render('corrigeable'))).toBeNull();
    });
  });
});
