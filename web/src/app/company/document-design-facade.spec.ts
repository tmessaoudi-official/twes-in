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
    logoRatio: vi.fn(async () => 3),
  };
  let facade: DocumentDesignFacade;

  beforeEach(() => {
    answers.length = 0;
    TestBed.configureTestingModule({ providers: [{ provide: DocumentDesignApi, useValue: api }] });
    facade = TestBed.inject(DocumentDesignFacade);
  });

  it('shows the picture of the design asked for last, whatever order the answers come in', async () => {
    const first = facade.preview('c1', {
      layout: 'classic',
      accent: '#1f2328',
      logoWidth: null,
      logoHeight: null,
      logoKeepsProportions: true,
    });
    const second = facade.preview('c1', {
      layout: 'modern',
      accent: '#1f6feb',
      logoWidth: null,
      logoHeight: null,
      logoKeepsProportions: true,
    });
    expect(facade.state()).toBe('loading');

    answers[1]!.settle('data:modern');
    await second;
    answers[0]!.settle('data:classic');
    await first;

    expect([facade.picture(), facade.state()]).toEqual(['data:modern', 'ready']);
  });

  it('says why there is no picture, and keeps none from an earlier design', async () => {
    const shown = facade.preview('c1', {
      layout: 'classic',
      accent: '#1f2328',
      logoWidth: null,
      logoHeight: null,
      logoKeepsProportions: true,
    });
    answers[0]!.settle('data:classic');
    await shown;

    const refused = facade.preview('c1', {
      layout: 'modern',
      accent: '#1f6feb',
      logoWidth: null,
      logoHeight: null,
      logoKeepsProportions: true,
    });
    expect(facade.picture()).toBe('data:classic');
    answers[1]!.refuse(new PreviewRefused('nothing'));
    await refused;

    expect([facade.picture(), facade.state()]).toEqual([null, 'nothing']);
  });

  it('says why there is nothing to ask for, and an answer still on its way does not undo it', async () => {
    const late = facade.preview('c1', {
      layout: 'classic',
      accent: '#1f2328',
      logoWidth: null,
      logoHeight: null,
      logoKeepsProportions: true,
    });
    facade.without('failed');
    answers[0]!.settle('data:classic');
    await late;

    expect([facade.picture(), facade.state()]).toEqual([null, 'failed']);
  });

  it("keeps the logo's proportions once read, and none when the company has no logo", async () => {
    await facade.loadLogoRatio('c1');
    expect(api.logoRatio).toHaveBeenCalledWith('c1');
    expect(facade.logoRatio()).toBe(3);

    api.logoRatio.mockResolvedValueOnce(null as unknown as number);
    await facade.loadLogoRatio('c1');
    expect(facade.logoRatio()).toBeNull();
  });
});
