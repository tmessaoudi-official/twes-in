// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import { provideTranslateLoader, provideTranslateService } from '@ngx-translate/core';
import type { TranslateLoader } from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CompanyProofDialog } from './company-proof-dialog';
import { CompanyEntryProof, type EntryAsked, type EntryOutcome } from './company-entry-proof';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      step_up: { errors: { refused: 'Mauvais mot de passe.' } },
      company_proof: {
        title: 'Entrer dans {{company}}',
        errors: { code_refused: 'Mauvais code.' },
      },
    });
  }
}

describe('CompanyProofDialog', () => {
  const close = vi.fn();
  const proof = {
    asked: signal(null),
    passkeySupported: vi.fn(),
    enterWithPassword:
      vi.fn<(id: string, password: string, code: string | null) => Promise<EntryOutcome>>(),
    enterWithPasskey: vi.fn<(id: string) => Promise<EntryOutcome>>(),
    reread: vi.fn(),
  };
  let fixture: ComponentFixture<CompanyProofDialog>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  async function submit(): Promise<void> {
    q('company-proof-confirm')?.click();
    await settle();
  }

  async function open(asked: EntryAsked): Promise<void> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [CompanyProofDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close } },
        { provide: MAT_DIALOG_DATA, useValue: asked },
        { provide: CompanyEntryProof, useValue: proof },
        provideRouter([]),
      ],
    });
    fixture = TestBed.createComponent(CompanyProofDialog);
    await settle();
  }

  const password: EntryAsked = {
    companyId: 'c2',
    companyName: 'Globex',
    passkey: false,
    code: false,
  };

  beforeEach(() => {
    close.mockReset();
    proof.passkeySupported.mockReset().mockReturnValue(true);
    proof.enterWithPassword.mockReset().mockResolvedValue('confirmed');
    proof.enterWithPasskey.mockReset().mockResolvedValue('confirmed');
  });

  it('names the company and asks the password alone where the company asks nothing more', async () => {
    await open(password);

    expect(q('company-proof-title')?.textContent).toContain('Entrer dans Globex');
    expect(q('company-proof-code')).toBeNull();
    expect(q('company-proof-passkey')).toBeNull();
    type('company-proof-password', 'secret');
    await submit();

    expect(proof.enterWithPassword).toHaveBeenCalledExactlyOnceWith('c2', 'secret', null);
    expect(close).toHaveBeenCalledExactlyOnceWith(true);
  });

  it('asks the authenticator code beside the password where the company requires one, and says a missing one', async () => {
    await open({ ...password, code: true });
    type('company-proof-password', 'secret');
    await submit();

    expect(q('company-proof-error')?.textContent).toContain('Mauvais code.');
    expect(proof.enterWithPassword).not.toHaveBeenCalled();

    type('company-proof-code', '123 456');
    await submit();
    expect(proof.enterWithPassword).toHaveBeenCalledExactlyOnceWith('c2', 'secret', '123456');
  });

  it('says a refusal and stays open, so a mistyped answer is tried again', async () => {
    proof.enterWithPassword.mockResolvedValue('code_refused');
    await open({ ...password, code: true });
    type('company-proof-password', 'secret');
    type('company-proof-code', '000000');
    await submit();

    expect(q('company-proof-error')?.textContent).toContain('Mauvais code.');
    expect(close).not.toHaveBeenCalled();

    proof.enterWithPassword.mockResolvedValue('refused');
    await submit();
    expect(q('company-proof-error')?.textContent).toContain('Mauvais mot de passe.');
  });

  it('offers a passkey only where the company takes one and the browser can answer', async () => {
    await open({ ...password, passkey: true });
    q('company-proof-passkey')?.click();
    await settle();
    expect(proof.enterWithPasskey).toHaveBeenCalledExactlyOnceWith('c2');
    expect(close).toHaveBeenCalledExactlyOnceWith(true);

    proof.passkeySupported.mockReturnValue(false);
    await open({ ...password, passkey: true });
    expect(q('company-proof-passkey')).toBeNull();
  });

  it('gives up without a proof when closed', async () => {
    await open(password);
    q('company-proof-cancel')?.click();
    expect(close).toHaveBeenCalledExactlyOnceWith(false);
    expect(proof.enterWithPassword).not.toHaveBeenCalled();
  });
});
