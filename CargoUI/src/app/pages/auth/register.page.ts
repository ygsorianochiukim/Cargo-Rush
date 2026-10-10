import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { AbstractControl, FormBuilder, ReactiveFormsModule, ValidationErrors, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { environment } from '../../../environments/environment';
import { GeoPoint } from '../../models/geo/geo.model';
import { IdentityService } from '../../services/identity/identity.service';
import { Field } from '../../shared/field';
import { MapPicker } from '../../shared/map-picker';
import { Wordmark } from '../../shared/wordmark';

@Component({
  selector: 'app-register',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Field, MapPicker, Wordmark],
  templateUrl: './register.page.html',
})
export class RegisterPage {
  private readonly identity = inject(IdentityService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  protected readonly submitting = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly pin = signal<GeoPoint | null>(null);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group(
    {
      company_name: ['', [Validators.required, Validators.maxLength(120)]],
      contact_phone: ['', Validators.maxLength(40)],
      address: ['', Validators.maxLength(200)],

      name: ['', [Validators.required, Validators.maxLength(120)]],
      email: ['', [Validators.required, Validators.email, Validators.maxLength(160)]],
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', Validators.required],
    },
    { validators: RegisterPage.passwordsMatch },
  );

  private static passwordsMatch(group: AbstractControl): ValidationErrors | null {
    const password = group.get('password')?.value;
    const confirmation = group.get('password_confirmation')?.value;

    if (!confirmation) return null;

    return password === confirmation ? null : { mismatch: true };
  }

  protected pinned(point: GeoPoint | null): void {
    this.pin.set(point);

    const address = this.form.controls.address;

    if (point?.place && !address.value.trim()) {
      address.setValue(point.place);
    }
  }

  protected errorFor(name: string): string | null {
    const control = this.form.get(name);
    if (!control || !(control.touched || control.dirty)) return null;

    if (name === 'password_confirmation' && this.form.hasError('mismatch') && control.value) {
      return 'The two passwords do not match.';
    }

    if (control.valid) return null;
    if (control.hasError('required')) return 'This field is required.';
    if (control.hasError('email')) return 'Enter a valid email address.';
    if (control.hasError('minlength')) return 'Use at least 8 characters.';
    if (control.hasError('maxlength')) return 'That is too long.';

    return 'Check this value.';
  }

  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;
      const first = errors && Object.values(errors)[0]?.[0];

      return first ?? error.error?.message ?? 'Some of those details were not accepted.';
    }

    if (error.status === 0) {
      return 'Cannot reach the server. Check that CargoApi is running on ' + environment.apiUrl + '.';
    }

    if (error.status === 419) {
      return 'The session token was rejected. Reload the page and try again.';
    }

    if (error.status === 429) {
      return 'Too many registration attempts from this connection. Wait a few minutes and try again.';
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

    const { contact_phone, address, ...required } = this.form.getRawValue();
    const pin = this.pin();

    this.identity
      .register({
        ...required,
        ...(contact_phone ? { contact_phone } : {}),
        ...(address ? { address } : {}),
        ...(pin ? { latitude: pin.lat, longitude: pin.lng } : {}),
      })
      .subscribe({
        next: () => {
          this.identity.loadNavigation().subscribe();
          this.router.navigateByUrl('/dashboard');
        },
        error: (error: HttpErrorResponse) => {
          this.submitting.set(false);
          this.failure.set(this.messageFor(error));
        },
      });
  }
}
