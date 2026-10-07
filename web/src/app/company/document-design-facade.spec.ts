// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { DocumentDesignApi, PreviewRefused } from './document-design-api';
import { DocumentDesignFacade } from './document-design-facade';
import type { DocumentDesign } from './document-design-types';

describe('DocumentDesignFacade', () => {
  const answers: {
    design: DocumentDesign;
    settle: (picture: string) => void;
    refuse: (e: unknown) => void;
  }[] = [];
  const api = {
    preview: vi.fn(
      (_companyId: string, design: DocumentDesign) =>
        new Promise<string>((settle, refuse) => answers.push({ design, settle, refuse })),
    ),
  };
  let facade: DocumentDesignFacade;

  beforeEach(() => {
    answers.length = 0;
    TestBed.configureTestingModule({ providers: [{ provide: DocumentDesignApi, useValue: api }] });
    facade = TestBed.inject(DocumentDesignFacade);
  });

  it('shows the picture of the design asked for last, whatever order the answers come in', async () => {
    const first = facade.preview('c1', { layout: 'classic', accent: '#1f2328' });
    const second = facade.preview('c1', { layout: 'modern', accent: '#1f6feb' });
    expect(facade.state()).toBe('loading');

    answers[1]!.settle('data:modern');
    await second;
    answers[0]!.settle('data:classic');
    await first;

    expect([facade.picture(), facade.state()]).toEqual(['data:modern', 'ready']);
  });

  it('says why there is no picture, and keeps none from an earlier design', async () => {
    const shown = facade.preview('c1', { layout: 'classic', accent: '#1f2328' });
    answers[0]!.settle('data:classic');
    await shown;

    const refused = facade.preview('c1', { layout: 'modern', accent: '#1f6feb' });
    expect(facade.picture()).toBe('data:classic');
    answers[1]!.refuse(new PreviewRefused('nothing'));
    await refused;

    expect([facade.picture(), facade.state()]).toEqual([null, 'nothing']);
  });
});
