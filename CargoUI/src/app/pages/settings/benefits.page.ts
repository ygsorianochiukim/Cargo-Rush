import { ChangeDetectionStrategy, Component } from '@angular/core';

import { BenefitsCard } from '../access/benefits-card';
import { PayrollCutoffCard } from '../access/payroll-cutoff-card';

@Component({
  selector: 'app-benefits',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BenefitsCard, PayrollCutoffCard],
  template: `
    <div class="grid items-start gap-4 xl:grid-cols-2">
      <app-benefits-card />
      <app-payroll-cutoff-card />
    </div>
  `,
})
export class BenefitsPage {}
