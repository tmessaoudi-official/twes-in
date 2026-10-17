// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { DEMO_MAIL_KIND, DemoMailsApi, type DemoMail } from './demo-mails-api';
import { DemoMailsPage } from './demo-mails-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      demo_mails: {
        title: 'Courriers de la démo',
        intro: 'Gardés ici.',
        none: 'Aucun courrier.',
        unavailable: 'Indisponible.',
      },
    });
  }
}

const latest: DemoMail = {
  id: 'm2',
  subject: 'Votre facture est en retard',
  capturedAt: '2026-10-18T09:00:00+00:00',
  html: '<p>Facture F-2026-0042</p><script>window.ran = true</script>',
};
const earlier: DemoMail = {
  id: 'm1',
  subject: 'Bienvenue',
  capturedAt: '2026-10-18T08:00:00+00:00',
  html: '<p>Bienvenue dans la démo</p>',
};

describe('DemoMailsPage', () => {
  const me = signal<unknown>({ user: { id: 'u1', demo: true }, company: { id: 'c1' } });
  const api = { list: vi.fn() };
  const live = { reloadOn: vi.fn() };
  let fixture: ComponentFixture<DemoMailsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(DemoMailsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    api.list.mockReset().mockResolvedValue([latest, earlier]);
    live.reloadOn.mockReset();
    TestBed.configureTestingModule({
      imports: [DemoMailsPage],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: AuthFacade, useValue: { me } },
        { provide: FormatFacade, useValue: { moment: (value: string) => value } },
        { provide: DemoMailsApi, useValue: api },
        { provide: LiveChanges, useValue: live },
      ],
    });
  });

  it('lists the mails the latest first and opens the latest, its script left out', async () => {
    await open();

    expect(api.list).toHaveBeenCalledWith('c1');
    const subjects = [
      ...fixture.nativeElement.querySelectorAll('[data-testid^="demo-mail-m"] .font-medium'),
    ].map((element) => (element as HTMLElement).textContent?.trim());
    expect(subjects).toEqual(['Votre facture est en retard', 'Bienvenue']);
    expect(q('demo-mail-opened')?.querySelector('h2')?.textContent).toBe(
      'Votre facture est en retard',
    );
    const body = q('demo-mail-body');
    expect(body?.textContent).toContain('Facture F-2026-0042');
    expect(body?.querySelector('script')).toBeNull();
    expect(q('demo-mail-m2')?.getAttribute('aria-current')).toBe('true');
  });

  it('opens the mail picked, and keeps it open when another arrives', async () => {
    await open();
    q('demo-mail-m1')?.click();
    fixture.detectChanges();
    expect(q('demo-mail-body')?.textContent).toContain('Bienvenue dans la démo');

    const reload = live.reloadOn.mock.calls[0][1] as () => Promise<void>;
    api.list.mockResolvedValue([{ ...latest, id: 'm3', subject: 'Nouveau' }, latest, earlier]);
    await reload();
    fixture.detectChanges();

    expect(live.reloadOn.mock.calls[0][0]).toEqual([DEMO_MAIL_KIND]);
    expect(q('demo-mail-m3')).not.toBeNull();
    expect(q('demo-mail-body')?.textContent).toContain('Bienvenue dans la démo');
  });

  it('says when nothing was kept yet', async () => {
    api.list.mockResolvedValue([]);
    await open();

    expect(q('demo-mails-none')?.textContent).toContain('Aucun courrier.');
    expect(q('demo-mails-list')).toBeNull();
  });

  it('says when the mails cannot be read', async () => {
    api.list.mockRejectedValue(new Error('down'));
    await open();

    expect(q('demo-mails-error')?.getAttribute('role')).toBe('alert');
  });
});
