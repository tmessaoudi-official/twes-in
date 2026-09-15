// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, OnInit } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslatePipe } from '@ngx-translate/core';
import { PlatformFacade } from './platform-facade';
import type { SignupSwitch } from './platform-types';

/**
 * The operators' page: whether anyone may sign up, whether a company that signs up waits for approval, and the
 * companies waiting for it. Reached from the home page by operators only (operatorGuard).
 */
@Component({
  selector: 'app-platform-page',
  imports: [MatCardModule, MatButtonModule, MatSlideToggleModule, TranslatePipe],
  templateUrl: './platform-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlatformPage implements OnInit {
  private readonly platform = inject(PlatformFacade);

  protected readonly waiting = this.platform.waiting;
  protected readonly signup = this.platform.signup;
  protected readonly busy = this.platform.busy;
  protected readonly error = this.platform.error;

  async ngOnInit(): Promise<void> {
    await this.platform.load();
  }

  protected async turn(key: SignupSwitch, value: boolean): Promise<void> {
    await this.platform.setSignup(key, value);
  }

  protected async approve(companyId: string): Promise<void> {
    await this.platform.approve(companyId);
  }

  protected async reject(companyId: string): Promise<void> {
    await this.platform.reject(companyId);
  }
}
