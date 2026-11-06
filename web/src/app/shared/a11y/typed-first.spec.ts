// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TypedFirst } from './typed-first';

@Component({
  imports: [TypedFirst],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button type="button" data-testid="other">Other</button>
    @if (shown()) {
      <input appTypedFirst data-testid="first" />
    }
  `,
})
class Host {
  readonly shown = signal(false);
}

describe('TypedFirst', () => {
  afterEach(() => (document.activeElement as HTMLElement | null)?.blur());

  async function render() {
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const query = (id: string) =>
      (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(`[data-testid="${id}"]`);
    return { fixture, query };
  }

  it('gives the cursor to the field as it appears, though the form is drawn only after a read', async () => {
    const { fixture, query } = await render();
    expect(query('first')).toBeNull();

    fixture.componentInstance.shown.set(true);
    await fixture.whenStable();

    expect(document.activeElement).toBe(query('first'));
  });

  it('never takes the cursor from what holds it', async () => {
    const { fixture, query } = await render();
    query('other')!.focus();

    fixture.componentInstance.shown.set(true);
    await fixture.whenStable();

    expect(document.activeElement).toBe(query('other'));
  });
});
