// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Signal } from '@angular/core';
import type { StepUpOutcome } from '../step-up/step-up-proof';

/** What the working company asks before this sign-in may act in it. */
export interface EntryAsked {
  readonly companyId: string;
  readonly companyName: string;
  /** a passkey of the account would do instead of the password */
  readonly passkey: boolean;
  /** the authenticator code is asked beside the password */
  readonly code: boolean;
}

/** How entering came out: a step-up's outcomes, and a code the company asks that is missing or wrong. */
export type EntryOutcome = StepUpOutcome | 'code_refused';

/**
 * The port shared code proves the entry into a company through, so shared/ depends on no feature. An account in two
 * companies or more proves itself once per sign-in for each it enters; the auth feature's facade answers this.
 */
export abstract class CompanyEntryProof {
  /** null while the working company asks nothing, or nobody is signed in */
  abstract readonly asked: Signal<EntryAsked | null>;
  abstract passkeySupported(): boolean;
  abstract enterWithPassword(
    companyId: string,
    password: string,
    code: string | null,
  ): Promise<EntryOutcome>;
  /** Runs the whole browser ceremony: options from the API, the authenticator, the answer back. */
  abstract enterWithPasskey(companyId: string): Promise<EntryOutcome>;
  /** Reads the session again: a proof that lapsed while the page was open shows as asked. */
  abstract reread(): Promise<void>;
}
