// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe } from '../shared/i18n/format-pipes';
import type { CountEffect } from './count-sheet';

/**
 * What a count would change, said on its line before it is recorded, the same on every screen that counts: what is
 * recorded at that place and the difference the count makes, what is kept elsewhere, and — as a notice, never a
 * refusal — a place the product neither is at nor lives at, or a lot it never had. Its texts are the `stock-count`
 * part, which each route counting from opens into the root.
 */
@Component({
  selector: 'app-count-effect-note',
  imports: [TranslatePipe, AmountPipe],
  templateUrl: './count-effect-note.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CountEffectNote {
  readonly effect = input.required<CountEffect>();
  /** How many decimals the product's unit counts in. */
  readonly decimals = input.required<number>();
  /** Each place as the screens name it. */
  readonly places = input.required<ReadonlyMap<string, string>>();
  readonly testId = input.required<string>();
  /** Several places counted together each say their own figures; what is elsewhere is then the other rows. */
  readonly brief = input(false);

  protected name(locationId: string): string {
    return this.places().get(locationId) ?? '';
  }
}
