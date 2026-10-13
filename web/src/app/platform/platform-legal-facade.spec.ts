// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { StepUp } from '../shared/step-up/step-up';
import { PlatformLegalApi } from './platform-legal-api';
import { PlatformLegalFacade } from './platform-legal-facade';

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

/** The platform's wall, holding a write back until the operator proves who is at the screen again. */
const proofWanted = new HttpErrorResponse({ status: 403, error: { error: 'step_up_required' } });

// docs/SPEC.md § 7, the step-up list: writing, validating and the identity are platform writes.
describe('PlatformLegalFacade', () => {
  const api = {
    overview: vi.fn(async () => []),
    identity: vi.fn(async () => ({ 'publisher.name': 'twes' })),
    setIdentity: vi.fn(),
    versions: vi.fn(async () => []),
    write: vi.fn(),
    validate: vi.fn(),
  };
  let facade: PlatformLegalFacade;

  beforeEach(() => {
    api.identity.mockClear();
    api.setIdentity.mockReset();
    api.write.mockReset();
    api.validate.mockReset();
    TestBed.configureTestingModule({
      providers: [
        { provide: PlatformLegalApi, useValue: api },
        { provide: StepUp, useValue: stepUp },
      ],
    });
    facade = TestBed.inject(PlatformLegalFacade);
  });

  it('asks the operator for the proof the platform wants, then publishes the version', async () => {
    stepUp.request.mockReset().mockResolvedValue(true);
    api.write.mockRejectedValueOnce(proofWanted).mockResolvedValueOnce(undefined);
    await facade.open('mentions', 'fr');

    expect(await facade.write('## Éditeur')).toBe(true);

    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_change');
    expect(api.write).toHaveBeenCalledTimes(2);
    expect(facade.failed()).toBe(false);
  });

  it('changes nothing when the operator does not give the proof', async () => {
    stepUp.request.mockReset().mockResolvedValue(false);
    api.setIdentity.mockRejectedValue(proofWanted);

    expect(await facade.saveIdentity({ 'publisher.name': 'Autre' })).toBe(false);

    expect(api.setIdentity).toHaveBeenCalledTimes(1);
    expect(api.identity).not.toHaveBeenCalled();
  });
});
