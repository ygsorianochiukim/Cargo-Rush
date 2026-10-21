import { ChangeDetectionStrategy, Component } from '@angular/core';

import { RatesCard } from '../access/rates-card';
import { YardCard } from '../access/yard-card';

@Component({
  selector: 'app-zoning',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RatesCard, YardCard],
  template: `
    <div class="grid items-start gap-4 xl:grid-cols-2">
      <app-yard-card />
      <app-rates-card />
    </div>
  `,
})
export class ZoningPage {}
