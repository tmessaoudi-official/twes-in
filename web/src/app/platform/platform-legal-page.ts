// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  type OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { Feedback } from '../shared/feedback/feedback';
import { DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import {
  LEGAL_LANGUAGE_NAMES,
  LEGAL_LANGUAGES,
  type LegalLanguage,
} from '../shared/legal/legal-api';
import { LegalMarkdown } from '../shared/legal/legal-markdown';
import { LEGAL_PAGES, type LegalPage } from '../shared/legal/legal-pages';
import { PlatformLegalFacade } from './platform-legal-facade';
import type { LegalStatusRow, LegalVersionRow } from './platform-legal-types';

interface Opened {
  readonly page: LegalPage;
  readonly language: LegalLanguage;
}

/**
 * The platform's legal pages as an operator writes them (docs/SPEC.md § 8 row 148): every page in every language and
 * where it stands, then the one opened, in Markdown, with a preview rendered exactly as the public page renders it.
 * Publishing adds a new version at once, marked a draft; validating marks the latest one checked. What was typed for a
 * page and not published stays with that page while another is opened, for as long as this screen is.
 */
@Component({
  selector: 'app-platform-legal-page',
  imports: [
    DayPipe,
    LegalMarkdown,
    MatButtonModule,
    MatIconModule,
    MomentPipe,
    RouterLink,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './platform-legal-page.html',
})
export class PlatformLegalPage implements OnInit {
  protected readonly facade = inject(PlatformLegalFacade);
  private readonly feedback = inject(Feedback);
  protected readonly pages = LEGAL_PAGES;
  protected readonly languages = LEGAL_LANGUAGES;
  protected readonly names = LEGAL_LANGUAGE_NAMES;

  protected readonly opened = signal<Opened | null>(null);
  protected readonly text = signal('');
  /** Until the open page's history arrives, which then fills the editor: anything typed sooner would be replaced. */
  protected readonly loading = signal(false);
  protected readonly previewing = signal(false);
  /** The texts typed and not published, by page and language. */
  private readonly drafts = new Map<string, string>();

  protected readonly statuses = computed(
    () => new Map(this.facade.overview().map((row) => [key(row.page, row.language), row])),
  );
  /** The open page's latest version, null when it was never written in that language. */
  protected readonly latest = computed<LegalVersionRow | null>(
    () => this.facade.versions()[0] ?? null,
  );
  protected readonly changed = computed(
    () => this.text().trim() !== '' && this.text() !== (this.latest()?.body ?? ''),
  );

  /** The identity as typed, until saved: the saved one again after every load or save. */
  protected readonly identityDraft = linkedSignal<Readonly<Record<string, string>>>(() => ({
    ...this.facade.identity(),
  }));
  protected readonly identityFields = computed(() => Object.keys(this.facade.identity()));
  private readonly identityChanges = computed(() => {
    const saved = this.facade.identity();
    const draft = this.identityDraft();
    return Object.fromEntries(Object.entries(draft).filter(([key, value]) => value !== saved[key]));
  });
  protected readonly identityChanged = computed(
    () => Object.keys(this.identityChanges()).length > 0,
  );
  /** What the preview fills the placeholders with: the saved identity, as the public page will. */
  protected readonly filled = computed(() =>
    Object.fromEntries(
      Object.entries(this.facade.identity()).filter(([, value]) => value.trim() !== ''),
    ),
  );

  ngOnInit(): void {
    void this.facade.load();
  }

  protected statusOf(page: LegalPage, language: LegalLanguage): LegalStatusRow | undefined {
    return this.statuses().get(key(page, language));
  }

  protected isOpen(page: LegalPage, language: LegalLanguage): boolean {
    const opened = this.opened();
    return opened !== null && opened.page === page && opened.language === language;
  }

  protected async open(page: LegalPage, language: LegalLanguage): Promise<void> {
    const leaving = this.opened();
    if (leaving !== null) {
      if (this.changed()) {
        this.drafts.set(key(leaving.page, leaving.language), this.text());
      } else {
        this.drafts.delete(key(leaving.page, leaving.language));
      }
    }
    this.opened.set({ page, language });
    this.previewing.set(false);
    this.text.set('');
    this.loading.set(true);
    await this.facade.open(page, language);
    if (!this.isOpen(page, language)) return;
    this.text.set(this.drafts.get(key(page, language)) ?? this.latest()?.body ?? '');
    this.loading.set(false);
  }

  /** How a text writes this fact, for the operator to copy. */
  protected written(placeholder: string): string {
    return `{{${placeholder}}}`;
  }

  protected typedIdentity(placeholder: string, event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.identityDraft.update((draft) => ({ ...draft, [placeholder]: value }));
  }

  protected async saveIdentity(): Promise<void> {
    if (await this.facade.saveIdentity(this.identityChanges())) {
      this.feedback.success('platform.legal.identity.saved');
    }
  }

  protected typed(event: Event): void {
    this.text.set((event.target as HTMLTextAreaElement).value);
  }

  protected reuse(version: LegalVersionRow): void {
    this.text.set(version.body);
    this.previewing.set(false);
  }

  protected async publish(): Promise<void> {
    const opened = this.opened();
    if (opened === null || !(await this.facade.write(this.text()))) return;
    this.drafts.delete(key(opened.page, opened.language));
    this.text.set(this.latest()?.body ?? this.text());
    this.feedback.success('platform.legal.published');
  }

  protected async validate(): Promise<void> {
    if (await this.facade.validate()) {
      this.feedback.success('platform.legal.validated_toast');
    }
  }
}

function key(page: LegalPage, language: LegalLanguage): string {
  return `${page}.${language}`;
}
