// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';

/** What each accepted type is called on screen, by the MIME type or extension a file input's `accept` names it with. */
const TYPE_NAMES: Record<string, string> = {
  'application/pdf': 'PDF',
  'image/png': 'PNG',
  'image/jpeg': 'JPEG',
  'image/webp': 'WebP',
  'text/csv': 'CSV',
  '.csv': 'CSV',
  '.xlsx': 'XLSX',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
};

/**
 * A file chosen by clicking or by dropping it on the zone, in place of the browser's own file button. It says what it
 * takes (the types and the size limit) before anything is chosen, and refuses a file of another type or over the limit
 * itself, so the person learns it at once rather than from the server. The real input stays in the zone, visually
 * hidden, so the keyboard and assistive technology reach it as a file button.
 */
@Component({
  selector: 'app-file-drop',
  imports: [MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'flex min-w-0 flex-col gap-1' },
  template: `
    <label
      class="flex cursor-pointer flex-col items-center gap-1 rounded-card border-2 border-dashed border-outline px-4 py-5 text-center text-sm focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary"
      [class.border-primary]="over()"
      [class.bg-surface-container]="over()"
      [class.opacity-60]="disabled()"
      [class.cursor-not-allowed]="disabled()"
      (dragover)="dragOver($event)"
      (dragleave)="over.set(false)"
      (drop)="dropped($event)"
      data-testid="file-drop-zone"
    >
      <mat-icon aria-hidden="true" class="text-primary">upload_file</mat-icon>
      <span class="font-medium">{{ label() }}</span>
      <span class="text-on-surface-variant">{{ 'form.file.drop' | translate }}</span>
      <span class="text-xs text-on-surface-variant" data-testid="file-drop-limits">{{
        limits()
      }}</span>
      @if (chosenName(); as name) {
        <span class="break-all text-xs font-medium" data-testid="file-drop-chosen">{{ name }}</span>
      }
      <input
        type="file"
        class="sr-only"
        [accept]="accept()"
        [disabled]="disabled()"
        (change)="changed($event)"
        [attr.data-testid]="testId()"
      />
    </label>
    @if (refusal(); as message) {
      <span role="alert" class="px-2 text-xs text-error" data-testid="file-drop-refused">{{
        message
      }}</span>
    }
  `,
})
export class FileDrop {
  readonly label = input.required<string>();
  readonly accept = input('');
  readonly maxBytes = input<number | null>(null);
  readonly disabled = input(false);
  readonly testId = input('');
  readonly chosenName = input<string | null>(null);
  readonly picked = output<File>();

  protected readonly over = signal(false);
  protected readonly refusal = signal<string | null>(null);
  private readonly translate = inject(TranslateService);

  private readonly tokens = computed(() =>
    this.accept()
      .split(',')
      .map((token) => token.trim().toLowerCase())
      .filter((token) => token !== ''),
  );

  private readonly typesText = computed(() =>
    [
      ...new Set(
        this.tokens().map((token) => TYPE_NAMES[token] ?? token.replace(/^\./, '').toUpperCase()),
      ),
    ].join(', '),
  );

  protected limits(): string {
    const types = this.typesText();
    const max = this.maxBytes();
    if (max === null) return types;
    return this.translate.instant('form.file.limits', { types, size: this.sizeText(max) });
  }

  protected dragOver(event: DragEvent): void {
    event.preventDefault();
    if (!this.disabled()) this.over.set(true);
  }

  protected dropped(event: DragEvent): void {
    event.preventDefault();
    this.over.set(false);
    const file = event.dataTransfer?.files?.item(0);
    if (file && !this.disabled()) this.take(file);
  }

  protected changed(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = input.files as FileList | File[] | null;
    const file = files ? ((files as FileList).item?.(0) ?? (files as File[])[0]) : undefined;
    // Emptied so choosing the same file again is a change again.
    if (file) this.take(file);
    try {
      input.value = '';
    } catch {
      /* a test double's files cannot be reset; a real input always can */
    }
  }

  private take(file: File): void {
    if (!this.accepts(file)) {
      this.refusal.set(this.translate.instant('form.file.refused', { types: this.typesText() }));
      return;
    }
    const max = this.maxBytes();
    if (max !== null && file.size > max) {
      this.refusal.set(this.translate.instant('form.file.too_large', { size: this.sizeText(max) }));
      return;
    }
    this.refusal.set(null);
    this.picked.emit(file);
  }

  private accepts(file: File): boolean {
    const tokens = this.tokens();
    if (tokens.length === 0) return true;
    const name = file.name.toLowerCase();
    const type = file.type.toLowerCase();
    return tokens.some((token) => (token.startsWith('.') ? name.endsWith(token) : type === token));
  }

  private sizeText(bytes: number): string {
    return bytes >= 1024 * 1024
      ? this.translate.instant('form.file.mb', { count: Math.round(bytes / (1024 * 1024)) })
      : this.translate.instant('form.file.kb', { count: Math.round(bytes / 1024) });
  }
}
