// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
  TranslateService,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { REALTIME_CONNECTOR, type RealtimeConnector } from '../shared/realtime/realtime-connector';
import { Camera } from '../shared/scan/camera';
import { PairingApi, PairingRefused } from '../shared/scan/pairing-api';
import { PhotoShrinker } from '../shared/scan/photo-shrinker';
import { PageMemoryStorage } from '../shared/settings/settings-facade';
import {
  PAIRING_STORAGE,
  PAIRING_STORAGE_KEY,
  PairingAddress,
  PhoneScannerPage,
} from './phone-scanner-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const LINK = 'a'.repeat(64);
const ECHO = '0199aaaa-0000-4000-8000-00000000000e';

describe('PhoneScannerPage', () => {
  const api = {
    claim: vi.fn(),
    scan: vi.fn(async () => undefined),
    choose: vi.fn(async () => undefined),
    realtimeToken: vi.fn(async () => 'token'),
    photo: vi.fn(async () => undefined),
  };
  const shrunk = new Blob(['small'], { type: 'image/jpeg' });
  const shrinker = { shrink: vi.fn<(photo: Blob) => Promise<Blob>>(async () => shrunk) };
  const opened: { getToken: () => Promise<string>; onPublication: (data: unknown) => void }[] = [];
  const disconnect = vi.fn();
  const connector: RealtimeConnector = (_url, getToken, onPublication) => {
    opened.push({ getToken, onPublication });
    return { disconnect };
  };
  let storage: PageMemoryStorage;
  let fixture: ComponentFixture<PhoneScannerPage>;
  let link: string | null;
  const address = {
    takeLink: vi.fn(() => {
      const taken = link;
      link = null;
      return taken;
    }),
    socket: () => 'wss://twes.example/connection/websocket',
  };

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(PhoneScannerPage);
    fixture.detectChanges();
    await vi.waitFor(() => expect(q('phone-loading')).toBeNull());
    fixture.detectChanges();
  }

  function publish(data: unknown): void {
    opened.at(-1)!.onPublication(data);
    fixture.detectChanges();
  }

  async function type(code: string): Promise<void> {
    const input = q('phone-code') as HTMLInputElement;
    input.value = code;
    input.dispatchEvent(new Event('input'));
    (q('phone-code-form') as HTMLFormElement).dispatchEvent(new Event('submit'));
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    api.claim.mockReset().mockResolvedValue({ id: 'p-1', key: 'k'.repeat(64) });
    api.scan.mockClear();
    api.choose.mockClear();
    api.realtimeToken.mockClear();
    api.photo.mockReset().mockResolvedValue(undefined);
    shrinker.shrink.mockReset().mockResolvedValue(shrunk);
    disconnect.mockClear();
    opened.length = 0;
    storage = new PageMemoryStorage();
    link = LINK;
    address.takeLink.mockClear();
    TestBed.configureTestingModule({
      imports: [PhoneScannerPage],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: PairingApi, useValue: api },
        { provide: REALTIME_CONNECTOR, useValue: connector },
        { provide: Camera, useValue: { available: () => false } },
        { provide: PAIRING_STORAGE, useValue: storage },
        { provide: PairingAddress, useValue: address },
        { provide: PhotoShrinker, useValue: shrinker },
      ],
    });
  });

  it('claims the link it was opened with, forgets it from the address, and listens to its own pairing', async () => {
    await open();

    expect(api.claim).toHaveBeenCalledWith(LINK);
    expect(address.takeLink).toHaveBeenCalledOnce();
    expect(JSON.parse(storage.getItem(PAIRING_STORAGE_KEY)!)).toEqual({
      id: 'p-1',
      key: 'k'.repeat(64),
    });
    await expect(opened[0].getToken()).resolves.toBe('token');
    expect(api.realtimeToken).toHaveBeenCalledWith('p-1', 'k'.repeat(64));
    expect(q('phone-code')).not.toBeNull();
  });

  async function takePhoto(photo: File): Promise<void> {
    const input = q('phone-photo-input') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: [photo], configurable: true });
    input.dispatchEvent(new Event('change'));
    await vi.waitFor(() => expect(shrinker.shrink).toHaveBeenCalled());
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it('sends a photo the camera took, made smaller first, and says it went to the computer', async () => {
    await open();
    const taken = new File(['big'], 'IMG_0001.jpg', { type: 'image/jpeg' });

    expect(q('phone-photo-input')?.getAttribute('capture')).toBe('environment');
    await takePhoto(taken);

    expect(shrinker.shrink).toHaveBeenCalledWith(taken);
    expect(api.photo).toHaveBeenCalledWith('p-1', 'k'.repeat(64), expect.any(String), shrunk);
    expect(q('phone-photo-sent')).not.toBeNull();
  });

  it('says a picture it cannot read, and sends nothing', async () => {
    await open();
    shrinker.shrink.mockRejectedValue(new Error('not an image'));

    await takePhoto(new File(['?'], 'note.txt', { type: 'text/plain' }));

    expect(api.photo).not.toHaveBeenCalled();
    expect(q('phone-photo-unreadable')).not.toBeNull();
  });

  it('says a photo the computer turned away, and keeps scanning', async () => {
    await open();
    api.photo.mockRejectedValue(new PairingRefused('invalid'));

    await takePhoto(new File(['big'], 'IMG_0001.jpg', { type: 'image/jpeg' }));
    await vi.waitFor(() => expect(q('phone-photo-refused')).not.toBeNull());

    expect(q('phone-code')).not.toBeNull();
  });

  it('sends a code typed by hand, then shows what the computer made of it', async () => {
    await open();

    await type('3017620422003');

    expect(api.scan).toHaveBeenCalledWith(
      'p-1',
      'k'.repeat(64),
      '3017620422003',
      expect.any(String),
    );
    const scanId = (api.scan.mock.calls[0] as unknown[])[3];
    publish({
      type: 'echo',
      id: ECHO,
      scan: scanId,
      outcome: 'done',
      message: 'scan.added',
      params: { name: 'Nutella' },
      product: { name: 'Nutella', price: '12,500 TND' },
      choices: [],
      details: ['In stock: 14', 'Nearest use-by: 01/12/2026'],
    });

    const echo = q('phone-echo')!.textContent ?? '';
    expect(echo).toContain('scan.added');
    expect(echo).toContain('Nutella');
    expect(echo).toContain('12,500 TND');
    expect(q('phone-echo')!.querySelectorAll('[data-testid="phone-detail"]')).toHaveLength(2);
    expect(echo).toContain('In stock: 14');
  });

  it('shows the choices the computer offers, and a tap sends the one chosen', async () => {
    await open();
    publish({
      type: 'echo',
      id: ECHO,
      scan: null,
      outcome: 'unclaimed',
      message: 'scan.phone.unknown',
      params: { code: '999' },
      product: null,
      choices: [{ id: 'create', label: 'products.scan.actions.create' }],
    });

    (q('phone-choice-create') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(api.choose).toHaveBeenCalledWith('p-1', 'k'.repeat(64), ECHO, 'create');
  });

  // docs/SPEC.md § 7, 2026-09-25 10:13: « Ajouter à ART-007 » names the product on the computer's screen.
  it("words a choice with the echo's own parameters", async () => {
    await open();
    TestBed.inject(TranslateService).setTranslation('fr', {
      products: { scan: { actions: { here: 'Ajouter à {{reference}}' } } },
    });
    await TestBed.inject(TranslateService).use('fr');
    publish({
      type: 'echo',
      id: ECHO,
      scan: null,
      outcome: 'unclaimed',
      message: 'scan.phone.unknown',
      params: { code: '999', reference: 'ART-007' },
      product: null,
      choices: [{ id: 'here', label: 'products.scan.actions.here' }],
    });

    expect(q('phone-choice-here')?.textContent?.trim()).toBe('Ajouter à ART-007');
  });

  it('stops when the computer lets it go', async () => {
    await open();

    publish({ type: 'ended' });

    expect(q('phone-ended')).not.toBeNull();
    expect(q('phone-code')).toBeNull();
    expect(disconnect).toHaveBeenCalled();
  });

  it('says why a link cannot be claimed', async () => {
    api.claim.mockRejectedValue(new PairingRefused('claimed'));

    await open();

    expect(q('phone-refused')?.textContent).toContain('scan.phone.refused.claimed');
    expect(opened).toHaveLength(0);
  });

  it('picks the pairing up again after a reload, without spending a link', async () => {
    storage.setItem(PAIRING_STORAGE_KEY, JSON.stringify({ id: 'p-1', key: 'k'.repeat(64) }));
    link = null;

    await open();

    expect(api.claim).not.toHaveBeenCalled();
    expect(opened).toHaveLength(1);
  });

  it('stops when the pairing refuses a scan', async () => {
    await open();
    api.scan.mockRejectedValueOnce(new PairingRefused('ended'));

    await type('3017620422003');

    expect(q('phone-ended')).not.toBeNull();
    expect(storage.getItem(PAIRING_STORAGE_KEY)).toBeNull();
  });
});
