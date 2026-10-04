// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { TranslatePipe } from '@ngx-translate/core';
import { NotificationsFacade } from '../notifications/notifications-facade';
import { Label } from '../shared/a11y/label';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { CountBadge } from '../shared/ui/count-badge';
import { companyLogoUrl } from './company-api';
import { CompanyFacade } from './company-facade';

/**
 * Where the user is working, and the way to somewhere else. Renders nothing at all when there is only one
 * company: a menu with a single entry is furniture, not a choice.
 */
@Component({
  selector: 'app-company-switcher',
  imports: [CountBadge, Label, MatButtonModule, MatIconModule, MatMenuModule, TranslatePipe],
  templateUrl: './company-switcher.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CompanySwitcher implements OnInit {
  private readonly companyFacade = inject(CompanyFacade);

  protected readonly companies = this.companyFacade.companies;
  protected readonly current = this.companyFacade.current;
  protected readonly canSwitch = this.companyFacade.canSwitch;
  protected readonly switching = this.companyFacade.switching;
  private readonly language = inject(LanguageFacade);
  private readonly notifications = inject(NotificationsFacade);

  /** What each company has waiting (docs/SPEC.md § 7, 2026-09-26 22:34), a company with nothing absent. */
  protected readonly unread = this.notifications.unreadByCompany;

  /** What waits in the companies other than the one in use, on the closed button so it is noticed from here. */
  protected readonly elsewhere = computed(() => {
    const current = this.current()?.id;
    const counts = this.unread();
    return this.companies().reduce(
      (sum, company) => (company.id === current ? sum : sum + (counts[company.id] ?? 0)),
      0,
    );
  });

  /** In a bar, the name alone; at the head of the rail, a block with the company's mark, country and currency. */
  readonly variant = input<'bar' | 'rail'>('bar');
  /** The rail folded to icons: the mark alone, the name in a tooltip. */
  readonly folded = input(false);

  /** The first letter of the company's name, which stands for it where the name does not fit. */
  protected readonly initial = computed(
    () => [...(this.current()?.name.trim() ?? '')][0]?.toLocaleUpperCase() ?? '?',
  );

  /** « Tunisie · TND »: the country in the interface's language, and the currency the company counts in. */
  protected readonly where = computed(() => {
    const company = this.current();
    if (company === null) return '';
    // The API keeps an ISO 3166 alpha-2 code, which Intl names in every supported language.
    const country = new Intl.DisplayNames([this.language.current()], { type: 'region' }).of(
      company.countryCode,
    );
    return `${country ?? company.countryCode} · ${company.currency}`;
  });

  /** Where the logo of a listed company is read, or null when it has none (or the list has not arrived). */
  protected logoOf(company: { readonly id: string }): string | null {
    const version = this.companies().find((option) => option.id === company.id)?.logoVersion;
    return version == null ? null : companyLogoUrl(company.id, version);
  }

  async ngOnInit(): Promise<void> {
    await this.companyFacade.load();
  }

  protected async choose(companyId: string): Promise<void> {
    await this.companyFacade.switchTo(companyId);
  }
}
