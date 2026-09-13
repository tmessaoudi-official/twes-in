// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import { DESIGN_CUSTOMER_FORM } from './design-customer-form';

/** The customer form of the design checkpoint: DescriptorForm over a fixture descriptor; saving stores nothing. */
@Component({
  selector: 'app-design-customer-form-page',
  imports: [DescriptorForm, MatButtonModule, RouterLink, TranslatePipe],
  templateUrl: './design-customer-form-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DesignCustomerFormPage {
  protected readonly descriptor = DESIGN_CUSTOMER_FORM;
  protected readonly form = buildFormGroup(DESIGN_CUSTOMER_FORM);
  protected readonly saved = signal(false);

  protected save(): void {
    this.saved.set(true);
  }
}
