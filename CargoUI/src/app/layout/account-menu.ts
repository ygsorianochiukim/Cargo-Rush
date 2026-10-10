import {
  ChangeDetectionStrategy,
  Component,
  HostListener,
  inject,
  input,
  signal,
} from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';

import { IdentityService } from '../services/identity/identity.service';
import { Field } from '../shared/field';
import { Icon } from '../shared/icon';
import { Modal } from '../shared/modal';

@Component({
  selector: 'app-account-menu',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Icon, Modal, ReactiveFormsModule],
  template: `
    <div class="relative">
      @if (open()) {
        <div class="fixed inset-0 z-40" (click)="open.set(false)" aria-hidden="true"></div>

        <div
          role="menu"
          class="absolute bottom-full left-0 z-50 mb-2 w-[248px] overflow-hidden rounded-panel bg-cr-surface shadow-panel ring-1 ring-cr-line motion-safe:animate-[cr-rise_.15s_ease-out]"
        >
          <div class="border-b border-cr-line px-4 py-3">
            <p class="truncate text-[14px] font-semibold">{{ me()?.name }}</p>
            <p class="mt-0.5 truncate text-[12px] text-cr-ink-muted">{{ me()?.email }}</p>
            <p class="mt-1.5 truncate text-[11px] text-cr-ink-muted">
              {{ me()?.role_label }}
              @if (company(); as name) {
                <span> · {{ name }}</span>
              }
            </p>
          </div>

          <button
            type="button"
            role="menuitem"
            class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-[13px] font-medium transition-colors hover:bg-cr-tint"
            (click)="openPassword()"
          >
            <app-icon name="shield" [size]="16" class="text-cr-ink-muted" />
            Change password
          </button>

          <div class="border-t border-cr-line"></div>

          <button
            type="button"
            role="menuitem"
            class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-[13px] font-semibold text-cr-red transition-colors hover:bg-cr-red-bg disabled:opacity-60"
            [disabled]="signingOut()"
            (click)="signOut()"
          >
            <app-icon name="close" [size]="16" />
            {{ signingOut() ? 'Signing out…' : 'Sign out' }}
          </button>
        </div>
      }

      @if (me(); as user) {
        <button
          type="button"
          class="flex w-full items-center rounded-control p-1 text-left transition-colors hover:bg-cr-tint"
          [class.justify-center]="collapsed()"
          [class.bg-cr-tint]="open()"
          [attr.title]="collapsed() ? user.name + ' — ' + user.role_label : null"
          [attr.aria-label]="'Account menu for ' + user.name"
          [attr.aria-expanded]="open()"
          aria-haspopup="menu"
          (click)="open.set(!open())"
        >
          <span
            class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-cr-blue text-[11px] font-semibold text-cr-surface"
          >
            {{ initials() }}
          </span>
          @if (!collapsed()) {
            <span class="ml-2.5 min-w-0 flex-1">
              <span class="block truncate text-[12px] font-semibold">{{ user.name }}</span>
              <span class="cr-meta block">{{ user.role_label }}</span>
            </span>
            <app-icon
              name="chevron-right"
              [size]="16"
              class="text-cr-ink-muted transition-transform"
              [class.-rotate-90]="!open()"
              [class.rotate-90]="open()"
            />
          }
        </button>
      } @else {
        <div class="flex items-center gap-2.5" role="status" aria-label="Loading account">
          <div class="cr-skeleton h-7 w-7 flex-none rounded-full"></div>
          @if (!collapsed()) {
            <div class="flex-1">
              <div class="cr-skeleton h-3 w-24"></div>
              <div class="cr-skeleton mt-1.5 h-2 w-16"></div>
            </div>
          }
        </div>
      }
    </div>

    <app-modal
      [open]="passwordOpen()"
      (openChange)="passwordOpen.set($event)"
      title="Change password"
      subtitle="You will stay signed in on this browser."
      icon="shield"
      size="sm"
    >
      <form [formGroup]="form" class="flex flex-col gap-3">
        <app-field label="Current password" required [error]="errorFor('current_password')">
          <input type="password" formControlName="current_password" [class]="inputClass" />
        </app-field>

        <app-field
          label="New password"
          required
          hint="At least 8 characters, and not the one you have."
          [error]="errorFor('password')"
        >
          <input type="password" formControlName="password" [class]="inputClass" />
        </app-field>

        <app-field
          label="Confirm new password"
          required
          [error]="errorFor('password_confirmation')"
        >
          <input type="password" formControlName="password_confirmation" [class]="inputClass" />
        </app-field>

        @if (passwordError(); as message) {
          <p
            class="rounded-control bg-cr-red-bg px-3 py-2 text-[13px] font-medium text-cr-red"
            role="alert"
          >
            {{ message }}
          </p>
        }

        @if (changed()) {
          <p
            class="rounded-control bg-cr-success-bg px-3 py-2 text-[13px] font-medium text-cr-success"
          >
            Password changed.
          </p>
        }
      </form>

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint"
          (click)="passwordOpen.set(false)"
        >
          Close
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-50"
          [disabled]="saving()"
          (click)="changePassword()"
        >
          {{ saving() ? 'Saving…' : 'Change password' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class AccountMenu {
  private readonly identity = inject(IdentityService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly collapsed = input(false);

  protected readonly me = this.identity.me;
  protected readonly initials = this.identity.initials;
  protected readonly company = this.identity.company;

  protected readonly open = signal(false);
  protected readonly signingOut = signal(false);

  protected readonly passwordOpen = signal(false);
  protected readonly saving = signal(false);
  protected readonly changed = signal(false);
  protected readonly passwordError = signal<string | null>(null);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    current_password: ['', Validators.required],
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', Validators.required],
  });

  private readonly fieldErrors = signal<Record<string, string[]>>({});

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.open()) this.open.set(false);
  }

  protected openPassword(): void {
    this.open.set(false);
    this.form.reset({ current_password: '', password: '', password_confirmation: '' });
    this.fieldErrors.set({});
    this.passwordError.set(null);
    this.changed.set(false);
    this.passwordOpen.set(true);
  }

  protected errorFor(field: string): string | null {
    const fromApi = this.fieldErrors()[field]?.[0];
    if (fromApi) return fromApi;

    const control = this.form.get(field);
    if (!control || control.valid || !(control.touched || control.dirty)) return null;

    if (control.hasError('required')) return 'This field is required.';
    if (control.hasError('minlength')) return 'Use at least 8 characters.';

    return 'Check this value.';
  }

  protected changePassword(): void {
    if (this.saving()) return;

    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    const values = this.form.getRawValue();

    if (values.password !== values.password_confirmation) {
      this.passwordError.set('The two new passwords do not match.');

      return;
    }

    this.saving.set(true);
    this.passwordError.set(null);
    this.fieldErrors.set({});
    this.changed.set(false);

    this.identity.changePassword(values).subscribe({
      next: () => {
        this.saving.set(false);
        this.changed.set(true);
        this.form.reset({ current_password: '', password: '', password_confirmation: '' });
      },
      error: (failure: { error?: { message?: string; errors?: Record<string, string[]> } }) => {
        this.saving.set(false);
        this.fieldErrors.set(failure.error?.errors ?? {});
        this.passwordError.set(
          failure.error?.errors ? null : (failure.error?.message ?? 'That was not accepted.'),
        );
      },
    });
  }

  protected signOut(): void {
    if (this.signingOut()) return;

    this.signingOut.set(true);

    const leave = () => {
      this.signingOut.set(false);
      this.open.set(false);
      this.router.navigate(['/login']);
    };

    this.identity.logout().subscribe({ next: leave, error: leave });
  }
}
