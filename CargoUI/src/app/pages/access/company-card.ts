import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import { CompanyService } from '../../services/identity/company.service';
import { IdentityService } from '../../services/identity/identity.service';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';

/**
 * The company's own identity — its mark, and who it is.
 *
 * It lives on Access Control rather than on a settings page of its own because
 * that is already the administrator's screen, and a page holding one card would
 * be a menu item somebody has to find. `company.manage` is the permission, held
 * by the administrator and the general manager; `access.view` is what opens the
 * page around it, and both of those roles hold that too.
 *
 * **Only the logo is editable here.** The name is not: it is what every person
 * in the company sees in the sidebar and what the code was derived from, so
 * changing it is a support conversation rather than a text field somebody can
 * knock while looking for something else.
 */
@Component({
  selector: 'app-company-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon],
  template: `
    <app-card heading="Company" icon="profile" hint="Shown in the sidebar">
      <div class="flex flex-wrap items-center gap-4">
        <!--
          The mark at 64px, which is the size it is stored at.

          Larger than the sidebar draws it on purpose: this is the one screen
          where somebody is judging the image itself, and judging it at 32px
          means uploading three times to find out it does not read small.
        -->
        @if (logo(); as url) {
          <img
            [src]="url"
            alt=""
            width="64"
            height="64"
            class="h-16 w-16 flex-none rounded-control object-contain ring-1 ring-cr-line"
          />
        } @else {
          <span
            class="flex h-16 w-16 flex-none items-center justify-center rounded-control bg-cr-tint text-[18px] font-semibold text-cr-blue ring-1 ring-cr-line"
          >
            {{ initials() }}
          </span>
        }

        <div class="min-w-0 flex-1">
          <p class="truncate text-[15px] font-semibold">{{ name() }}</p>
          <p class="cr-meta mt-0.5">
            @if (logo()) {
              Square images work best. Anything else is cropped from the middle.
            } @else {
              No logo yet — the sidebar shows the initials.
            }
          </p>

          @if (failure(); as message) {
            <p role="alert" class="mt-2 text-[12px] font-medium text-cr-red">{{ message }}</p>
          }
        </div>

        <div class="flex flex-none items-center gap-2">
          <!--
            The real input, kept out of the layout but still rendered — hence
            sr-only rather than hidden. The click is forwarded to this element,
            and a browser will not open a picker for one that is not laid out.
          -->
          <input
            #picker
            type="file"
            accept="image/png,image/jpeg,image/gif,image/webp"
            class="sr-only"
            (change)="chosen($event)"
          />

          <button
            type="button"
            class="flex h-10 items-center gap-1.5 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="busy()"
            (click)="picker.click()"
          >
            <app-icon name="plus" [size]="16" />
            {{ busy() ? 'Uploading…' : logo() ? 'Replace logo' : 'Upload logo' }}
          </button>

          @if (logo()) {
            <button
              type="button"
              class="h-10 rounded-control border border-cr-line px-4 text-[14px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-60"
              [disabled]="busy()"
              (click)="remove()"
            >
              Remove
            </button>
          }
        </div>
      </div>
    </app-card>
  `,
})
export class CompanyCard {
  private readonly companyApi = inject(CompanyService);
  private readonly identity = inject(IdentityService);

  private readonly picker = viewChild.required<ElementRef<HTMLInputElement>>('picker');

  /**
   * Read straight off `me` rather than fetched.
   *
   * The sidebar already draws from there, so a second source would be a second
   * thing to keep in step — and the two disagreeing is exactly the bug where
   * the card shows the new logo and the sidebar keeps the old one.
   */
  protected readonly name = this.identity.company;
  protected readonly logo = this.identity.companyLogo;
  protected readonly initials = this.identity.companyInitials;

  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected chosen(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    // Clearing the input is what makes picking the *same* file twice work.
    // Without it the second choice fires no `change` event, and a person who
    // re-cropped the image and picked it again sees nothing happen.
    input.value = '';

    if (!file) return;

    this.busy.set(true);
    this.failure.set(null);

    this.companyApi.uploadLogo(file).subscribe({
      next: () => this.busy.set(false),
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  protected remove(): void {
    this.busy.set(true);
    this.failure.set(null);

    this.companyApi.removeLogo().subscribe({
      next: () => this.busy.set(false),
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  /**
   * The server's own words for a 422.
   *
   * It knows things this screen does not — which formats it can decode, how
   * large a file may be, and whether the bytes really were an image once it
   * tried. Restating any of that here would be two rules to keep in step.
   */
  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      return (
        (error.error?.errors as Record<string, string[]> | undefined)?.['logo']?.[0] ??
        error.error?.message ??
        'That image was not accepted.'
      );
    }

    if (error.status === 0) return 'Cannot reach the server.';
    if (error.status === 403) return 'This account cannot change the company logo.';
    if (error.status === 413) return 'That file is too large for the server to accept.';

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }
}
