// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { declaredPayment, paymentForm, paymentFormValues } from './subscription-forms';
import { SubscriptionFacade } from './subscription-facade';

/**
 * The company's own subscription page: where it stands, what it costs, and the payments it declared. It is reachable
 * whatever the subscription says — a locked company reaches nothing else, and this is the way out (docs/SPEC.md § 7,
 * 2026-09-17). Nothing here takes money: the company says it paid, and an operator confirms it.
 */
@Component({
  selector: 'app-subscription-page',
  imports: [DatePipe, MatButtonModule, MatCardModule, TranslatePipe, DescriptorForm],
  templateUrl: './subscription-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SubscriptionPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(SubscriptionFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);

  protected readonly subscription = this.facade.subscription;
  protected readonly managed = this.facade.managed;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly declaring = signal(false);
  protected readonly descriptor = paymentForm();
  /** Keyed on nothing the API answers, so what is typed survives a reload that changes the standing. */
  protected readonly form = computed(() => (this.declaring() ? this.group : null));

  private readonly group = buildFormGroup(paymentForm(), paymentFormValues());

  /** What the payment is in: the subscription's currency where the terms name one, else the company's own. */
  protected readonly currency = computed(
    () => this.subscription()?.currency ?? this.company()?.currency ?? '',
  );

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['subscription', 'payment_declaration'],
        () => this.facade.load(companyId),
        this.destroyRef,
      );
      await this.facade.load(companyId);
    }
  }

  protected declare(): void {
    this.declaring.set(true);
  }

  protected cancel(): void {
    this.declaring.set(false);
  }

  protected async submit(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    if (await this.facade.declare(companyId, declaredPayment(values, this.currency()))) {
      this.declaring.set(false);
      this.group.reset(paymentFormValues());
      this.feedback.success('licensing.payment.declared');
    }
  }
}
