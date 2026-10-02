// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { effectToasts, provideQuietFeedback } from '../shared/testing/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { provideStillAppearance } from '../shared/testing/appearance';
import { type ConnectedDevice, ConnectedDevicesApi } from './connected-devices-api';
import { ConnectedDevices } from './connected-devices';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      account: {
        devices: {
          title: 'Appareils connectés',
          this_one: 'Cet appareil',
          unknown: 'Appareil inconnu',
          end: 'Déconnecter',
          end_others: 'Déconnecter tous les autres',
          seen: '{{address}} · {{when}}',
        },
      },
    });
  }
}

const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';
const row = (id: string, current: boolean, device = FIREFOX): ConnectedDevice => ({
  id,
  device,
  address: '203.0.113.7',
  createdAt: '2026-10-03T08:00:00+00:00',
  lastSeenAt: '2026-10-03T09:00:00+00:00',
  current,
});

describe('ConnectedDevices', () => {
  const api = { list: vi.fn(), end: vi.fn(), endOthers: vi.fn() };

  beforeEach(async () => {
    api.list.mockReset().mockResolvedValue([row('a', true), row('b', false, 'curl/8')]);
    api.end.mockReset().mockResolvedValue(undefined);
    api.endOthers.mockReset().mockResolvedValue(1);
    await TestBed.configureTestingModule({
      imports: [ConnectedDevices],
      providers: [
        provideStillAppearance(),
        provideQuietFeedback(),
        { provide: ConnectedDevicesApi, useValue: api },
        { provide: FormatFacade, useValue: { moment: (value: string) => value } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(ConnectedDevices);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, query };
  }

  it('names each browser, marks this one and gives it no button', async () => {
    const { query } = await render();

    expect(query('device-0-label')?.textContent).toContain('Firefox · Linux');
    expect(query('device-0-current')).not.toBeNull();
    expect(query('device-0-end')).toBeNull();
    expect(query('device-1-label')?.textContent).toContain('Appareil inconnu');
    expect(query('device-1-end')).not.toBeNull();
  });

  it('ends one other session, says so as a correctable effect and reads the list again', async () => {
    const { fixture, query } = await render();

    query<HTMLButtonElement>('device-1-end')!.click();
    await fixture.whenStable();

    expect(api.end).toHaveBeenCalledWith('b');
    expect(api.list).toHaveBeenCalledTimes(2);
    expect(effectToasts()).toEqual(['account.devices.ended:corrigeable']);
  });

  it('ends every other session at once, and offers it only when there is another', async () => {
    const { fixture, query } = await render();

    query<HTMLButtonElement>('devices-end-others')!.click();
    await fixture.whenStable();
    expect(api.endOthers).toHaveBeenCalledOnce();

    api.list.mockResolvedValue([row('a', true)]);
    const again = await render();
    expect(again.query('devices-end-others')).toBeNull();
  });

  it('says the list could not be read, instead of an empty one', async () => {
    api.list.mockRejectedValue(new Error('down'));

    const { query } = await render();

    expect(query('devices-unreachable')).not.toBeNull();
    expect(query('device-0')).toBeNull();
  });
});
