// SPDX-License-Identifier: AGPL-3.0-or-later
import { createHmac } from 'node:crypto';

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * The six-digit code an authenticator app shows for a secret (RFC 6238), with the defaults the API's otpauth URI
 * leaves implicit: SHA-1, a 30-second step, six digits. Computed here so a scenario can sign in like a person
 * holding the app, without any test-only door in the API.
 */
export function totp(secret: string, at: number = Date.now()): string {
  let bits = '';
  for (const char of secret.replace(/[\s=]/g, '').toUpperCase()) {
    const value = BASE32.indexOf(char);
    if (value < 0) {
      throw new Error(`not a base32 secret: "${char}"`);
    }
    bits += value.toString(2).padStart(5, '0');
  }
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
  const digest = createHmac('sha1', key).update(counter).digest();
  const offset = digest[digest.length - 1]! & 0x0f;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}
