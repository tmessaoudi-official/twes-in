// SPDX-License-Identifier: AGPL-3.0-or-later

import { saveFile } from './save-file';

describe('saveFile', () => {
  afterEach(() => vi.restoreAllMocks());

  it('hands the browser the file under its name, then lets go of it', () => {
    const create = vi.fn(() => 'blob:tej');
    const revoke = vi.fn();
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke });
    const clicked: { href: string; download: string; attached: boolean }[] = [];
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      clicked.push({ href: this.href, download: this.download, attached: this.isConnected });
    });
    const file = new Blob(['<x/>'], { type: 'application/xml' });

    saveFile(file, '1234567A-2026-08-0.xml');

    expect(create).toHaveBeenCalledWith(file);
    expect(clicked).toEqual([
      { href: 'blob:tej', download: '1234567A-2026-08-0.xml', attached: true },
    ]);
    expect(revoke).toHaveBeenCalledWith('blob:tej');
    expect(document.querySelector('a[download]')).toBeNull();
  });
});
