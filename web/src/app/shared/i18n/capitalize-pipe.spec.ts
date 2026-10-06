// SPDX-License-Identifier: AGPL-3.0-or-later

import { CapitalizePipe } from './capitalize-pipe';

describe('CapitalizePipe', () => {
  const pipe = new CapitalizePipe();

  it('raises the first letter only, so a name standing alone reads as one and keeps its other words', () => {
    // A role is translated lowercase to sit inside sentences; alone in a column it read « propriétaire ».
    expect(pipe.transform('propriétaire')).toBe('Propriétaire');
    expect(pipe.transform('caissier / vendeur')).toBe('Caissier / vendeur');
    expect(pipe.transform('état')).toBe('État');
  });

  it('leaves nothing as nothing', () => {
    expect(pipe.transform('')).toBe('');
    expect(pipe.transform(null)).toBe('');
  });
});
