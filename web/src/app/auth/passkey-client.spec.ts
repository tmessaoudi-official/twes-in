// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { HealthApi, type PasskeyPlace } from '../shared/health/health-api';
import { PasskeyClient, passkeysWorkAt } from './passkey-client';

describe('passkeysWorkAt', () => {
  it.each([
    ['localhost', 'localhost', true],
    ['LOCALHOST', 'localhost', true],
    ['shop.example.com', 'example.com', true],
    ['example.com', 'example.com', true],
    ['192.168.1.20', 'localhost', false],
    ['badexample.com', 'example.com', false],
    ['example.com', 'shop.example.com', false],
  ])('a page at %s with passkeys bound to %s: %s', (hostname, host, works) => {
    expect(passkeysWorkAt(hostname, host)).toBe(works);
  });
});

describe('PasskeyClient', () => {
  function client(hostname: string, place: PasskeyPlace | null): PasskeyClient {
    TestBed.configureTestingModule({
      providers: [
        { provide: DOCUMENT, useValue: { location: { hostname } } },
        { provide: HealthApi, useValue: { passkeys: () => of(place) } },
      ],
    });
    const passkeys = TestBed.inject(PasskeyClient);
    vi.spyOn(passkeys, 'browserCan').mockReturnValue(true);
    return passkeys;
  }

  it('offers no passkey at a shop’s network address, and says where they work', async () => {
    const passkeys = client('192.168.1.20', { host: 'localhost', address: 'https://localhost' });
    expect(passkeys.supported()).toBe(true);

    await passkeys.read();

    expect(passkeys.supported()).toBe(false);
    expect(passkeys.elsewhere()).toBe('https://localhost');
  });

  it('offers them at the address they are bound to', async () => {
    const passkeys = client('localhost', { host: 'localhost', address: 'https://localhost' });
    await passkeys.read();

    expect(passkeys.supported()).toBe(true);
    expect(passkeys.elsewhere()).toBeNull();
  });

  it('names the host itself when the API names no address', async () => {
    const passkeys = client('10.0.0.5', { host: 'twes.example.com', address: null });
    await passkeys.read();

    expect(passkeys.elsewhere()).toBe('https://twes.example.com');
  });

  it('leaves the browser to decide when nothing says where they work', async () => {
    const passkeys = client('192.168.1.20', null);
    await passkeys.read();

    expect(passkeys.supported()).toBe(true);
    expect(passkeys.elsewhere()).toBeNull();
  });
});

describe('PasskeyClient asking for a passkey', () => {
  /** A browser's WebAuthn as far as the client reads it. */
  class FakeCredential {
    static conditional: boolean | undefined = true;
    static parseRequestOptionsFromJSON = (json: unknown) => ({ parsed: json });
    static parseCreationOptionsFromJSON = (json: unknown) => json;
    static isConditionalMediationAvailable?: () => Promise<boolean> = () =>
      Promise.resolve(FakeCredential.conditional === true);
    toJSON() {
      return { id: 'picked' };
    }
  }
  const credentialsGet = vi.fn();

  beforeEach(() => {
    FakeCredential.conditional = true;
    credentialsGet.mockReset().mockResolvedValue(new FakeCredential());
    vi.stubGlobal('PublicKeyCredential', FakeCredential);
    vi.stubGlobal('navigator', { credentials: { get: credentialsGet } });
    TestBed.configureTestingModule({
      providers: [{ provide: HealthApi, useValue: { passkeys: () => of(null) } }],
    });
  });

  afterEach(() => vi.unstubAllGlobals());

  it('says whether the browser offers its passkeys under an address field', async () => {
    const passkeys = TestBed.inject(PasskeyClient);
    expect(await passkeys.offersUnderTheField()).toBe(true);

    FakeCredential.conditional = false;
    expect(await passkeys.offersUnderTheField()).toBe(false);
  });

  it('asks at once by default, or under the field until the signal aborts it', async () => {
    const passkeys = TestBed.inject(PasskeyClient);

    expect(await passkeys.get({ challenge: 'c1' })).toEqual({ id: 'picked' });
    expect(credentialsGet).toHaveBeenLastCalledWith({ publicKey: { parsed: { challenge: 'c1' } } });

    const offer = new AbortController();
    await passkeys.get({ challenge: 'c2' }, { mediation: 'conditional', signal: offer.signal });
    expect(credentialsGet).toHaveBeenLastCalledWith({
      publicKey: { parsed: { challenge: 'c2' } },
      mediation: 'conditional',
      signal: offer.signal,
    });
  });
});
