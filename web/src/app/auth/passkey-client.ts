// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';
import type { PasskeyCredential, PasskeyOptions } from './auth-types';

/**
 * The browser's WebAuthn and nothing else: options in the JSON the API sends, a credential back in the JSON it expects
 * (WebAuthn Level 3 `parseCreationOptionsFromJSON`, `parseRequestOptionsFromJSON` and `toJSON`). Behind one injectable
 * so the pages never touch `navigator.credentials` and specs can stand in for the authenticator.
 */
@Injectable({ providedIn: 'root' })
export class PasskeyClient {
  /** Whether this browser can create and use a passkey from JSON options at all. */
  supported(): boolean {
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

  /** Rejects when the person cancels, the device refuses, or no credential comes back. */
  async get(options: PasskeyOptions): Promise<PasskeyCredential> {
    const publicKey = PublicKeyCredential.parseRequestOptionsFromJSON(
      options as unknown as PublicKeyCredentialRequestOptionsJSON,
    );
    return toJson(await navigator.credentials.get({ publicKey }));
  }
}

function toJson(credential: Credential | null): PasskeyCredential {
  if (!(credential instanceof PublicKeyCredential)) {
    throw new Error('No passkey credential came back.');
  }
  // The DOM types name the two response shapes; the API takes either, whole, so the page never reads into them.
  return credential.toJSON() as unknown as PasskeyCredential;
}
