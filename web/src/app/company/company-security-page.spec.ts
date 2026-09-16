// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import type { CompanySecurity, CompanySecurityError } from './company-security-api';
import { CompanySecurityFacade } from './company-security-facade';
import { CompanySecurityPage } from './company-security-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      company: {
        security: {
          title: 'Sécurité',
          mfa_required: 'Exiger la vérification en deux étapes',
          saved: 'Enregistré',
          errors: { not_found: 'Entreprise introuvable' },
        },
      },
    });
  }
}

describe('CompanySecurityPage', () => {
  const security = signal<CompanySecurity | null>(null);
  const error = signal<CompanySecurityError | null>(null);
  const facade = {
    security: security.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    load: vi.fn(),
    requireSecondFactor: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    needsEnrolment: vi.fn(),
  };
  let fixture: ComponentFixture<CompanySecurityPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const theSwitch = (): HTMLButtonElement =>
    q('security-mfa-toggle')!.querySelector('button[role="switch"]') as HTMLButtonElement;

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(CompanySecurityPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    security.set({ mfaRequired: false, writable: true });
    error.set(null);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.requireSecondFactor.mockReset().mockResolvedValue(true);
    auth.needsEnrolment.mockReset().mockReturnValue(false);
    TestBed.configureTestingModule({
      imports: [CompanySecurityPage],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: CompanySecurityFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
  });

  it('loads what the working company requires and shows it as a switch', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1');
    expect(theSwitch().getAttribute('aria-checked')).toBe('false');
    expect(theSwitch().disabled).toBe(false);
  });

  it('shows the switch but lets nobody without the permission change it', async () => {
    security.set({ mfaRequired: true, writable: false });
    await open();

    expect(theSwitch().getAttribute('aria-checked')).toBe('true');
    expect(theSwitch().disabled).toBe(true);
  });

  it('turning it on sends an account without a second factor to set one up', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    facade.requireSecondFactor.mockImplementation(async () => {
      security.set({ mfaRequired: true, writable: true });
      return true;
    });
    auth.needsEnrolment.mockReturnValue(true);
    await open();

    theSwitch().click();
    await settle();

    expect(facade.requireSecondFactor).toHaveBeenCalledWith('c1', true);
    expect(navigate).toHaveBeenCalledWith('/two-factor');
  });

  it('turning it on keeps an account that already has a second factor on the page', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    await open();

    theSwitch().click();
    await settle();

    expect(q('security-saved')).not.toBeNull();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('a refused change says why and puts the switch back', async () => {
    facade.requireSecondFactor.mockImplementation(async () => {
      error.set('not_found');
      return false;
    });
    await open();

    theSwitch().click();
    await settle();

    expect(q('security-error')?.textContent).toContain('Entreprise introuvable');
    expect(theSwitch().getAttribute('aria-checked')).toBe('false');
  });
});
