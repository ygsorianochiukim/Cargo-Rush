import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { InvoiceDocument } from '../models/billing/invoice-document.model';
import { StatusValue } from '../models/shared/status.model';
import { fmt } from './format';
import { StatusPill } from './status-pill';

@Component({
  selector: 'app-invoice-sheet',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [StatusPill],
  styles: `
    @media print {
      .cr-sheet {
        box-shadow: none !important;
        border: 0 !important;
        outline: 0 !important;
        max-width: none !important;
        padding: 0 !important;
        --tw-ring-shadow: 0 0 #0000 !important;
        display: flex !important;
        flex-direction: column;
        min-height: 100%;
        min-height: calc(100vh - 28mm);
      }

      .cr-totals,
      .cr-payments,
      .cr-blocks,
      .cr-signature {
        break-inside: avoid;
      }

      @page {
        margin: 14mm;
      }
    }
  `,
  template: `
    @let d = doc();
    @let t = tpl();
    @let l = t.labels;
    @let show = t.show;

    <article
      class="cr-sheet mx-auto flex min-h-[1000px] max-w-[820px] flex-col rounded-card bg-cr-surface p-8 shadow-card ring-1 ring-cr-line/60"
    >
      <header
        class="flex flex-wrap items-start justify-between gap-6 border-b border-cr-ink/10 pb-6"
      >
        <div class="flex items-start gap-4">
          @if (show.logo) {
            @if (d.issuer.logo_url) {
              <img
                [src]="d.issuer.logo_url"
                [alt]="(d.issuer.name ?? 'Company') + ' logo'"
                class="h-14 w-14 rounded-control object-contain"
              />
            } @else {
              <span
                class="flex h-14 w-14 items-center justify-center rounded-control bg-cr-tint text-[18px] font-bold"
                [style.color]="t.accent"
              >
                {{ initials() }}
              </span>
            }
          }

          <div class="min-w-0">
            <h1 class="text-[20px] leading-tight font-bold">{{ d.issuer.name }}</h1>
            @if (show.address && d.issuer.address) {
              <p class="mt-0.5 text-[13px] text-cr-ink-muted">{{ d.issuer.address }}</p>
            }
            @if (show.contact) {
              <p class="mt-0.5 text-[13px] text-cr-ink-muted">
                @if (d.issuer.contact_phone) {
                  <span>{{ d.issuer.contact_phone }}</span>
                }
                @if (d.issuer.contact_email) {
                  <span> · {{ d.issuer.contact_email }}</span>
                }
              </p>
            }
            @if (show.tin && d.issuer.tin) {
              <p class="cr-num mt-1 text-[12px] text-cr-ink-muted">
                TIN {{ d.issuer.tin }}
                @if (d.issuer.vat_registered) {
                  <span> · VAT registered</span>
                }
              </p>
            }
          </div>
        </div>

        <div class="text-right">
          <p class="text-[11px] font-bold tracking-[0.14em] uppercase" [style.color]="t.accent">
            {{ title() }}
          </p>
          <p class="cr-num mt-1 text-[22px] leading-none font-bold">{{ d.document.number }}</p>
          @if (show.status) {
            <div class="mt-2 flex justify-end">
              <app-status-pill [status]="status()" />
            </div>
          }
          @if (t.fields.length > 0) {
            <dl class="mt-3 grid grid-cols-[auto_auto] justify-end gap-x-3 gap-y-0.5 text-[12px]">
              @for (field of t.fields; track $index) {
                <dt class="text-cr-ink-muted">{{ field.label }}</dt>
                <dd class="cr-num text-right">{{ field.value }}</dd>
              }
            </dl>
          }
        </div>
      </header>

      <section class="grid grid-cols-1 gap-6 border-b border-cr-ink/10 py-6 sm:grid-cols-2">
        <div>
          <h2 class="cr-meta">{{ l.billed_to }}</h2>
          <p class="mt-1 text-[15px] font-semibold">{{ d.bill_to.name }}</p>
          @if (d.bill_to.address) {
            <p class="text-[13px] text-cr-ink-muted">{{ d.bill_to.address }}</p>
          }
          @if (d.bill_to.contact) {
            <p class="text-[13px] text-cr-ink-muted">{{ d.bill_to.contact }}</p>
          }
          @if (d.bill_to.tin) {
            <p class="cr-num mt-1 text-[12px] text-cr-ink-muted">TIN {{ d.bill_to.tin }}</p>
          }
          @if (d.bill_to.vat_treatment_label) {
            <p class="text-[12px] text-cr-ink-muted">{{ d.bill_to.vat_treatment_label }}</p>
          }
        </div>

        <dl class="grid grid-cols-2 gap-y-1.5 text-[13px] sm:justify-self-end">
          <dt class="cr-meta">{{ l.issued }}</dt>
          <dd class="cr-num text-right">{{ fmt.date(d.document.issued_at) }}</dd>

          <dt class="cr-meta">{{ l.due }}</dt>
          <dd class="cr-num text-right" [class.text-cr-red]="d.document.overdue">
            {{ fmt.date(d.document.due_at) }}
          </dd>

          @if (show.terms && d.document.terms_days !== null) {
            <dt class="cr-meta">{{ l.terms }}</dt>
            <dd class="cr-num text-right">{{ d.document.terms_days }} days</dd>
          }

          @if (show.currency) {
            <dt class="cr-meta">{{ l.currency }}</dt>
            <dd class="cr-num text-right">{{ d.document.currency }}</dd>
          }
        </dl>
      </section>

      <section class="py-6">
        <h2 class="cr-meta mb-2">{{ l.lines_heading }}</h2>

        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-left text-[13px]">
            <thead>
              <tr class="border-b-2" [style.border-color]="t.accent">
                <th class="cr-th py-2 pr-3">{{ l.col_description }}</th>
                @if (show.reference) {
                  <th class="cr-th py-2 pr-3">{{ l.col_reference }}</th>
                }
                @if (show.delivered) {
                  <th class="cr-th py-2 pr-3">{{ l.col_delivered }}</th>
                }
                @if (show.weight) {
                  <th class="cr-th py-2 pr-3 text-right">{{ l.col_weight }}</th>
                }
                <th class="cr-th py-2 text-right">{{ l.col_amount }}</th>
              </tr>
            </thead>
            <tbody>
              @for (line of d.lines; track $index) {
                <tr class="border-b border-cr-ink/5 align-top">
                  <td class="py-3 pr-3">
                    <span class="block font-medium">{{ line.description }}</span>
                    @if (line.cargo) {
                      <span class="block text-[12px] text-cr-ink-muted">{{ line.cargo }}</span>
                    }
                    @if (show.unit_driver && (line.plate || line.driver)) {
                      <span class="block text-[12px] text-cr-ink-muted">
                        @if (line.plate) {
                          <span>Unit {{ line.plate }}</span>
                        }
                        @if (line.plate && line.driver) {
                          <span> · </span>
                        }
                        @if (line.driver) {
                          <span>{{ line.driver }}</span>
                        }
                      </span>
                    }
                  </td>
                  @if (show.reference) {
                    <td class="cr-num py-3 pr-3">{{ line.reference ?? '—' }}</td>
                  }
                  @if (show.delivered) {
                    <td class="cr-num py-3 pr-3">{{ fmt.date(line.delivered_at) }}</td>
                  }
                  @if (show.weight) {
                    <td class="cr-num py-3 pr-3 text-right">
                      {{ line.weight_kg === null ? '—' : fmt.kg(line.weight_kg) }}
                    </td>
                  }
                  <td class="cr-num py-3 text-right font-semibold">
                    {{ fmt.money(line.amount_cents, d.document.currency) }}
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </section>

      <section class="cr-totals flex flex-col gap-6 border-t border-cr-ink/10 pt-6 sm:flex-row">
        <div class="min-w-0 flex-1">
          @if (show.amount_in_words) {
            <h2 class="cr-meta">{{ l.amount_in_words }}</h2>
            <p class="mt-1 text-[14px] font-medium">{{ d.totals.amount_in_words }}</p>
          }

          @if (d.totals.withholding_cents > 0) {
            <p class="mt-3 max-w-sm text-[12px] text-cr-ink-muted">
              {{ withholdingRate() }}% creditable withholding tax has been deducted. The balance is
              remitted to the BIR by the payer, and Form 2307 is expected with the payment.
            </p>
          }
        </div>

        <dl class="w-full text-[13px] sm:max-w-[300px]">
          <div class="flex justify-between py-1">
            <dt class="text-cr-ink-muted">{{ l.net_of_vat }}</dt>
            <dd class="cr-num">{{ fmt.money(d.totals.net_amount_cents, d.document.currency) }}</dd>
          </div>

          <div class="flex justify-between py-1">
            <dt class="text-cr-ink-muted">
              {{ l.vat }}
              @if (d.totals.vat_rate_bp) {
                <span>({{ vatRate() }}%)</span>
              }
            </dt>
            <dd class="cr-num">{{ fmt.money(d.totals.vat_cents, d.document.currency) }}</dd>
          </div>

          <div class="flex justify-between border-t border-cr-ink/10 py-2">
            <dt class="font-semibold">{{ l.invoice_total }}</dt>
            <dd class="cr-num font-semibold">
              {{ fmt.money(d.totals.amount_cents, d.document.currency) }}
            </dd>
          </div>

          @if (d.totals.withholding_cents > 0) {
            <div class="flex justify-between py-1">
              <dt class="text-cr-ink-muted">Less withholding ({{ withholdingRate() }}%)</dt>
              <dd class="cr-num">
                −{{ fmt.money(d.totals.withholding_cents, d.document.currency) }}
              </dd>
            </div>
          }

          <div class="flex justify-between border-t border-cr-ink/10 py-2">
            <dt class="font-semibold">{{ l.amount_payable }}</dt>
            <dd class="cr-num text-[15px] font-bold" [style.color]="t.accent">
              {{ fmt.money(d.totals.due_cents, d.document.currency) }}
            </dd>
          </div>

          @if (d.totals.paid_cents > 0) {
            <div class="flex justify-between py-1">
              <dt class="text-cr-ink-muted">{{ l.received }}</dt>
              <dd class="cr-num text-cr-success">
                −{{ fmt.money(d.totals.paid_cents, d.document.currency) }}
              </dd>
            </div>

            <div class="flex justify-between border-t border-cr-ink/10 py-2">
              <dt class="font-semibold">{{ l.balance_due }}</dt>
              <dd
                class="cr-num text-[15px] font-bold"
                [class.text-cr-success]="d.totals.balance_cents === 0"
              >
                {{ fmt.money(d.totals.balance_cents, d.document.currency) }}
              </dd>
            </div>
          }
        </dl>
      </section>

      @if (show.payments && d.payments.length > 0) {
        <section class="cr-payments mt-6 border-t border-cr-ink/10 pt-6">
          <h2 class="cr-meta mb-2">{{ l.payments_heading }}</h2>

          <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left text-[13px]">
              <thead>
                <tr class="border-b border-cr-ink/10">
                  <th class="cr-th py-2 pr-3">Date</th>
                  <th class="cr-th py-2 pr-3">Method</th>
                  <th class="cr-th py-2 pr-3">Reference</th>
                  <th class="cr-th py-2 pr-3">Recorded by</th>
                  <th class="cr-th py-2 text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                @for (payment of d.payments; track $index) {
                  <tr class="border-b border-cr-ink/5">
                    <td class="cr-num py-2.5 pr-3">{{ fmt.date(payment.paid_on) }}</td>
                    <td class="py-2.5 pr-3">{{ payment.method_label ?? '—' }}</td>
                    <td class="cr-num py-2.5 pr-3">{{ payment.reference ?? '—' }}</td>
                    <td class="py-2.5 pr-3 text-cr-ink-muted">{{ payment.recorded_by ?? '—' }}</td>
                    <td class="cr-num py-2.5 text-right font-semibold">
                      {{ fmt.money(payment.amount_cents, d.document.currency) }}
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </section>
      }

      @if (t.blocks.length > 0) {
        <section class="cr-blocks mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
          @for (block of t.blocks; track $index) {
            <div class="border-l-2 pl-3" [style.border-color]="t.accent">
              @if (block.title) {
                <p class="text-[13px] font-semibold">{{ block.title }}</p>
              }
              <p class="text-[12px] whitespace-pre-line text-cr-ink-muted">{{ block.body }}</p>
            </div>
          }
        </section>
      }

      @if (show.signatures) {
        <footer
          class="cr-signature mt-auto grid grid-cols-1 gap-8 border-t border-cr-ink/10 pt-6 sm:grid-cols-2"
        >
          <div>
            <p class="cr-meta">{{ l.issued_by }}</p>
            <div class="mt-8 border-t border-cr-ink/30 pt-1">
              <p class="text-[12px] text-cr-ink-muted">
                {{ d.issuer.contact_name ?? d.issuer.name }}
              </p>
            </div>
          </div>
          <div>
            <p class="cr-meta">{{ l.received_by }}</p>
            <div class="mt-8 border-t border-cr-ink/30 pt-1">
              <p class="text-[12px] text-cr-ink-muted">{{ l.received_by_hint }}</p>
            </div>
          </div>
        </footer>
      }

      @if (show.footer) {
        <p class="mt-6 shrink-0 text-center text-[11px] text-cr-ink-muted">
          @if (t.footer) {
            {{ t.footer }}
          } @else {
            {{ d.document.number }} · {{ d.issuer.name }}
            @if (d.issuer.tin) {
              <span> · TIN {{ d.issuer.tin }}</span>
            }
            · This document was generated by Cargo Rush.
          }
        </p>
      }
    </article>
  `,
})
export class InvoiceSheet {
  readonly doc = input.required<InvoiceDocument>();

  protected readonly fmt = fmt;

  protected readonly tpl = computed(() => this.doc().template);

  protected readonly title = computed(
    () => this.tpl().titles[this.doc().document.title_key] ?? this.doc().document.title,
  );

  protected readonly status = computed<StatusValue>(
    () => (this.doc().document.status ?? 'pending') as StatusValue,
  );

  protected readonly vatRate = computed(() => (this.doc().totals.vat_rate_bp ?? 0) / 100);

  protected readonly withholdingRate = computed(
    () => (this.doc().totals.withholding_rate_bp ?? 0) / 100,
  );

  protected readonly initials = computed(() =>
    (this.doc().issuer.name ?? '')
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word[0]?.toUpperCase() ?? '')
      .join(''),
  );
}
