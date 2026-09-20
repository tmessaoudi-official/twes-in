// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { RecordBar } from './record-bar';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      form: {
        save: 'Save',
        revert: 'Discard changes',
        unsaved: '{{count}} unsaved changes',
        one_unsaved: '1 unsaved change',
      },
    });
  }
}

@Component({
  imports: [RecordBar],
  template: `<app-record-bar
    [changes]="changes()"
    [busy]="busy()"
    (save)="did.push('save')"
    (revert)="did.push('revert')"
  />`,
})
class Host {
  readonly changes = signal(0);
  readonly busy = signal(false);
  readonly did: string[] = [];
}

describe('RecordBar', () => {
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host],
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

  it('offers nothing to save while nothing has changed', () => {
    // A save that is always available teaches nothing about whether there is anything to save.
    expect((q('record-save') as HTMLButtonElement).disabled).toBe(true);
    expect(q('record-revert')).toBeNull();
    expect(q('record-changes')).toBeNull();
  });

  it('comes alive once something changed, and says how much is unsaved', async () => {
    fixture.componentInstance.changes.set(3);
    await settle();

    expect((q('record-save') as HTMLButtonElement).disabled).toBe(false);
    expect(q('record-changes')?.textContent).toContain('3 unsaved changes');
    expect(q('record-revert')).not.toBeNull();
  });

  it('counts one change in the singular, since "1 unsaved changes" is not a sentence', async () => {
    fixture.componentInstance.changes.set(1);
    await settle();
    expect(q('record-changes')?.textContent).toContain('1 unsaved change');
  });

  it('announces the count, which is the only thing saying a form was left half-filled', async () => {
    fixture.componentInstance.changes.set(2);
    await settle();
    expect(q('record-changes')?.getAttribute('role')).toBe('status');
  });

  it('saves and discards through the page, which owns the form', async () => {
    fixture.componentInstance.changes.set(1);
    await settle();

    q('record-save')!.click();
    q('record-revert')!.click();
    expect(fixture.componentInstance.did).toEqual(['save', 'revert']);
  });

  it('refuses both while a save is in flight, without taking them off the screen', async () => {
    fixture.componentInstance.changes.set(1);
    fixture.componentInstance.busy.set(true);
    await settle();

    expect((q('record-save') as HTMLButtonElement).disabled).toBe(true);
    expect((q('record-revert') as HTMLButtonElement).disabled).toBe(true);
    expect(q('record-changes')).not.toBeNull();
  });
});
