// SPDX-License-Identifier: AGPL-3.0-or-later

import type { DestroyRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { StorageChanges } from './storage-changes';

/** What another tab of this browser writing the key looks like here: the browser says it in every other document. */
function writtenInAnotherTab(key: string | null): void {
  window.dispatchEvent(new StorageEvent('storage', { key }));
}

describe('StorageChanges', () => {
  it('tells a follower of a key when another tab writes it, and of nothing else', () => {
    const changes = TestBed.inject(StorageChanges);
    const heard: string[] = [];
    changes.follow('twes.scan.gap', () => heard.push('gap'));
    changes.follow(
      (key) => key.startsWith('twes.settings.'),
      () => heard.push('settings'),
    );

    writtenInAnotherTab('twes.scan.gap');
    writtenInAnotherTab('twes.settings.u1.theme.scheme');
    writtenInAnotherTab('twes.text-size');

    expect(heard).toEqual(['gap', 'settings']);
  });

  it('tells every follower when another tab clears the storage', () => {
    const changes = TestBed.inject(StorageChanges);
    const heard: string[] = [];
    changes.follow('twes.scan.gap', () => heard.push('gap'));
    changes.follow('twes.cookie-notice', () => heard.push('notice'));

    writtenInAnotherTab(null);

    expect(heard).toEqual(['gap', 'notice']);
  });

  it('stops telling a follower whose owner is gone, through one listener for every key', () => {
    const add = vi.spyOn(window, 'addEventListener');
    const changes = TestBed.inject(StorageChanges);
    const heard: string[] = [];
    let gone: () => void = () => undefined;
    const ref = { onDestroy: (stop: () => void) => (gone = stop) } as unknown as DestroyRef;
    changes.follow('twes.scan.gap', () => heard.push('gap'), ref);
    changes.follow('twes.scan.min-length', () => heard.push('length'));

    gone();
    writtenInAnotherTab('twes.scan.gap');
    writtenInAnotherTab('twes.scan.min-length');

    expect(heard).toEqual(['length']);
    expect(add.mock.calls.filter(([type]) => type === 'storage')).toHaveLength(1);
    add.mockRestore();
  });
});
