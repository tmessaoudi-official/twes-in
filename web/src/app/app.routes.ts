// SPDX-License-Identifier: AGPL-3.0-or-later

import { Routes } from '@angular/router';
import { anonymousGuard, authGuard } from './auth/auth-guard';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [anonymousGuard],
    loadComponent: () => import('./auth/login-page').then((m) => m.LoginPage),
  },
  {
    path: '',
    pathMatch: 'full',
    canActivate: [authGuard],
    loadComponent: () => import('./hello/hello-page').then((m) => m.HelloPage),
  },
  {
    path: 'members',
    canActivate: [authGuard],
    loadComponent: () => import('./company/members-page').then((m) => m.MembersPage),
  },
  {
    // Opened from a mail client, with no session: deliberately outside both guards.
    path: 'invitations/:token',
    loadComponent: () =>
      import('./invitation/accept-invitation-page').then((m) => m.AcceptInvitationPage),
  },
  { path: '**', redirectTo: '' },
];
