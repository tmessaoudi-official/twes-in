// SPDX-License-Identifier: AGPL-3.0-or-later

import { type ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  CUSTOMER_DISPLAY_CHANNEL,
  CustomerDisplay,
} from '../shared/customer-display/customer-display';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { SettingsApi } from '../shared/settings/settings-api';
import type { SettingRow } from '../shared/settings/settings-types';
import { FakeDisplayChannels } from '../shared/testing/display-channels';
import { CustomerDisplayPage } from './customer-display-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

describe('CustomerDisplayPage', () => {
  const settings = { chain: vi.fn() };
  let channels: FakeDisplayChannels;
  let fixture: ComponentFixture<CustomerDisplayPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  beforeEach(() => {
    settings.chain.mockReset();
    settings.chain.mockResolvedValue([
      { key: 'article.show_price_excl_tax', value: false } as SettingRow,
    ]);
    channels = new FakeDisplayChannels();
    TestBed.configureTestingModule({
      imports: [CustomerDisplayPage],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: CUSTOMER_DISPLAY_CHANNEL, useValue: channels.opener },
        { provide: SettingsApi, useValue: settings },
        {
          provide: Session,
          useValue: { me: () => ({ company: { id: 'c1', name: 'Demo', currency: 'TND' } }) },
        },
        { provide: FormatFacade, useValue: { amount: (value: string) => `~${value}` } },
      ],
    });
  });

  afterEach(() => fixture?.destroy());

  it('welcomes the customer until a scan comes, then shows the line and its price, taxes included', () => {
    fixture = TestBed.createComponent(CustomerDisplayPage);
    fixture.detectChanges();
    expect(q('customer-display-waiting')).not.toBeNull();

    TestBed.inject(CustomerDisplay).show({ name: 'Vis 6x40', quantity: '3', unitPrice: '1.190' });
    fixture.detectChanges();

    expect(q('customer-display-waiting')).toBeNull();
    expect(q('customer-display-name')?.textContent).toContain('Vis 6x40');
    expect(q('customer-display-line')?.textContent).toContain('3');
    expect(q('customer-display-line')?.textContent).toContain('~1.190 TND');
    expect(q('customer-display-total')).toBeNull();
  });

  it('labels a price as the shelf price shown, and adds the price without tax only when the company asks', async () => {
    fixture = TestBed.createComponent(CustomerDisplayPage);
    await fixture.whenStable();
    fixture.detectChanges();
    const sender = TestBed.inject(CustomerDisplay);

    sender.show({ name: 'Vis', quantity: '2', unitPrice: '1.190', unitPriceNet: '1.000' });
    fixture.detectChanges();
    expect(q('customer-display-shelf')).not.toBeNull();
    expect(q('customer-display-net')).toBeNull();
    expect(settings.chain).toHaveBeenCalledWith('c1', 'articles');

    fixture.destroy();
    settings.chain.mockResolvedValue([
      { key: 'article.show_price_excl_tax', value: true } as SettingRow,
    ]);
    fixture = TestBed.createComponent(CustomerDisplayPage);
    await fixture.whenStable();
    fixture.detectChanges();
    TestBed.inject(CustomerDisplay).show({
      name: 'Vis',
      quantity: '2',
      unitPrice: '1.190',
      unitPriceNet: '1.000',
    });
    fixture.detectChanges();
    expect(q('customer-display-net')?.textContent).toContain('~1.000 TND');
    expect(q('customer-display-line')?.textContent).toContain('~1.190 TND');
  });

  it('shows what the sale comes to once saved', () => {
    fixture = TestBed.createComponent(CustomerDisplayPage);
    fixture.detectChanges();
    const sender = TestBed.inject(CustomerDisplay);

    sender.show({ name: 'Vis', quantity: '1', unitPrice: '1.190' });
    sender.total('11.900');
    fixture.detectChanges();

    expect(q('customer-display-total')?.textContent).toContain('~11.900 TND');
  });

  it('closes its end of the channel when it goes', () => {
    fixture = TestBed.createComponent(CustomerDisplayPage);
    fixture.detectChanges();
    const open = channels.count;
    fixture.destroy();
    expect(channels.count).toBe(open - 1);
  });
});
