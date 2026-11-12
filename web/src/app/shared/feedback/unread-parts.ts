// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RefusalPipe } from '../errors/refusal-pipe';
import type { UnreadPart } from '../errors/unknown-refusal';

/**
 * The parts another feature reads for a list that it could not read, each in its own line under the list's title: the
 * list shows without them. A record's form says its parts where they would be, in `DescriptorForm`, instead.
 */
@Component({
  selector: 'app-unread-parts',
  imports: [RefusalPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @for (part of parts(); track part.unread) {
      <!-- read-retry: none, the part's sentence says to reload the page -->
      <p role="alert" class="text-error" [attr.data-testid]="testId() + '-' + part.unread">
        {{ part | refusal: 'form.errors' }}
      </p>
    }
  `,
})
export class UnreadParts {
  readonly parts = input.required<readonly UnreadPart[]>();
  /** The lines' test ids are this prefix and the part's name. */
  readonly testId = input.required<string>();
}
