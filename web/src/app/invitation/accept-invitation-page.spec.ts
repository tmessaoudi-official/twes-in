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
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { provideKnownPasswordRule } from '../shared/testing/password-rule';

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
        password_hint: '{{min}} caractères',
        password_too_short: 'Au moins {{min}} caractères.',
        errors: { too_many: 'Trop de tentatives avec ce lien : réessayez dans quelques minutes.' },
        submit: 'Créer mon compte',
        journal:
          'Ce que vous ferez chez {{company}} sera inscrit dans son journal d’activité. Voir',
      },
      legal: { pages: { privacy: 'Confidentialité' } },
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
  const failed = signal<InvitationError | null>(null);
  const invitation = {
    offer: current.asReadonly(),
    busy: signal(false).asReadonly(),
    error: failed.asReadonly(),
    forget: vi.fn(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    accept: vi.fn(),
  };
  let fixture: ComponentFixture<AcceptInvitationPage>;

  const byTestId = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function render(shown: InvitationOffer | null): Promise<void> {
    current.set(shown);
    TestBed.configureTestingModule({
      imports: [AcceptInvitationPage],
      providers: [
        provideKnownPasswordRule(),
        provideRouter([]),
        provideStillAppearance(),
        provideQuietFeedback(),
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
    failed.set(null);
  });

  it('asks an address with no account for a name and a password', async () => {
    await render(offer);

    expect(byTestId('invitation-name')).not.toBeNull();
    expect(byTestId('invitation-password')).not.toBeNull();
    expect(byTestId('invitation-join')).toBeNull();
    // The length is the one the API answers, said before anything is sent.
    expect(byTestId('invitation-password')?.closest('mat-form-field')?.textContent).toContain(
      '12 caractères',
    );
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

  it('tells whoever joins, before they do, that what they do is kept in the journal', async () => {
    await render(offer);

    expect(byTestId('invitation-journal')?.textContent).toContain(
      'Ce que vous ferez chez Acme sera inscrit dans son journal d’activité',
    );
    expect(byTestId('invitation-privacy')?.getAttribute('href')).toBe('/legal/privacy');
  });
  it('leads back to the sign-in from a link that is no longer valid, as the other signed-out screens do', async () => {
    // It said the link was not valid and offered nothing (sweep 2026-10-09).
    invitation.load.mockResolvedValue(null);
    await render(null);

    expect(byTestId('invitation-error')).not.toBeNull();
    expect(byTestId('invitation-back')?.getAttribute('href')).toBe('/login');
  });

  // Row 196: a client past the link's budget is told to wait, and nothing about the link; the way back stays.
  it('says too many attempts were made, without a word about the link, and leads back to the sign-in', async () => {
    invitation.load.mockResolvedValue(null);
    failed.set('too_many');
    await render(null);

    expect(byTestId('invitation-error')?.textContent?.trim()).toBe(
      'Trop de tentatives avec ce lien : réessayez dans quelques minutes.',
    );
    expect(byTestId('invitation-password')).toBeNull();
    expect(byTestId('invitation-back')?.getAttribute('href')).toBe('/login');
  });

  it.each([false, true])(
    'leads back to the sign-in beside an offer too (an account already: %s), for whoever does not mean to join',
    async (hasAccount) => {
      await render({ ...offer, hasAccount });

      expect(byTestId('invitation-back')?.getAttribute('href')).toBe('/login');
    },
  );
});
