import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import {
  AbstractControl,
  FormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  Validators,
} from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { IdentityService } from '../../services/identity/identity.service';
import { Field } from '../../shared/field';
import { Wordmark } from '../../shared/wordmark';

@Component({
  selector: 'app-reset-password',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Field, Wordmark],
  templateUrl: './reset-password.page.html',
})
export class ResetPasswordPage {
  private readonly identity = inject(IdentityService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly fb = inject(FormBuilder);

  private readonly token = this.route.snapshot.queryParamMap.get('token') ?? '';

  protected readonly email = this.route.snapshot.queryParamMap.get('email') ?? '';
  protected readonly linkBroken = this.token === '' || this.email === '';

  protected readonly submitting = signal(false);
  protected readonly failure = signal<string | null>(null);
  protected readonly done = signal(false);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group(
    {
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', Validators.required],
    },
    { validators: ResetPasswordPage.passwordsMatch },
  );

  private static passwordsMatch(group: AbstractControl): ValidationErrors | null {
    const password = group.get('password')?.value;
    const confirmation = group.get('password_confirmation')?.value;

    if (!confirmation) return null;

    return password === confirmation ? null : { mismatch: true };
  }

  protected errorFor(name: string): string | null {
    const control = this.form.get(name);
    if (!control || !(control.touched || control.dirty)) return null;

    if (name === 'password_confirmation' && this.form.hasError('mismatch') && control.value) {
      return 'The two passwords do not match.';
    }

    if (control.valid) return null;
    if (control.hasError('required')) return 'This field is required.';
    if (control.hasError('minlength')) return 'Use at least 8 characters.';

    return 'Check this value.';
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.submitting.set(true);
    this.failure.set(null);

    this.identity
      .resetPassword({ token: this.token, email: this.email, ...this.form.getRawValue() })
      .subscribe({
        next: () => {
          this.submitting.set(false);
          this.done.set(true);
        },
        error: (error: HttpErrorResponse) => {
          this.submitting.set(false);
          this.failure.set(this.messageFor(error));
        },
      });
  }

  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      return (
        errors?.['token']?.[0] ??
        errors?.['password']?.[0] ??
        errors?.['email']?.[0] ??
        error.error?.message ??
        'That did not work. Ask for a new link.'
      );
    }

    if (error.status === 429) return 'Too many attempts. Wait a minute and try again.';
    if (error.status === 0) return 'Cannot reach the server.';

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }

  protected toLogin(): void {
    this.router.navigateByUrl('/login');
  }
}
