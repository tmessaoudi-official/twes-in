// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { NotificationsFacade } from '../notifications/notifications-facade';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import { type NotificationChoice, NotificationChoicesApi } from './notification-choices-api';
import { NotificationChoices } from './notification-choices';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      account: {
        notifications: {
          title: 'Ce que la cloche compte',
          intro: 'Pour chaque société, choisissez ce que la cloche compte.',
          account: 'Votre compte',
          kind: 'Notification',
          bell: 'Cloche',
          bell_of: 'Cloche : {{kind}}',
          failed: 'Le choix n’a pas pu être enregistré.',
          unreachable: 'Les notifications n’ont pas pu être lues.',
        },
      },
      notifications: {
        kinds: {
          stock_low: 'Stock sous le seuil de réapprovisionnement',
          invitation_accepted: 'Nouveau membre',
          invitation_received: 'Invitation reçue',
          unknown: 'Autre notification',
        },
      },
    });
  }
}

const told: NotificationChoice[] = [
  { companyId: 'c1', companyName: 'Acme', type: 'stock.low', bell: true, email: true },
  { companyId: 'c1', companyName: 'Acme', type: 'invitation.accepted', bell: false, email: true },
  { companyId: 'c2', companyName: 'Globex', type: 'stock.low', bell: true, email: false },
  { companyId: null, companyName: null, type: 'invitation.received', bell: true, email: true },
  { companyId: null, companyName: null, type: 'something.newer', bell: true, email: true },
];

// « Mon compte › Notifications »: each person chooses, kind by kind and company by company, what the bell counts.
describe('NotificationChoices', () => {
  const api = { list: vi.fn(), change: vi.fn() };
  const bell = { refresh: vi.fn() };
  let fixture: ComponentFixture<NotificationChoices>;

  async function render(): Promise<HTMLElement> {
    await TestBed.configureTestingModule({
      imports: [NotificationChoices],
      providers: [
        provideTranslateService({
          fallbackLang: 'fr',
          loader: provideTranslateLoader(StaticLoader),
        }),
        ...provideQuietFeedback(),
        { provide: NotificationChoicesApi, useValue: api },
        { provide: NotificationsFacade, useValue: bell },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(NotificationChoices);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const byTestId = (root: HTMLElement, id: string) =>
    root.querySelector<HTMLElement>(`[data-testid="${id}"]`);
  const switchOf = (root: HTMLElement, id: string) =>
    byTestId(root, id)?.querySelector<HTMLButtonElement>('button[role="switch"]') ?? null;
  const text = (element: Element | null) => element?.textContent?.replace(/\s+/g, ' ').trim();

  beforeEach(() => {
    api.list.mockReset();
    api.change.mockReset();
    bell.refresh.mockReset();
    api.list.mockResolvedValue(told.map((choice) => ({ ...choice })));
    api.change.mockResolvedValue(undefined);
  });

  it('lists what each company tells under its name, then what is about the account, each with its bell as chosen', async () => {
    const root = await render();
    const groups = [...root.querySelectorAll('[data-testid^="notification-group-"]')];
    expect(groups.map((group) => text(group.querySelector('h3')))).toEqual([
      'Acme',
      'Globex',
      'Votre compte',
    ]);
    expect(text(byTestId(root, 'notification-c1-stock.low'))).toContain(
      'Stock sous le seuil de réapprovisionnement',
    );
    expect(switchOf(root, 'notification-c1-stock.low')?.getAttribute('aria-checked')).toBe('true');
    expect(
      switchOf(root, 'notification-c1-invitation.accepted')?.getAttribute('aria-checked'),
    ).toBe('false');
    expect(switchOf(root, 'notification-account-invitation.received')).not.toBeNull();
    expect(text(byTestId(root, 'notification-account-something.newer'))).toContain(
      'Autre notification',
    );
  });

  it('names each switch with the kind it rings for, since a row holds no other label for it', async () => {
    const root = await render();
    expect(switchOf(root, 'notification-c2-stock.low')?.getAttribute('aria-label')).toBe(
      'Cloche : Stock sous le seuil de réapprovisionnement',
    );
  });

  it('mutes a kind in one company only, keeps its e-mail as it was, and has the bell count again', async () => {
    const root = await render();
    switchOf(root, 'notification-c2-stock.low')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(api.change).toHaveBeenCalledWith({
      companyId: 'c2',
      companyName: 'Globex',
      type: 'stock.low',
      bell: false,
      email: false,
    });
    expect(bell.refresh).toHaveBeenCalled();
    expect(switchOf(root, 'notification-c2-stock.low')?.getAttribute('aria-checked')).toBe('false');
    expect(switchOf(root, 'notification-c1-stock.low')?.getAttribute('aria-checked')).toBe('true');
  });

  it('puts the switch back and says so when the choice was not kept', async () => {
    api.change.mockRejectedValue(new Error('offline'));
    const root = await render();
    const feedback = TestBed.inject(Feedback) as unknown as RecordedFeedback;
    switchOf(root, 'notification-c1-stock.low')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(switchOf(root, 'notification-c1-stock.low')?.getAttribute('aria-checked')).toBe('true');
    expect(feedback.said).toContainEqual({
      kind: 'failure',
      key: 'account.notifications.failed',
      params: undefined,
    });
    expect(bell.refresh).not.toHaveBeenCalled();
  });

  it('says the list could not be read', async () => {
    api.list.mockRejectedValue(new Error('offline'));
    const root = await render();
    expect(text(byTestId(root, 'notification-choices-unreachable'))).toBe(
      'Les notifications n’ont pas pu être lues.',
    );
    expect(root.querySelector('[data-testid^="notification-group-"]')).toBeNull();
  });
});
