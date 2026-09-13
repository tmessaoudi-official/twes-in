// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { CustomFieldsApi, CustomFieldsRefused } from '../shared/custom-fields/custom-fields-api';
import type {
  CustomFieldDefinition,
  CustomFieldInput,
} from '../shared/custom-fields/custom-fields-types';
import { CustomFieldsFacade } from './custom-fields-facade';

const input: CustomFieldInput = {
  entity: 'customer',
  key: 'sector',
  label: 'Secteur',
  type: 'text',
  required: false,
  choices: [],
  sortOrder: 0,
  isActive: true,
};
const sector: CustomFieldDefinition = { ...input, id: 'f1' };

describe('CustomFieldsFacade', () => {
  const api = { list: vi.fn(), create: vi.fn(), revise: vi.fn() };
  let facade: CustomFieldsFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.list.mockResolvedValue([sector]);
    TestBed.configureTestingModule({ providers: [{ provide: CustomFieldsApi, useValue: api }] });
    facade = TestBed.inject(CustomFieldsFacade);
  });

  it("reads the company's fields for customers", async () => {
    await facade.load('c1');

    expect(api.list).toHaveBeenCalledWith('c1', 'customer');
    expect(facade.fields()).toEqual([sector]);
    expect(facade.error()).toBeNull();
  });

  it('reads the fields again after a change, and says why the API refused one', async () => {
    api.create.mockResolvedValueOnce(sector);
    expect(await facade.create('c1', input)).toBe(true);
    expect(api.list).toHaveBeenCalledTimes(1);

    api.revise.mockResolvedValueOnce(sector);
    expect(await facade.revise('c1', 'f1', input)).toBe(true);
    expect(api.revise).toHaveBeenCalledWith('c1', 'f1', input);

    api.create.mockRejectedValueOnce(new CustomFieldsRefused('key_taken'));
    expect(await facade.create('c1', input)).toBe(false);
    expect(facade.error()).toBe('key_taken');

    facade.clearError();
    expect(facade.error()).toBeNull();
  });
});
