// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { marked } from 'marked';
import type { LegalLanguage } from './legal-api';

/**
 * A legal text's Markdown as the reader sees it, laid out in its own language's direction. `marked` turns it into
 * HTML and `[innerHTML]` binds it through Angular's sanitizer, so a script, an event handler or a `javascript:` link a
 * text carries never runs (`legal-text.spec.ts` reds when the sanitizer is bypassed). The public page and the
 * operator's preview show a text through this one component, so what is previewed is what is published.
 */
@Component({
  selector: 'app-legal-markdown',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div
    class="twes-legal-body"
    [attr.lang]="language()"
    [attr.dir]="language() === 'ar' ? 'rtl' : 'ltr'"
    [innerHTML]="html()"
    data-testid="legal-body"
  ></div>`,
})
export class LegalMarkdown {
  readonly body = input.required<string>();
  readonly language = input.required<LegalLanguage>();
  protected readonly html = computed(() => marked.parse(this.body(), { async: false }));
}
