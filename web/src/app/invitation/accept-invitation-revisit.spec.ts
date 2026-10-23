// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { AcceptInvitationPage } from './accept-invitation-page';
import { InvitationApi, InvitationRefused } from './invitation-api';
import type { InvitationOffer } from './invitation-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invitation: {
        offer: 'Invitation à rejoindre {{company}}.',
        errors: { password_refused: 'Mot de passe refusé' },
      },
    });
  }
}

const acme: InvitationOffer = {
  email: 'stranger@example.test',
  companyName: 'Acme',
  roleName: 'member',
  expiresAt: '2026-09-16T10:00:00+00:00',
  hasAccount: true,
};

/**
 * The invitation facade lives as long as the application: one injector is kept across visits, as the browser keeps it
 * between two navigations, so a second link never opens on what the first one showed or was refused.
 */
describe('An invitation link, after another one', () => {
  const api = { offer: vi.fn(), accept: vi.fn() };

  beforeEach(async () => {
    api.offer.mockReset().mockResolvedValue(acme);
    api.accept.mockReset().mockResolvedValue('Acme');
    await TestBed.configureTestingModule({
      providers: [
        provideStillAppearance(),
        provideQuietFeedback(),
        provideRouter([]),
        { provide: InvitationApi, useValue: api },
        { provide: AuthFacade, useValue: { refresh: vi.fn(async () => undefined) } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function visit(token: string) {
    const fixture = TestBed.createComponent(AcceptInvitationPage);
    fixture.componentRef.setInput('token', token);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = (id: string) => el.querySelector<HTMLElement>(`[data-testid="${id}"]`);
    return { fixture, query };
  }

  it('does not show the first company while the second link is being read', async () => {
    const first = await visit('first');
    expect(first.query('invitation-offer')?.textContent).toContain('Acme');
    first.fixture.destroy();
    api.offer.mockReturnValueOnce(new Promise(() => undefined));

    const second = await visit('second');

    expect(second.query('invitation-offer')).toBeNull();
  });

  it('does not carry the first link’s refusal to the second', async () => {
    api.accept.mockRejectedValueOnce(new InvitationRefused('password_refused'));
    const first = await visit('first');
    first.query('invitation-join')!.click();
    await first.fixture.whenStable();
    expect(first.query('invitation-form-error')).not.toBeNull();
    first.fixture.destroy();
    let answer: (offer: InvitationOffer) => void = () => undefined;
    api.offer.mockReturnValueOnce(new Promise<InvitationOffer>((resolve) => (answer = resolve)));

    const second = await visit('second');
    answer({ ...acme, companyName: 'Beta' });
    await new Promise((resolve) => setTimeout(resolve));
    await second.fixture.whenStable();

    expect(second.query('invitation-offer')?.textContent).toContain('Beta');
    expect(second.query('invitation-form-error')).toBeNull();
  });
});
