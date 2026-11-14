// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideTranslateLoader, provideTranslateService } from '@ngx-translate/core';
import type { TranslateLoader } from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepUpDialog } from './step-up-dialog';
import { type ProofAsked, type StepUpOutcome, StepUpProof } from './step-up-proof';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      feedback: { unknown_refusal: 'Refusé (code : {{code}}).' },
      step_up: {
        title: 'Confirmez',
        intro: 'Pour quitter cette vue.',
        intro_export: 'Pour télécharger cette liste.',
        code: 'Code',
        passkey_only: 'Votre clé d’accès suffit.',
        passkey_unavailable: 'Ce navigateur n’en a pas.',
        errors: {
          refused: 'Mauvais mot de passe.',
          no_passkey: 'Aucune clé.',
          code_refused: 'Mauvais code.',
          passkey_required: 'Clé d’accès demandée.',
        },
      },
    });
  }
}

describe('StepUpDialog', () => {
  const close = vi.fn();
  const passwordAlone: ProofAsked = { passkey: true, code: false, password: true };
  const proof = {
    proofAsked: signal<ProofAsked>(passwordAlone),
    passkeySupported: vi.fn(),
    withPassword: vi.fn<(password: string, code: string | null) => Promise<StepUpOutcome>>(),
    withPasskey: vi.fn<() => Promise<StepUpOutcome>>(),
  };
  let fixture: ComponentFixture<StepUpDialog>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(value: string, testId = 'step-up-password'): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  async function open(data: unknown): Promise<void> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [StepUpDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close } },
        { provide: MAT_DIALOG_DATA, useValue: data },
        { provide: StepUpProof, useValue: proof },
        provideRouter([]),
      ],
    });
    fixture = TestBed.createComponent(StepUpDialog);
    await settle();
  }

  beforeEach(async () => {
    close.mockReset();
    proof.proofAsked.set(passwordAlone);
    proof.passkeySupported.mockReset().mockReturnValue(true);
    proof.withPassword.mockReset().mockResolvedValue('confirmed');
    proof.withPasskey.mockReset().mockResolvedValue('confirmed');
    await open(undefined);
  });

  it('says why it asks: leaving customer view unless told otherwise, and an export when asked for one', async () => {
    expect(q('step-up-intro')?.textContent?.trim()).toBe('Pour quitter cette vue.');

    await open({ intro: 'step_up.intro_export' });

    expect(q('step-up-intro')?.textContent?.trim()).toBe('Pour télécharger cette liste.');
  });

  it('closes confirmed once the password is the account’s', async () => {
    type('le-bon');
    q('step-up-confirm')!.click();
    await settle();

    expect(proof.withPassword).toHaveBeenCalledWith('le-bon', null);
    expect(close).toHaveBeenCalledWith(true);
  });

  it('says a wrong password, stays open, and sends nothing for an empty one', async () => {
    proof.withPassword.mockResolvedValueOnce('refused');
    type('mauvais');
    // Pressed with the mouse: the cursor is on the button.
    q('step-up-confirm')!.focus();
    q('step-up-confirm')!.click();
    await settle();

    expect(q('step-up-error')?.textContent).toContain('Mauvais mot de passe.');
    expect(close).not.toHaveBeenCalled();
    // The password is typed again at once, over the one refused.
    const field = q('step-up-password') as HTMLInputElement;
    expect(document.activeElement).toBe(field);
    expect([field.selectionStart, field.selectionEnd]).toEqual([0, 'mauvais'.length]);

    type('');
    q('step-up-confirm')!.click();
    await settle();
    expect(proof.withPassword).toHaveBeenCalledTimes(1);
  });

  // A server failing during the proof is never said as a wrong password.
  it('says a proof a server failed by its status, staying open', async () => {
    proof.withPassword.mockResolvedValueOnce({ unknown: '500' });
    type('le-bon');
    q('step-up-confirm')!.click();
    await settle();

    expect(q('step-up-error')?.textContent).toContain('Refusé (code : 500).');
    expect(q('step-up-error')?.textContent).not.toContain('Mauvais mot de passe.');
    expect(close).not.toHaveBeenCalled();
  });

  it('offers a passkey only where the browser has them, and closes confirmed on one', async () => {
    q('step-up-passkey')!.click();
    await settle();
    expect(proof.withPasskey).toHaveBeenCalled();
    expect(close).toHaveBeenCalledWith(true);

    proof.passkeySupported.mockReturnValue(false);
    fixture = TestBed.createComponent(StepUpDialog);
    await settle();
    expect(q('step-up-passkey')).toBeNull();
  });

  it('says so when no passkey could be used and leaves it open; giving up closes unconfirmed', async () => {
    proof.withPasskey.mockResolvedValueOnce('no_passkey');
    q('step-up-passkey')!.click();
    await settle();
    expect(q('step-up-error')?.textContent).toContain('Aucune clé.');
    expect(close).not.toHaveBeenCalled();

    q('step-up-cancel')!.click();
    expect(close).toHaveBeenCalledWith(false);
  });

  it('asks the authenticator code beside the password where a second factor is required, and says a missing one', async () => {
    proof.proofAsked.set({ passkey: false, code: true, password: true });
    await open(undefined);
    // The account holds no passkey: none is offered, whatever the browser can do.
    expect(q('step-up-passkey')).toBeNull();

    type('le-bon');
    q('step-up-confirm')!.click();
    await settle();
    expect(q('step-up-error')?.textContent).toContain('Mauvais code.');
    expect(proof.withPassword).not.toHaveBeenCalled();

    type('123456', 'step-up-code');
    q('step-up-confirm')!.click();
    await settle();
    expect(proof.withPassword).toHaveBeenCalledWith('le-bon', '123456');
    expect(close).toHaveBeenCalledWith(true);
  });

  it('asks only the passkey where it is the second factor required, and says a browser that has none', async () => {
    proof.proofAsked.set({ passkey: true, code: false, password: false });
    await open(undefined);

    expect(q('step-up-password')).toBeNull();
    expect(q('step-up-confirm')).toBeNull();
    expect(q('step-up-passkey-only')?.textContent).toContain('Votre clé d’accès suffit.');
    q('step-up-passkey')!.click();
    await settle();
    expect(close).toHaveBeenCalledWith(true);

    proof.passkeySupported.mockReturnValue(false);
    await open(undefined);
    expect(q('step-up-passkey')).toBeNull();
    expect(q('step-up-passkey-only')?.textContent).toContain('Ce navigateur n’en a pas.');
  });

  // The session read before a company of the account started requiring a second factor: the API's answer says what to give.
  it('asks the code once the API says one is required, and the passkey once it is the only way', async () => {
    proof.passkeySupported.mockReturnValue(false);
    proof.withPassword.mockResolvedValueOnce('code_refused');
    type('le-bon');
    q('step-up-confirm')!.click();
    await settle();
    expect(q('step-up-error')?.textContent).toContain('Mauvais code.');
    // The field the refusal opened takes the cursor once drawn, so the code is typed straight away.
    await vi.waitFor(() => expect(document.activeElement).toBe(q('step-up-code')));

    proof.passkeySupported.mockReturnValue(true);
    proof.proofAsked.set({ passkey: false, code: false, password: true });
    await open(undefined);
    proof.withPassword.mockResolvedValueOnce('passkey_required');
    type('le-bon');
    q('step-up-confirm')!.click();
    await settle();
    expect(q('step-up-error')?.textContent).toContain('Clé d’accès demandée.');
    expect(q('step-up-passkey')).not.toBeNull();
  });

  it('closes unconfirmed and goes to the sign-in once the wrong answers ended the session', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    proof.withPassword.mockResolvedValueOnce('signed_out');
    type('cinquième');
    q('step-up-confirm')!.click();
    await settle();

    expect(close).toHaveBeenCalledWith(false);
    expect(navigate).toHaveBeenCalledWith('/login?expired=1');
  });
});
