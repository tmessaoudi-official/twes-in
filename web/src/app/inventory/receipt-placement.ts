// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../shared/a11y/label';
import { DecimalInput } from '../shared/form/decimal-input';
import { Select, type SelectOption } from '../shared/form/select';
import type { StatusTone } from '../shared/theme/accent-theme';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  addPlace,
  type Part,
  placement,
  removePlace,
  restToDefault,
  setPlaceLocation,
  setPlaceQuantity,
} from './split-receipt';

const TONES: Record<ReturnType<typeof placement>['state'], StatusTone> = {
  short: 'warning',
  done: 'success',
  over: 'danger',
  invalid: 'neutral',
};

/**
 * One delivery shared over several places: a row per place with what goes there, and « il reste N à placer » until it
 * adds up; or a count over several places, a row per place with what was found there. It holds nothing: the rows come in and go back out whole, so the page owns them and the arithmetic stays in
 * whole thousandths in `split-receipt.ts`.
 */
@Component({
  selector: 'app-receipt-placement',
  imports: [
    FormsModule,
    MatButtonModule,
    MatIconModule,
    TranslatePipe,
    Label,
    DecimalInput,
    Select,
    StatusBadge,
  ],
  templateUrl: './receipt-placement.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReceiptPlacement {
  /** What the delivery brought, as typed in the form. */
  readonly received = input.required<string>();
  readonly parts = input.required<readonly Part[]>();
  /** The company's places a row may name, labelled by their path. */
  readonly places = input.required<readonly SelectOption[]>();
  /** Where what is left goes, for the establishment. */
  readonly defaultPlace = input<string | null>(null);
  /** How many decimals the product's unit keeps: a piece is whole, a kilogram has three. */
  readonly decimals = input(3);
  /** A save was refused for what is unplaced: the line says so. */
  readonly refused = input(false);
  /**
   * A count rather than a delivery: each row is what was found at its place, so there is no total to share out and
   * no « il reste » (audit 2026-10-06, H-b3).
   */
  readonly counting = input(false);
  readonly partsChange = output<readonly Part[]>();

  protected readonly stand = computed(() =>
    placement(
      this.received(),
      this.parts().map((part) => part.quantity),
      this.decimals(),
    ),
  );
  protected readonly tone = computed(() => TONES[this.stand().state]);
  protected readonly allNamed = computed(() =>
    this.places().every((place) => this.parts().some((part) => part.locationId === place.value)),
  );
  protected readonly restTo = computed(
    () => this.defaultPlace() ?? this.places()[0]?.value ?? null,
  );

  protected add(): void {
    this.partsChange.emit(
      addPlace(
        this.parts(),
        this.places().map((place) => ({ id: place.value })),
      ),
    );
  }

  protected remove(index: number): void {
    this.partsChange.emit(removePlace(this.parts(), index));
  }

  protected typed(index: number, quantity: string): void {
    this.partsChange.emit(setPlaceQuantity(this.parts(), index, quantity));
  }

  protected moved(index: number, locationId: string): void {
    this.partsChange.emit(setPlaceLocation(this.parts(), index, locationId));
  }

  protected rest(): void {
    const place = this.restTo();
    if (place !== null)
      this.partsChange.emit(restToDefault(this.parts(), this.received(), place, this.decimals()));
  }
}
