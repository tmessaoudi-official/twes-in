// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideTranslateLoader, provideTranslateService } from '@ngx-translate/core';
import type { TranslateLoader } from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepUpDialog } from './step-up-dialog';
import { type StepUpOutcome, StepUpProof } from './step-up-proof';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      step_up: {
        title: 'Confirmez',
        intro: 'Pour quitter cette vue.',
        intro_export: 'Pour télécharger cette liste.',
        errors: { refused: 'Mauvais mot de passe.', no_passkey: 'Aucune clé.' },
      },
    });
  }
}

describe('StepUpDialog', () => {
  const close = vi.fn();
  const proof = {
    passkeySupported: vi.fn(),
    withPassword: vi.fn<(password: string) => Promise<StepUpOutcome>>(),
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

  function type(value: string): void {
    const input = q('step-up-password') as HTMLInputElement;
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

    expect(proof.withPassword).toHaveBeenCalledWith('le-bon');
    expect(close).toHaveBeenCalledWith(true);
  });

  it('says a wrong password, stays open, and sends nothing for an empty one', async () => {
    proof.withPassword.mockResolvedValueOnce('refused');
    type('mauvais');
    q('step-up-confirm')!.click();
    await settle();

    expect(q('step-up-error')?.textContent).toContain('Mauvais mot de passe.');
    expect(close).not.toHaveBeenCalled();

    type('');
    q('step-up-confirm')!.click();
    await settle();
    expect(proof.withPassword).toHaveBeenCalledTimes(1);
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
