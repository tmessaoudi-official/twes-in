// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { PurchasesFacade } from './purchases-facade';
import { PurchasesPage } from './purchases-page';
import type { PurchaseOrderRow, PurchaseOrderStatusCounts } from './purchases-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      purchases: {
        draft: 'Brouillon sans numéro',
        statuses: { sent: 'Envoyée', cancelled: 'Annulée' },
      },
    });
  }
}

function order(id: string, patch: Partial<PurchaseOrderRow>): PurchaseOrderRow {
  return {
    id,
    version: 1,
    number: null,
    status: 'draft',
    vendorId: 'v1',
    vendorName: 'Sotumag',
    recordedVendorNumber: null,
    establishmentId: null,
    issueDate: null,
    expectedOn: null,
    remarksPrinted: null,
    notesInternal: null,
    lines: [],
    totalNet: null,
    receipts: [],
    ...patch,
  };
}

const counts: PurchaseOrderStatusCounts = {
  all: 3,
  statuses: { draft: 1, sent: 1, partly_received: 0, received: 0, closed: 0, cancelled: 1 },
};

describe('PurchasesPage', () => {
  const facade = {
    orders: signal<readonly PurchaseOrderRow[]>([
      order('o1', { number: 'BC-2026-0002', status: 'sent', totalNet: '12.000' }),
      order('o2', {}),
      order('o3', { number: 'BC-2026-0001', status: 'cancelled' }),
    ]).asReadonly(),
    total: signal(3).asReadonly(),
    statusCounts: signal<PurchaseOrderStatusCounts | null>(counts).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    loadPage: vi.fn(),
    loadStatusCounts: vi.fn(),
    pickVendors: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<PurchasesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    facade.loadPage.mockReset().mockResolvedValue(undefined);
    facade.loadStatusCounts.mockReset().mockResolvedValue(undefined);
    facade.pickVendors.mockReset().mockResolvedValue([]);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [PurchasesPage],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: PurchasesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(PurchasesPage);
    await settle();
  });

  it('asks the newest orders first, with the status chips counted under the same search', () => {
    const search = {
      page: 1,
      itemsPerPage: 25,
      q: '',
      status: [],
      vendorIds: [],
      intervals: {},
      order: { key: 'number', direction: 'desc' },
    };
    expect(facade.loadPage).toHaveBeenCalledWith('c1', search);
    expect(facade.loadStatusCounts).toHaveBeenCalledWith('c1', search);
  });

  it('names each order by its number, a draft as a draft, and its status in words and tone', () => {
    const sent = q('purchase-order-o1')?.textContent ?? '';
    expect(sent).toContain('BC-2026-0002');
    expect(sent).toContain('Sotumag');
    expect(sent).toContain('Envoyée');
    expect(q('purchase-order-o2')?.textContent).toContain('Brouillon sans numéro');
    expect(q('purchase-order-o3')?.querySelector('app-status-badge')?.textContent).toContain(
      'Annulée',
    );
    expect(q('list-link-o1')?.getAttribute('href')).toBe('/purchases/o1');
  });

  it('reads the page again when an order is received elsewhere', async () => {
    facade.loadPage.mockClear();
    facade.loadStatusCounts.mockClear();

    await announceSaved('purchase_order', 'o1', undefined, 'purchase_order.received');

    expect(facade.loadPage).toHaveBeenCalledWith('c1', expect.objectContaining({ page: 1 }));
    expect(facade.loadStatusCounts).toHaveBeenCalled();
  });

  it('offers a new order to whoever writes orders only', async () => {
    expect(q('purchase-order-add')?.getAttribute('href')).toBe('/purchases/new');

    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(PurchasesPage);
    await settle();

    expect(q('purchase-order-add')).toBeNull();
    expect(q('list-link-o1')).not.toBeNull();
  });
});
