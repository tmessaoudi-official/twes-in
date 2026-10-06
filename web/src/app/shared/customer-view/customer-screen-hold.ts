// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The port shared code holds the sign-in on the customer screen through, so shared/ depends on no feature. The API
 * keeps the hold in the session, so every tab of the browser is held with it; the auth feature's facade answers this
 * port (app.config.ts) and reads the session again after each change. A test provides its own.
 */
export abstract class CustomerScreenHold {
  /** Holds the sign-in on this company's customer screen; false when the API would not. */
  abstract hold(companyId: string): Promise<boolean>;
  /** Lets go of it, which takes a fresh proof of who is at the screen; false when the API kept it. */
  abstract release(): Promise<boolean>;
  /** Reads the sign-in again, after the API said it is held. */
  abstract reread(): Promise<void>;
}
