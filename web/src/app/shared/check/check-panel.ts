// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';

/** One thing a record lacks, as a chip: its text key and the words it takes, under an id the list tracks it by. */
export interface CheckItem {
  readonly id: string;
  readonly key: string;
  readonly params?: Readonly<Record<string, string>>;
}

/**
 * « À vérifier » on a record's page, the same for every subject: what it lacks, as the API says it to this reader, and
 * the mark a person put on it with its note. Each reason leaves once what it names is filled in; the mark is set and
 * cleared in the form below.
 */
@Component({
  selector: 'app-check-panel',
  imports: [MatIconModule, TranslatePipe],
  templateUrl: './check-panel.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CheckPanel {
  readonly items = input.required<readonly CheckItem[]>();
  readonly marked = input(false);
  readonly note = input<string | null>(null);
  /** The line above the reasons, which names the record: « Il manque à cet article : ». */
  readonly heading = input.required<string>();
  /** The prefix of the panel's test ids, the subject's own. */
  readonly testId = input.required<string>();
}
