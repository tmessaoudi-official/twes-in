// SPDX-License-Identifier: AGPL-3.0-or-later
import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { NewVersionBanner } from './shared/build/new-version-banner';

/**
 * The root is the router: signed-in pages sit inside the shell (shell/app-shell), the pages before sign-in in their own
 * layout. Above both, the banner that says a new build is out.
 */
@Component({
  selector: 'app-root',
  imports: [NewVersionBanner, RouterOutlet],
  template: '<app-new-version-banner /><router-outlet />',
})
export class App {}
