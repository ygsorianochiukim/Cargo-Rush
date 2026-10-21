import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';

import { Invoice } from '../../models/billing/billing.model';
import { InvoiceDocument } from '../../models/billing/invoice-document.model';
import { invoiceSpec } from '../../services/billing/billing.form';
import { BillingService } from '../../services/billing/billing.service';
import { Icon } from '../../shared/icon';
import { InvoiceSheet } from '../../shared/invoice-sheet';
import { PaymentDialog } from '../../shared/payment-dialog';
import { RecordDialog } from '../../shared/record-dialog';
import { FileDelivery, deliverFile } from '../../shared/save-file';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-invoice',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, ErrorState, InvoiceSheet],
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

  protected print(): void {
    this.savePdf('print');
  }

  protected savePdf(mode: FileDelivery = 'save'): void {
    this.saving.set(true);
    this.pdfError.set(null);

    this.billing.documentPdf(this.invoiceId).subscribe({
      next: (blob) => {
        deliverFile(mode, blob, `${this.document()?.document.number ?? 'invoice'}.pdf`);
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
