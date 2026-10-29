// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { StepUp } from '../shared/step-up/step-up';
import { LegalIdentityRefused, PlatformLegalApi } from './platform-legal-api';
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
    identity: vi.fn(async () => ({
      values: { 'publisher.name': 'twes' },
      versions: { 'publisher.name': 2 },
    })),
    saveIdentity: vi.fn(),
    versions: vi.fn(async () => []),
    write: vi.fn(),
    validate: vi.fn(),
  };
  let facade: PlatformLegalFacade;

  beforeEach(() => {
    api.identity.mockClear();
    api.saveIdentity.mockReset();
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
    await facade.load();
    api.identity.mockClear();
    api.saveIdentity.mockRejectedValue(proofWanted);

    expect(await facade.saveIdentity({ 'publisher.name': 'Autre' })).toBe('failed');

    expect(api.saveIdentity).toHaveBeenCalledTimes(1);
    expect(api.identity).not.toHaveBeenCalled();
  });

  it('saves the facts changed with the versions read, and after one saved since shows what is saved', async () => {
    await facade.load();
    api.saveIdentity.mockResolvedValueOnce('saved_since');
    api.identity.mockResolvedValueOnce({
      values: { 'publisher.name': 'twes SARL' },
      versions: { 'publisher.name': 3 },
    });

    expect(await facade.saveIdentity({ 'publisher.name': 'Autre' })).toBe('saved_since');

    expect(api.saveIdentity).toHaveBeenCalledWith([
      { placeholder: 'publisher.name', value: 'Autre', version: 2 },
    ]);
    expect(facade.identity()).toEqual({ 'publisher.name': 'twes SARL' });

    api.saveIdentity.mockResolvedValueOnce('saved');
    expect(await facade.saveIdentity({ 'publisher.name': 'Autre' })).toBe('saved');
    expect(api.saveIdentity).toHaveBeenLastCalledWith([
      { placeholder: 'publisher.name', value: 'Autre', version: 3 },
    ]);
  });

  it('keeps nothing when the API refuses a field, saying which, until a save is kept', async () => {
    await facade.load();
    const refusal = { field: 'publisher.name', reason: 'invalid_text', params: { max: 200 } };
    api.saveIdentity.mockRejectedValueOnce(new LegalIdentityRefused([refusal]));

    expect(await facade.saveIdentity({ 'publisher.name': 'x'.repeat(201) })).toBe('refused');
    expect(facade.identityRefusals()).toEqual([refusal]);
    expect(facade.identity()).toEqual({ 'publisher.name': 'twes' });
    expect(facade.failed()).toBe(false);

    api.saveIdentity.mockResolvedValueOnce('saved');
    expect(await facade.saveIdentity({ 'publisher.name': 'Autre' })).toBe('saved');
    expect(facade.identityRefusals()).toEqual([]);
  });
});
