// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, Injectable, Injector, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { HealthApi, type PasskeyPlace } from '../shared/health/health-api';
import type { PasskeyCredential, PasskeyOptions } from './auth-types';

/**
 * WebAuthn's rule: a passkey bound to a host works on a page at that host or one of its subdomains, never at an
 * address (a shop's network one) or another name, where the browser refuses the ceremony.
 */
export function passkeysWorkAt(hostname: string, host: string): boolean {
  const page = hostname.toLowerCase();
  const bound = host.toLowerCase();
  return page === bound || page.endsWith(`.${bound}`);
}

/** How a passkey is asked for: at once (the default), or offered under an address field, until the signal aborts it. */
export interface PasskeyRequest {
  readonly mediation?: CredentialMediationRequirement;
  readonly signal?: AbortSignal;
}

/**
 * The browser's WebAuthn and nothing else: options in the JSON the API sends, a credential back in the JSON it expects
 * (WebAuthn Level 3 `parseCreationOptionsFromJSON`, `parseRequestOptionsFromJSON` and `toJSON`). Behind one injectable
 * so the pages never touch `navigator.credentials` and specs can stand in for the authenticator.
 */
@Injectable({ providedIn: 'root' })
export class PasskeyClient {
  private readonly injector = inject(Injector);
  private readonly document = inject(DOCUMENT);
  private readonly place = signal<PasskeyPlace | null>(null);

  /**
   * Reads once where the installation's passkeys work (the app does at start). Until it answers, and when nothing
   * answers, the browser decides alone: a degraded API does not take a sign-in factor away.
   */
  async read(): Promise<void> {
    this.place.set(await firstValueFrom(this.injector.get(HealthApi).passkeys()));
  }

  /** Whether a passkey can be made and used here: by this browser, at this page's address. */
  supported(): boolean {
    return this.browserCan() && this.elsewhere() === null;
  }

  /** The address the installation's passkeys work at when this page's is not one; null when they work here or nothing says. */
  elsewhere(): string | null {
    const place = this.place();
    if (place === null || passkeysWorkAt(this.document.location.hostname, place.host)) return null;
    return place.address ?? `https://${place.host}`;
  }

  /** Whether this browser can create and use a passkey from JSON options at all. */
  browserCan(): boolean {
    return (
      typeof PublicKeyCredential !== 'undefined' &&
      typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function' &&
      'credentials' in navigator
    );
  }

  /** Rejects when the person cancels, the device refuses, or no credential comes back. */
  async create(options: PasskeyOptions): Promise<PasskeyCredential> {
    // The API's options are WebAuthn's own JSON; its OpenAPI type can say no more than "an object with a challenge".
    const publicKey = PublicKeyCredential.parseCreationOptionsFromJSON(
      options as unknown as PublicKeyCredentialCreationOptionsJSON,
    );
    return toJson(await navigator.credentials.create({ publicKey }));
  }

  /** Whether this browser can offer its passkeys under an address field (WebAuthn's conditional mediation). */
  async offersUnderTheField(): Promise<boolean> {
    return (
      this.browserCan() &&
      typeof PublicKeyCredential.isConditionalMediationAvailable === 'function' &&
      (await PublicKeyCredential.isConditionalMediationAvailable())
    );
  }

  /**
   * Rejects when the person cancels, the device refuses, no credential comes back, or the signal aborts it. A
   * conditional request waits for the person to pick one of the passkeys the browser shows under the field.
   */
  async get(options: PasskeyOptions, how: PasskeyRequest = {}): Promise<PasskeyCredential> {
    const publicKey = PublicKeyCredential.parseRequestOptionsFromJSON(
      options as unknown as PublicKeyCredentialRequestOptionsJSON,
    );
    return toJson(await navigator.credentials.get({ publicKey, ...how }));
  }
}

function toJson(credential: Credential | null): PasskeyCredential {
  if (!(credential instanceof PublicKeyCredential)) {
    throw new Error('No passkey credential came back.');
  }
  // The DOM types name the two response shapes; the API takes either, whole, so the page never reads into them.
  return credential.toJSON() as unknown as PasskeyCredential;
}
