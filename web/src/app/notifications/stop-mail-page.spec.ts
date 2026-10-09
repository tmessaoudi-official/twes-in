// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { StopMailApi, StopMailRefused } from './stop-mail-api';
import { StopMailPage } from './stop-mail-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      notification_stop: {
        title: 'Ne plus recevoir cet e-mail',
        submit: 'Ne plus recevoir cet e-mail',
        done: 'C’est fait.',
        account: 'Ouvrir Mon compte › Notifications',
        errors: {
          link_not_usable: 'Ce lien ne peut plus servir.',
          too_many_attempts: 'Trop d’essais.',
          network: 'Le serveur ne répond pas.',
        },
      },
    });
  }
}

// A notification mail's stop link opens this page, with no session: the link itself changes nothing, the button does.
describe('StopMailPage', () => {
  const api = { stop: vi.fn() };

  beforeEach(async () => {
    api.stop.mockReset().mockResolvedValue(undefined);
    await TestBed.configureTestingModule({
      imports: [StopMailPage],
      providers: [
        provideStillAppearance(),
        provideQuietFeedback(),
        provideRouter([]),
        { provide: StopMailApi, useValue: api },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(StopMailPage);
    fixture.componentRef.setInput('token', 'the-token');
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, query };
  }

  async function confirm(fixture: { whenStable(): Promise<unknown> }, button: HTMLElement | null) {
    button!.click();
    await fixture.whenStable();
  }

  it('changes nothing until its button is pressed, since mail clients open links on their own', async () => {
    const { query } = await render();

    expect(api.stop).not.toHaveBeenCalled();
    expect(query('stop-mail-submit')?.textContent).toContain('Ne plus recevoir cet e-mail');
  });

  it('sends the link token, then says it is done and leads to where it is turned back on', async () => {
    const { fixture, query } = await render();

    await confirm(fixture, query('stop-mail-submit'));

    expect(api.stop).toHaveBeenCalledWith('the-token');
    expect(query('stop-mail-done')?.textContent).toContain('C’est fait.');
    expect(query('stop-mail-account')?.getAttribute('href')).toBe('/account?tab=notifications');
    expect(query('stop-mail-submit')).toBeNull();
  });

  it('says why when the link cannot serve, and keeps the button for another try', async () => {
    for (const [code, said] of [
      ['link_not_usable', 'Ce lien ne peut plus servir.'],
      ['too_many_attempts', 'Trop d’essais.'],
      ['network', 'Le serveur ne répond pas.'],
    ] as const) {
      api.stop.mockRejectedValueOnce(new StopMailRefused(code));
      const { fixture, query } = await render();

      await confirm(fixture, query('stop-mail-submit'));

      expect(query('stop-mail-error')?.textContent).toContain(said);
      expect(query('stop-mail-done')).toBeNull();
      expect(query('stop-mail-submit')).not.toBeNull();
    }
  });
});
