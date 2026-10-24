// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import type { MfaStatus } from './auth-types';

/** How an account could be locked out with the second factors it holds; null when nothing threatens it. */
export interface LockoutRisk {
  /** one device stands between the person and the account: an authenticator and no passkey, or one passkey alone */
  readonly singleDevice: boolean;
  /** the recovery codes not spent yet */
  readonly codesLeft: number;
  /** a company the person belongs to requires the check, so only the installation's host can restore it */
  readonly required: boolean;
}

/** Three codes or fewer left is the moment to ask for new ones, before the last goes. */
export const FEW_RECOVERY_CODES = 3;

export function lockoutRisk(mfa: MfaStatus): LockoutRisk | null {
  if (!mfa.enrolled) return null;
  // One factor, an authenticator or a passkey alone, whatever codes back it: a second one is pushed then.
  const singleDevice = (mfa.totp && mfa.passkeys === 0) || (!mfa.totp && mfa.passkeys === 1);
  if (!singleDevice && mfa.recoveryCodes > FEW_RECOVERY_CODES) return null;
  return { singleDevice, codesLeft: mfa.recoveryCodes, required: mfa.required };
}

/**
 * The warning an account one lost device away from being locked out reads, in words, with what to do beside it: on
 * the two-step page and on Mon compte › Sécurité. Losing the phone of an account with an authenticator and no passkey
 * left only its recovery codes, which nothing counted on screen, and an account its company requires the check of
 * could then be restored by the installation's host alone (`bin/console app:mfa:replace`). Its texts are its
 * own part, `MFA_LOCKOUT_TEXTS`, which both routes open into the root.
 */
@Component({
  selector: 'app-mfa-lockout',
  imports: [MatButtonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (risk(); as risk) {
      <section
        class="flex flex-col gap-3 rounded-md border border-outline-variant p-4"
        data-testid="mfa-lockout"
      >
        <p class="m-0" data-testid="mfa-lockout-said">
          @if (risk.singleDevice) {
            {{ 'mfa_lockout.single_device' | translate: { count: risk.codesLeft } }}
          } @else if (risk.codesLeft === 0) {
            {{ 'mfa_lockout.codes_none' | translate }}
          } @else {
            {{ 'mfa_lockout.codes_low' | translate: { count: risk.codesLeft } }}
          }
        </p>
        @if (risk.required) {
          <p class="m-0" data-testid="mfa-lockout-required">
            {{ 'mfa_lockout.required' | translate }}
          </p>
        }
        <div class="flex flex-wrap gap-2">
          @if (risk.singleDevice && passkeysSupported()) {
            <button
              mat-stroked-button
              type="button"
              (click)="addPasskey.emit()"
              data-testid="mfa-lockout-add-passkey"
            >
              {{ 'mfa_lockout.add_passkey' | translate }}
            </button>
          }
          @if (codesRenewable()) {
            <button
              mat-stroked-button
              type="button"
              (click)="newCodes.emit()"
              data-testid="mfa-lockout-new-codes"
            >
              {{ 'mfa_lockout.new_codes' | translate }}
            </button>
          }
        </div>
      </section>
    }
  `,
})
export class MfaLockout {
  readonly mfa = input.required<MfaStatus>();
  /** whether this browser can make a passkey: the offer to add one is not made where it cannot */
  readonly passkeysSupported = input(true);
  /**
   * New codes take an authenticator code or a passkey this browser can use: an account with passkeys only, on a browser
   * that cannot use them, is not offered what it cannot do here.
   */
  protected readonly codesRenewable = computed(
    () => this.mfa().totp || (this.mfa().passkeys > 0 && this.passkeysSupported()),
  );
  readonly addPasskey = output();
  readonly newCodes = output();
  protected readonly risk = computed(() => lockoutRisk(this.mfa()));
}
