// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, OnInit, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslatePipe } from '@ngx-translate/core';
import { PlatformFacade } from './platform-facade';
import type { AccountAction, SignupSwitch } from './platform-types';

/**
 * The operators' page: whether anyone may sign up, whether a company that signs up waits for approval, the
 * companies waiting for it, and accounts, whose sessions an operator ends and which they deactivate or reactivate. Reached from the home page by operators only (operatorGuard).
 */
@Component({
  selector: 'app-platform-page',
  imports: [
    MatCardModule,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    MatSlideToggleModule,
    TranslatePipe,
  ],
  templateUrl: './platform-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlatformPage implements OnInit {
  private readonly platform = inject(PlatformFacade);

  protected readonly waiting = this.platform.waiting;
  protected readonly signup = this.platform.signup;
  protected readonly busy = this.platform.busy;
  protected readonly error = this.platform.error;
  protected readonly accounts = this.platform.accounts;
  protected readonly search = signal('');

  async ngOnInit(): Promise<void> {
    await this.platform.load();
  }

  protected async turn(key: SignupSwitch, value: boolean): Promise<void> {
    await this.platform.setSignup(key, value);
  }

  protected async find(): Promise<void> {
    await this.platform.findAccounts(this.search().trim());
  }

  protected async act(userId: string, action: AccountAction): Promise<void> {
    await this.platform.actOnAccount(userId, action);
  }

  protected typed(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected async approve(companyId: string): Promise<void> {
    await this.platform.approve(companyId);
  }

  protected async reject(companyId: string): Promise<void> {
    await this.platform.reject(companyId);
  }
}
