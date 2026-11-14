// SPDX-License-Identifier: AGPL-3.0-or-later
import type { Signal } from '@angular/core';
import type { UnknownRefusal } from '../errors/unknown-refusal';

/** How a proof of who is at the screen came out. */
export type StepUpOutcome =
  | 'confirmed'
  /** The password or the passkey is not the account's. */
  | 'refused'
  /** The authenticator code asked beside the password is missing or wrong. */
  | 'code_refused'
  /** A password was sent where only the account's passkey proves it, the second factor required of it. */
  | 'passkey_required'
  | 'too_many'
  /** The account has no passkey, or the browser produced none: cancelled, timed out, or no authenticator. */
  | 'no_passkey'
  /** That wrong answer was the session's last: it is signed out, and the account stays open for a new sign-in. */
  | 'signed_out'
  /** An answer the proof has no words for (a 500, a code added later), said by its code, never as a wrong password. */
  | UnknownRefusal
  | 'network';

/**
 * What a proof of who is at the screen asks, the API's one answer for a step-up and a company's entry: only the proofs
 * the account holds.
 */
export interface ProofAsked {
  /** a passkey of the account would do instead of the password */
  readonly passkey: boolean;
  /** the authenticator code is asked beside the password */
  readonly code: boolean;
  /** false where the account's passkey is the second factor required of it: the passkey alone proves it */
  readonly password: boolean;
}

/**
 * The port shared code asks who is at the screen through, so shared/ depends on no feature. The auth feature's facade
 * answers it (app.config.ts); a test provides its own.
 */
export abstract class StepUpProof {
  /** What a step-up asks of this sign-in, read from the session. */
  abstract readonly proofAsked: Signal<ProofAsked>;
  abstract passkeySupported(): boolean;
  abstract withPassword(password: string, code: string | null): Promise<StepUpOutcome>;
  /** Runs the whole browser ceremony: options from the API, the authenticator, the answer back. */
  abstract withPasskey(): Promise<StepUpOutcome>;
}
