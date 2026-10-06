// SPDX-License-Identifier: AGPL-3.0-or-later

/** How a proof of who is at the screen came out. */
export type StepUpOutcome =
  | 'confirmed'
  /** The password or the passkey is not the account's. */
  | 'refused'
  | 'too_many'
  /** The account has no passkey, or the browser produced none: cancelled, timed out, or no authenticator. */
  | 'no_passkey'
  /** That wrong answer was the session's last: it is signed out, and the account stays open for a new sign-in. */
  | 'signed_out'
  | 'network';

/**
 * The port shared code asks who is at the screen through, so shared/ depends on no feature. The auth feature's facade
 * answers it (app.config.ts); a test provides its own.
 */
export abstract class StepUpProof {
  abstract passkeySupported(): boolean;
  abstract withPassword(password: string): Promise<StepUpOutcome>;
  /** Runs the whole browser ceremony: options from the API, the authenticator, the answer back. */
  abstract withPasskey(): Promise<StepUpOutcome>;
}
