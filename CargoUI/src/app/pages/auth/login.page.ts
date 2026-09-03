import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';

import { environment } from '../../../environments/environment';
import { IdentityService } from '../../services/identity/identity.service';
import { Field } from '../../shared/field';
import { Wordmark } from '../../shared/wordmark';

/**
 * Sign in — the only route outside the layout.
 *
 * It sits outside because there is no sidebar to render yet: the nav and the
 * user chip both come from an authenticated call, and a shell full of
 * skeletons behind a login form would be pretending.
 */
@Component({
  selector: 'app-login',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, Field, Wordmark],
  templateUrl: './login.page.html',
})
export class LoginPage {
  private readonly identity = inject(IdentityService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly fb = inject(FormBuilder);

  protected readonly submitting = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
  });

  protected errorFor(name: string): string | null {
    const control = this.form.get(name);
    if (!control || control.valid || !(control.touched || control.dirty)) return null;
    if (control.hasError('required')) return 'This field is required.';
    if (control.hasError('email')) return 'Enter a valid email address.';

    return 'Check this value.';
  }

  /**
   * Say which thing went wrong.
   *
   * These are four different problems with four different fixes, and lumping
   * them into "could not reach the server" sends somebody to restart an API
   * that is already running. Status 0 is the only one that actually means
   * unreachable — the browser reports a blocked or failed request that way,
   * with no response to read.
   */
  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      return (
        error.error?.errors?.email?.[0] ??
        error.error?.message ??
        'These credentials do not match our records.'
      );
    }

    if (error.status === 0) {
      return 'Cannot reach the server. Check that CargoApi is running on ' + environment.apiUrl + '.';
    }

    if (error.status === 419) {
      return 'The session token was rejected. Reload the page and try again.';
    }

    if (error.status >= 500) {
      return 'The server hit an error handling that. Check the CargoApi log.';
    }

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.submitting.set(true);
    this.failure.set(null);

    // No `device_name`: this is the SPA, so the API sets a session cookie.
    this.identity.login(this.form.getRawValue()).subscribe({
      next: () => {
        this.identity.loadNavigation().subscribe();

        const next = this.route.snapshot.queryParamMap.get('next');
        this.router.navigateByUrl(next ?? '/dashboard');
      },
      error: (error: HttpErrorResponse) => {
        this.submitting.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }
}
