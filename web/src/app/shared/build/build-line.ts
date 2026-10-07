// SPDX-License-Identifier: AGPL-3.0-or-later

import { Clipboard } from '@angular/cdk/clipboard';
import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatTooltip } from '@angular/material/tooltip';
import { TranslatePipe } from '@ngx-translate/core';
import { Feedback } from '../feedback/feedback';
import { BuildInfo } from './build-info';

/**
 * The builds in the footer, to everyone (docs/SPEC.md § 7, the build line): « Web 2026.10.07.3 · API 2026.10.07.5 »,
 * each part's mode after its version when it is not production, and the deployment last when it is not prod. The
 * hashes come on hover or focus, and a click copies the whole line, which is what support asks for. Its accessible
 * name is what it shows; the tooltip describes it.
 */
@Component({
  selector: 'app-build-line',
  imports: [MatTooltip, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      type="button"
      class="cursor-pointer"
      [matTooltip]="'build.hint' | translate: hashes()"
      (click)="copy()"
      data-testid="build-line"
    >
      <span data-testid="build-web"
        >Web {{ web()?.version ?? ('build.unversioned' | translate)
        }}{{ info.webMode ? ' ' + info.webMode : '' }}</span
      >
      @if (api(); as api) {
        <span aria-hidden="true"> · </span>
        <span data-testid="build-api"
          >API {{ api.version ?? ('build.unversioned' | translate)
          }}{{ api.mode !== 'prod' ? ' ' + api.mode : '' }}</span
        >
        @if (api.deployment && api.deployment !== 'prod') {
          <span aria-hidden="true"> · </span>
          <span data-testid="build-deployment">[{{ api.deployment }}]</span>
        }
      }
    </button>
  `,
})
export class BuildLine {
  protected readonly info = inject(BuildInfo);
  private readonly clipboard = inject(Clipboard);
  private readonly feedback = inject(Feedback);
  protected readonly web = this.info.web;
  protected readonly api = this.info.api;
  protected readonly hashes = computed(() => ({
    web: this.web()?.commit ?? '?',
    api: this.api()?.commit ?? '?',
  }));

  constructor() {
    this.info.start();
  }

  protected copy(): void {
    if (this.clipboard.copy(this.info.line())) this.feedback.success('build.copied');
  }
}
