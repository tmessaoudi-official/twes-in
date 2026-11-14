// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from '@playwright/test';
import { createHash, createHmac } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

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

const STEP = 30_000;

/**
 * The newest step a code of this secret was given in, kept in a file rather than in this module: the API refuses a
 * step's code once spent, as a replay, and what it spent outlives the process that spent it. Playwright starts a new
 * worker after a failed test and runs the setup in another, and a run started again at once meets the steps the last
 * one spent; a step taken ahead (below) is still the newest the API holds for up to a minute.
 */
function spentFile(secret: string): string {
  return join(
    tmpdir(),
    `twes-e2e-totp-${createHash('sha256').update(secret).digest('hex').slice(0, 16)}`,
  );
}

function spentStepOf(secret: string): number {
  // No file: no code of this secret was given on this machine yet.
  if (!existsSync(spentFile(secret))) return -1;
  return Number.parseInt(readFileSync(spentFile(secret), 'utf8'), 10);
}

function spend(secret: string, step: number): void {
  writeFileSync(spentFile(secret), String(Math.max(step, spentStepOf(secret))));
}

/**
 * A code from a step this run has not spent yet: a step-up just after the sign-in, or a company's proof, would
 * otherwise send the code the sign-in spent, which the API refuses as a replay. The API takes one step of leeway, as
 * an app running a few seconds fast shows the next step's code, so a second proof in the same step sends that one at
 * once; only a third waits for the clock, up to thirty seconds. That wait is the authenticator's, not the machine's,
 * so the scenario's budget grows by exactly it: a scenario proving right after one that spent both steps would
 * otherwise spend its whole thirty seconds standing still.
 */
export async function freshTotp(secret: string): Promise<string> {
  const step = Math.max(Math.floor(Date.now() / STEP), spentStepOf(secret) + 1);
  if (step > Math.floor(Date.now() / STEP) + 1) {
    const wait = (step - 1) * STEP - Date.now() + 1_000;
    test.info().setTimeout(test.info().timeout + wait);
    await new Promise((resolve) => setTimeout(resolve, wait));
  }
  spend(secret, step);
  return totp(secret, step * STEP);
}

/** Marks the step it is as spent, after the API refused its code as a replay: a code typed outside these helpers spent it. */
export function stepSpent(secret: string): void {
  spend(secret, Math.floor(Date.now() / STEP));
}
