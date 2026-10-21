import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';

import { Invoice } from '../../models/billing/billing.model';
import { InvoiceDocument } from '../../models/billing/invoice-document.model';
import { invoiceSpec } from '../../services/billing/billing.form';
import { BillingService } from '../../services/billing/billing.service';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { PaymentDialog } from '../../shared/payment-dialog';
import { RecordDialog } from '../../shared/record-dialog';
import { saveFile } from '../../shared/save-file';
import { ErrorState } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';
import { StatusValue } from '../../models/shared/status.model';

@Component({
  selector: 'app-invoice',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, ErrorState, StatusPill],
  templateUrl: './invoice.page.html',
})
export class InvoicePage {
  private readonly billing = inject(BillingService);
  private readonly router = inject(Router);
  private readonly dialog = inject(RecordDialog);
  private readonly payments = inject(PaymentDialog);

  private readonly spec = invoiceSpec();

  private readonly route = inject(ActivatedRoute);

  private readonly invoiceId = this.route.snapshot.paramMap.get('invoice') ?? '';

  protected readonly fmt = fmt;

  protected readonly document = signal<InvoiceDocument | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly pdfError = signal<string | null>(null);

  constructor() {
    queueMicrotask(() => this.load());

    this.dialog
      .savedFor(this.spec)
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.load());

    this.payments.recorded.pipe(takeUntilDestroyed()).subscribe(() => this.load());
  }

  protected edit(): void {
    this.billing.find(this.invoiceId).subscribe({
      next: (invoice: Invoice) => this.dialog.edit(this.spec, invoice),
      error: () => this.error.set('Could not open that invoice for editing.'),
    });
  }

  protected recordPayment(): void {
    this.billing.find(this.invoiceId).subscribe({
      next: (invoice: Invoice) => this.payments.forInvoice(invoice),
      error: () => this.error.set('Could not open that invoice.'),
    });
  }

  protected load(): void {
    const id = this.invoiceId;

    if (id === '') {
      this.error.set('No invoice was named.');

      return;
    }

    this.billing.document(id).subscribe({
      next: (document) => {
        this.document.set(document);
        this.error.set(null);
      },
      error: () => {
        this.document.set(null);
        this.error.set('Could not load that invoice. It may have been deleted.');
      },
    });
  }

  protected readonly status = computed<StatusValue>(
    () => (this.document()?.document.status ?? 'pending') as StatusValue,
  );

  protected readonly vatRate = computed(() => (this.document()?.totals.vat_rate_bp ?? 0) / 100);

  protected readonly withholdingRate = computed(
    () => (this.document()?.totals.withholding_rate_bp ?? 0) / 100,
  );

  protected readonly initials = computed(() => {
    const name = this.document()?.issuer.name ?? '';

    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word[0]?.toUpperCase() ?? '')
      .join('');
  });

  protected print(): void {
    window.print();
  }

  protected savePdf(): void {
    this.saving.set(true);
    this.pdfError.set(null);

    this.billing.documentPdf(this.invoiceId).subscribe({
      next: (blob) => {
        saveFile(blob, `${this.document()?.document.number ?? 'invoice'}.pdf`);
        this.saving.set(false);
      },
      error: () => {
        this.saving.set(false);
        this.pdfError.set('Could not prepare the PDF. Check the connection and try again.');
      },
    });
  }

  protected back(): void {
    this.router.navigate(['/billing']);
  }
}
