// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
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
import { InventoryFacade } from './inventory-facade';
import type { LocationContents, StockLevelRow } from './inventory-types';
import { CONTENTS_SEARCH_FROM, CONTENTS_SEARCH_PAUSE_MS, PlaceContents } from './place-contents';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        plan: {
          here: {
            search: 'Chercher ici',
            nothing: 'Rien n’est rangé ici.',
            no_match: 'Rien ici ne correspond à « {{words}} ».',
            empty_home: '{{product}} a sa place ici ({{place}}) et il n’y en a plus.',
            reading: 'Lecture…',
          },
        },
      },
    });
  }
}

const level: StockLevelRow = {
  id: 'p1:b1',
  productId: 'p1',
  productReference: 'VIS-6X40',
  productName: 'Vis 6x40 zinguée',
  unitCode: 'C62',
  unitName: 'Pièce',
  unitDecimals: 0,
  locationId: 'b1',
  locationCode: 'R1-B2',
  locationName: 'Bac B2',
  establishmentId: 'e1',
  quantity: '340.000',
  lotId: null,
  lotCode: null,
  lotExpiresOn: null,
  lotReleased: false,
  mainPhotoId: null,
};

/** A place holding `count` products, one line each, as one read answers it. */
function holding(locationId: string, count: number, q = ''): LocationContents {
  return {
    locationId,
    q,
    levels: Array.from({ length: count }, (_, at) => ({
      ...level,
      id: `p${at}:b1`,
      productId: `p${at}`,
      productReference: `REF-${at}`,
    })),
    total: count,
    homes: [],
  };
}

@Component({
  imports: [PlaceContents],
  template: `<app-place-contents companyId="c1" [locationId]="place()" />`,
})
class Host {
  readonly place = signal('l1');
}

describe('PlaceContents', () => {
  const contents = signal<LocationContents | null>(null);
  const facade = {
    contents: contents.asReadonly(),
    loadContents: vi.fn(),
    reloadContents: vi.fn(),
  };
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  /** Types into the field and lets the pause pass, as a person who stops typing does. */
  async function type(words: string): Promise<void> {
    const field = q('stock-contents-search') as HTMLInputElement;
    field.value = words;
    field.dispatchEvent(new Event('input'));
    await new Promise((resolve) => setTimeout(resolve, CONTENTS_SEARCH_PAUSE_MS + 10));
    await settle();
  }

  /** Answers every read with `answer` for the place and words asked. */
  function answering(answer: (locationId: string, q: string) => LocationContents): void {
    facade.loadContents.mockImplementation(
      async (_company: string, locationId: string, q: string) => {
        contents.set(answer(locationId, q));
      },
    );
  }

  beforeEach(async () => {
    contents.set(null);
    facade.loadContents.mockReset().mockResolvedValue(undefined);
    facade.reloadContents.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [Host],
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
        { provide: InventoryFacade, useValue: facade },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1' } }),
            hasPermission: () => true,
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(Host);
    await settle();
  }

  it('reads the place it is given, with no words, and says when that place holds nothing', async () => {
    answering((locationId, words) => ({ ...holding(locationId, 0), q: words }));
    await open();

    expect(facade.loadContents).toHaveBeenLastCalledWith('c1', 'l1', '');
    expect(q('stock-contents-nothing')).not.toBeNull();
  });

  /** A place is recognised by what it holds, and a picture is read faster than a reference. */
  it("draws each line's product photo, and a plain mark where the product has none", async () => {
    answering((locationId) => {
      const place = holding(locationId, 2);
      return {
        ...place,
        levels: place.levels.map((line, at) => (at === 0 ? { ...line, mainPhotoId: 'ph0' } : line)),
      };
    });
    await open();

    const pictured = q('stock-contents-line-p0:b1')?.querySelector(
      '[data-testid="product-thumbnail"]',
    );
    expect(pictured?.getAttribute('src')).toBe(
      '/api/companies/c1/products/p0/photos/ph0/content?size=small',
    );
    const plain = q('stock-contents-line-p1:b1');
    expect(plain?.querySelector('[data-testid="product-thumbnail"]')).toBeNull();
    expect(plain?.querySelector('[data-testid="product-thumbnail-none"]')).not.toBeNull();
  });

  /** A shelf of a few products is read at a glance; a field there would be one more thing to look past. */
  it('offers a search only once the place holds more than a glance takes', async () => {
    answering((locationId) => holding(locationId, CONTENTS_SEARCH_FROM));
    await open();
    expect(q('stock-contents-search')).toBeNull();

    answering((locationId) => holding(locationId, CONTENTS_SEARCH_FROM + 1));
    fixture.componentInstance.place.set('l2');
    await settle();
    expect(q('stock-contents-search')).not.toBeNull();
  });

  /** The words go to the API, through every place under this one, once typing rests. */
  it('searches the place through the API once typing rests, and says when nothing matches', async () => {
    answering((locationId, words) =>
      words === '' ? holding(locationId, 9) : { ...holding(locationId, 0), q: words },
    );
    await open();

    await type('  chevilles ');

    expect(facade.loadContents).toHaveBeenLastCalledWith('c1', 'l1', 'chevilles');
    expect(q('stock-contents-no-match')?.textContent).toContain('« chevilles »');
    expect(q('stock-contents-nothing')).toBeNull();
    // Still there while the words narrow the place to nothing, so they can be changed.
    expect(q('stock-contents-search')).not.toBeNull();
  });

  /** Under words the homes shown are not the whole place: one not shown is not therefore empty. */
  it('calls no home empty while words narrow the place', async () => {
    answering((locationId, words) => ({
      ...holding(locationId, 9),
      q: words,
      homes: [
        {
          productId: 'p99',
          productReference: 'RON-M6',
          productName: 'Rondelle M6',
          locationId,
          locationCode: 'R1',
          main: true,
        },
      ],
    }));
    await open();
    expect(q('stock-contents-empty-home-p99')).not.toBeNull();

    await type('vis');
    expect(q('stock-contents-empty-home-p99')).toBeNull();
  });

  it('starts again from no words on another place', async () => {
    answering((locationId, words) => ({ ...holding(locationId, 9), q: words }));
    await open();
    await type('vis');

    fixture.componentInstance.place.set('l2');
    await settle();

    expect(facade.loadContents).toHaveBeenLastCalledWith('c1', 'l2', '');
    expect((q('stock-contents-search') as HTMLInputElement).value).toBe('');
  });

  /** A late answer for the shelf left behind never shows under the one chosen now. */
  it('shows nothing of another place while this one is read', async () => {
    await open();
    contents.set(holding('l9', 3));
    await settle();

    expect(q('stock-contents-line-p0:b1')).toBeNull();
    expect(q('stock-contents')?.textContent).toContain('Lecture…');
  });

  it('reads the place again when stock moves anywhere', async () => {
    answering((locationId) => holding(locationId, 1));
    await open();

    await announceSaved('stock', 'p1');

    expect(facade.reloadContents).toHaveBeenCalledWith('c1');
  });
});
