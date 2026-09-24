import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';

import { Payables } from '../../models/finance/payables.model';
import { FinanceService } from '../../services/finance/finance.service';
import { Card } from '../../shared/card';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { EmptyState, SkeletonRows } from '../../shared/states';

/**
 * Payables — everything the fleet owes, in one list.
 *
 * The money going out had grown into four places that never met: a partner's
 * wallet, a hired truck's monthly rent, a supplier's bill, and whatever else
 * somebody filed as spend. Each screen was right about its own corner, and
 * nobody could answer "what do we owe this week" without opening all four and
 * adding up by hand.
 *
 * ## It settles nothing
 *
 * Every line links to the screen that owns it. That is deliberate rather than
 * unfinished: settling a partner means picking which runs a payment covers,
 * settling a supplier bill means allocating against an invoice, and marking an
 * expense paid is a third thing again. One button over three flows would
 * either flatten distinctions that matter or grow into three forms on one
 * page.
 *
 * It also keeps the permissions honest. This is `finance.view` — a money
 * overview — so a manager can see what the week costs without holding
 * `truckers.manage` or `billing.manage`, which is what actually moves it.
 */
@Component({
  selector: 'app-payables',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, EmptyState, SkeletonRows, RouterLink],
  templateUrl: './payables.page.html',
})
export class PayablesPage {
  private readonly finance = inject(FinanceService);

  protected readonly fmt = fmt;

  /** Null while loading — the states depend on telling that apart from empty. */
  protected readonly payables = toSignal<Payables | null>(this.finance.payables(), {
    initialValue: null,
  });

  /**
   * Only the groups that have something in them.
   *
   * A fleet that hires no trucks should not be shown an empty "Rented trucks"
   * heading every week; the absence of the section is the answer.
   */
  protected readonly groups = computed(() =>
    (this.payables()?.groups ?? []).filter((group) => group.count > 0),
  );

  protected readonly owesNothing = computed(
    () => this.payables() !== null && this.groups().length === 0,
  );

  /**
   * To the centavo, like the period pages.
   *
   * Every figure on this screen is part of something somebody adds up — a
   * group's lines against its subtotal, the subtotals against the total at the
   * top — and the Quarterly Summary now prints the same outstanding figure on
   * a tile of its own. Rounded to whole pesos, a column of four lines could
   * miss its own total by two pesos and the two screens could disagree about
   * the same debt.
   */
  protected money = fmt.pesos;
  protected date = fmt.date;
}
