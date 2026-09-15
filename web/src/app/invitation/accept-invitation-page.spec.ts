// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AcceptInvitationPage } from './accept-invitation-page';
import { InvitationFacade } from './invitation-facade';
import type { InvitationError, InvitationOffer } from './invitation-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      roles: { member: 'membre' },
      invitation: {
        title: 'Rejoindre une entreprise',
        offer: 'Invitation à rejoindre {{company}} en tant que {{role}}.',
        has_account: 'Cette adresse a déjà un compte.',
        join: "Rejoindre l'entreprise",
        display_name: 'Votre nom',
        password: 'Mot de passe',
        password_hint: '12 caractères',
        submit: 'Créer mon compte',
      },
    });
  }
}

const offer: InvitationOffer = {
  email: 'stranger@example.test',
  companyName: 'Acme',
  roleName: 'member',
  expiresAt: '2026-09-16T10:00:00+00:00',
  hasAccount: false,
};

describe('AcceptInvitationPage', () => {
  const current = signal<InvitationOffer | null>(offer);
  const invitation = {
    offer: current.asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<InvitationError | null>(null).asReadonly(),
    accepted: signal(false).asReadonly(),
    load: vi.fn(),
    accept: vi.fn(),
  };
  let fixture: ComponentFixture<AcceptInvitationPage>;

  const byTestId = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function render(shown: InvitationOffer): Promise<void> {
    current.set(shown);
    TestBed.configureTestingModule({
      imports: [AcceptInvitationPage],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: InvitationFacade, useValue: invitation },
      ],
    });
    fixture = TestBed.createComponent(AcceptInvitationPage);
    fixture.componentRef.setInput('token', 'a-token');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    invitation.load.mockReset().mockResolvedValue(offer);
    invitation.accept.mockReset().mockResolvedValue(true);
  });

  it('asks an address with no account for a name and a password', async () => {
    await render(offer);

    expect(byTestId('invitation-name')).not.toBeNull();
    expect(byTestId('invitation-password')).not.toBeNull();
    expect(byTestId('invitation-join')).toBeNull();
  });

  it('asks an address that has an account for nothing, and joins in one click', async () => {
    await render({ ...offer, hasAccount: true });
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    expect(byTestId('invitation-name')).toBeNull();
    expect(byTestId('invitation-password')).toBeNull();
    expect(byTestId('invitation-has-account')?.textContent).toContain('déjà un compte');
    byTestId('invitation-join')?.click();
    await new Promise((resolve) => setTimeout(resolve));

    expect(invitation.accept).toHaveBeenCalledWith('a-token');
    expect(navigate).toHaveBeenCalledWith('/login');
  });
});
