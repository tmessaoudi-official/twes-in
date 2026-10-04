// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it } from 'vitest';
import { FileDrop } from './file-drop';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      form: {
        file: {
          drop: 'or drop it here',
          limits: '{{types}} · up to {{size}}',
          refused: 'Not accepted: {{types}}.',
          too_large: 'Over {{size}}.',
          mb: '{{count}} MB',
          kb: '{{count}} KB',
        },
      },
    });
  }
}

@Component({
  imports: [FileDrop],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-file-drop
    label="Choose a logo"
    accept="image/png,image/jpeg,.webp"
    [maxBytes]="2097152"
    testId="logo"
    (picked)="got.push($event)"
  />`,
})
class Host {
  readonly got: File[] = [];
}

describe('FileDrop', () => {
  let fixture: ComponentFixture<Host>;
  const q = (id: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${id}"]`);
  const settle = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };
  const drop = async (file: File): Promise<void> => {
    const event = new Event('drop', { bubbles: true, cancelable: true }) as Event & {
      dataTransfer: unknown;
    };
    event.dataTransfer = { files: { item: () => file, length: 1 } };
    q('file-drop-zone')!.dispatchEvent(event);
    await settle();
  };

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it('says what it takes before anything is chosen: the types by name and the size limit', () => {
    expect(q('file-drop-limits')!.textContent).toBe('PNG, JPEG, WEBP · up to 2 MB');
    expect(q('file-drop-zone')!.textContent).toContain('Choose a logo');
    expect(q('file-drop-zone')!.textContent).toContain('or drop it here');
  });

  it('hands over a file dropped on it, and one chosen through the input', async () => {
    const dropped = new File(['x'], 'a.png', { type: 'image/png' });
    await drop(dropped);
    const input = q('logo') as HTMLInputElement;
    const chosen = new File(['y'], 'b.webp', { type: '' });
    Object.defineProperty(input, 'files', { value: { item: () => chosen, length: 1 } });
    input.dispatchEvent(new Event('change'));
    await settle();

    expect(fixture.componentInstance.got).toEqual([dropped, chosen]);
  });

  it('refuses another type and a file over the limit, and says why', async () => {
    await drop(new File(['x'], 'a.pdf', { type: 'application/pdf' }));
    expect(fixture.componentInstance.got).toEqual([]);
    expect(q('file-drop-refused')!.textContent).toContain('Not accepted: PNG, JPEG, WEBP.');

    const big = new File(['x'], 'big.png', { type: 'image/png' });
    Object.defineProperty(big, 'size', { value: 3 * 1024 * 1024 });
    await drop(big);
    expect(fixture.componentInstance.got).toEqual([]);
    expect(q('file-drop-refused')!.textContent).toContain('Over 2 MB.');
  });
});
