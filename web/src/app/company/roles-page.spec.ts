// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { Session } from '../shared/session/session';
import type { RolesError } from './roles-api';
import { RolesFacade } from './roles-facade';
import { RolesPage } from './roles-page';
import type { PermissionGroupRow, RoleRow } from './roles-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      modules: { inventory: 'Stock' },
      permissions: {
        groups: { members: 'Équipe' },
        user: { read: 'Voir les membres', write: 'Inviter et retirer des membres' },
        stock: { read: 'Voir le stock' },
      },
      company: {
        roles: {
          title: 'Rôles',
          built_in: 'fourni',
          member_count: '{{count}} membre(s)',
          view: 'Consulter',
          edit: 'Modifier',
          new: 'Créer un rôle',
          everything: 'Le propriétaire a tous les droits',
          errors: { in_use: 'Des membres ont encore ce rôle.' },
        },
      },
    });
  }
}

const owner: RoleRow = {
  id: 'r-owner',
  name: 'owner',
  builtIn: true,
  wildcard: true,
  permissions: [],
  memberCount: 2,
};
const member: RoleRow = {
  id: 'r-member',
  name: 'member',
  builtIn: true,
  wildcard: false,
  permissions: ['user.read'],
  memberCount: 1,
};
const barista: RoleRow = {
  id: 'r-barista',
  name: 'barista',
  builtIn: false,
  wildcard: false,
  permissions: ['stock.read'],
  memberCount: 0,
};

const groups: readonly PermissionGroupRow[] = [
  {
    key: 'members',
    labelKey: 'permissions.groups.members',
    permissions: ['user.read', 'user.write'],
  },
  { key: 'inventory', labelKey: 'modules.inventory', permissions: ['stock.read'] },
];

describe('RolesPage', () => {
  const roles = signal<readonly RoleRow[]>([owner, member, barista]);
  const error = signal<RolesError | null>(null);
  const detail = signal('');
  const facade = {
    roles: roles.asReadonly(),
    groups: signal(groups).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    detail: detail.asReadonly(),
    load: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    remove: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<RolesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const tickOf = (permission: string): HTMLInputElement =>
    q(`permission-${permission}`)!.querySelector('input[type="checkbox"]') as HTMLInputElement;

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(RolesPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    roles.set([owner, member, barista]);
    error.set(null);
    detail.set('');
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.create.mockReset().mockResolvedValue(true);
    facade.revise.mockReset().mockResolvedValue(true);
    facade.remove.mockReset().mockResolvedValue(true);
    facade.clearError.mockReset();
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [RolesPage],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: RolesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        provideQuietFeedback(),
      ],
    });
  });

  it('lists every role, says which are shipped and how many hold each', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1');
    expect(q('role-owner')?.textContent).toContain('fourni');
    expect(q('role-owner')?.textContent).toContain('2 membre(s)');
    expect(q('role-barista')?.textContent).not.toContain('fourni');
    expect(q('role-barista')?.textContent).toContain('0 membre(s)');
  });

  it('offers editing and deleting for a custom role only', async () => {
    await open();

    expect(q('role-open-barista')?.textContent).toContain('Modifier');
    expect(q('role-delete-barista')).not.toBeNull();

    // A shipped role is readable, never editable: the release defines it and every company reads the same one.
    expect(q('role-open-member')?.textContent).toContain('Consulter');
    expect(q('role-delete-member')).toBeNull();
    expect(q('role-delete-owner')).toBeNull();
  });

  it('draws the matrix grouped, with what the role already holds ticked', async () => {
    await open();
    q('role-open-barista')!.click();
    await settle();

    expect(q('role-matrix')?.textContent).toContain('Équipe');
    expect(q('role-matrix')?.textContent).toContain('Stock');
    expect(q('role-matrix')?.textContent).toContain('Voir les membres');
    expect(tickOf('stock.read').checked).toBe(true);
    expect(tickOf('user.read').checked).toBe(false);
    expect(tickOf('user.read').disabled).toBe(false);
  });

  it('saves the whole set of ticks, so unticking is the same request as ticking', async () => {
    await open();
    q('role-open-barista')!.click();
    await settle();

    tickOf('user.read').click();
    tickOf('stock.read').click();
    await settle();
    q('role-save')!.click();
    await settle();

    expect(facade.revise).toHaveBeenCalledWith('c1', 'r-barista', 'barista', ['user.read']);
  });

  it('creates a role from an empty matrix', async () => {
    await open();
    q('role-new')!.click();
    await settle();

    const name = q('role-name') as HTMLInputElement;
    name.value = 'waiter';
    name.dispatchEvent(new Event('input'));
    tickOf('user.read').click();
    await settle();
    q('role-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith('c1', 'waiter', ['user.read']);
  });

  it('shows the owner as holding everything rather than every box ticked', async () => {
    // Ticking all of them would promise less than the wildcard does: it would not carry what a later release adds.
    await open();
    q('role-open-owner')!.click();
    await settle();

    expect(q('role-everything')?.textContent).toContain('tous les droits');
    expect(q('role-matrix')).toBeNull();
  });

  it('locks a shipped role: its boxes are readable and there is nothing to save', async () => {
    await open();
    q('role-open-member')!.click();
    await settle();

    expect(tickOf('user.read').checked).toBe(true);
    expect(tickOf('user.read').disabled).toBe(true);
    expect((q('role-name') as HTMLInputElement).disabled).toBe(true);
    expect(q('role-save')).toBeNull();
  });

  it('shows a refusal with what the API said, because it names people we do not know', async () => {
    await open();
    error.set('in_use');
    detail.set('The cashier role is held by sami@twes.local.');
    await settle();

    expect(q('roles-error')?.textContent).toContain('Des membres ont encore ce rôle.');
    expect(q('roles-error-detail')?.textContent).toContain('sami@twes.local');
  });

  it('offers nothing to change without the permission', async () => {
    auth.hasPermission.mockReturnValue(false);
    await open();

    expect(q('role-new')).toBeNull();
    expect(q('role-delete-barista')).toBeNull();
    expect(q('role-open-barista')?.textContent).toContain('Consulter');
  });
});
