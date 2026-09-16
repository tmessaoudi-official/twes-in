// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, OnInit, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslatePipe } from '@ngx-translate/core';
import { PlatformFacade } from './platform-facade';
import {
  COMPANY_COUNTRIES,
  type AccountAction,
  type CompanyCountry,
  type SignupSwitch,
} from './platform-types';
import { Feedback } from '../shared/feedback/feedback';

/**
 * The operators' page: whether anyone may sign up, whether a company that signs up waits for approval, the
 * companies waiting for it, every company, which an operator opens and invites owners into, and accounts, whose
 * sessions an operator ends and which they deactivate or reactivate. Reached from the home page by operators only
 * (operatorGuard).
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
  private readonly feedback = inject(Feedback);

  protected readonly waiting = this.platform.waiting;
  protected readonly signup = this.platform.signup;
  protected readonly busy = this.platform.busy;
  protected readonly error = this.platform.error;
  protected readonly accounts = this.platform.accounts;
  protected readonly search = signal('');
  protected readonly companies = this.platform.companies;
  protected readonly countries = Object.keys(COMPANY_COUNTRIES) as CompanyCountry[];
  protected readonly companyName = signal('');
  protected readonly companyCountry = signal<CompanyCountry>('TN');
  protected readonly ownerEmail = signal('');
  /** The address the last invitation went to, once it went. */
  /** What is typed in each company's owner field, by company. */
  protected readonly ownerEmails = signal<Readonly<Record<string, string>>>({});

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

  protected async open(): Promise<void> {
    const email = this.ownerEmail().trim();
    if (await this.platform.openCompany(this.companyName().trim(), this.companyCountry(), email)) {
      this.feedback.success('platform.companies.invited', { email });
      this.companyName.set('');
      this.ownerEmail.set('');
    }
  }

  protected async invite(companyId: string): Promise<void> {
    const email = (this.ownerEmails()[companyId] ?? '').trim();
    if (await this.platform.inviteOwner(companyId, email)) {
      this.feedback.success('platform.companies.invited', { email });
      this.ownerEmails.update((typed) => ({ ...typed, [companyId]: '' }));
    }
  }

  protected typedOwner(companyId: string, event: Event): void {
    const value = this.typed(event);
    this.ownerEmails.update((typed) => ({ ...typed, [companyId]: value }));
  }

  protected chosen(event: Event): CompanyCountry {
    const value = (event.target as HTMLSelectElement).value;
    return this.countries.find((code) => code === value) ?? 'TN';
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
