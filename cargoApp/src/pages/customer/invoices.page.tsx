import { StyleSheet, Text, View } from 'react-native';

import { PortalInvoice } from '@/models/portal/portal.model';
import { portalService } from '@/services/portal/portal.service';
import { Screen } from '@/components/screen';
import { Card, EmptyState, ErrorState, SkeletonRows, StatusPill } from '@/components/ui/primitives';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';

/**
 * The customer's invoices.
 *
 * Receivables only — a payable is money the business owes somebody else and
 * has no place here, which the API enforces rather than trusting a filter on
 * this screen.
 *
 * Two totals at the top, not one balance: "what you owe" and "what you have
 * paid" are different pieces of news, and a single net figure hides whichever
 * one the reader came for. It is the same split the office dashboard shows,
 * from the same two statuses.
 */
export function InvoicesPage() {
  const invoices = useApi(portalService.invoices);
  const summary = useApi(portalService.summary);

  const rows = invoices.data ?? [];

  return (
    <Screen title="Invoices" subtitle={summary.data?.customer.name}>
      <View style={styles.totals}>
        <Card padded={false} style={styles.totalCard}>
          <Text style={styles.totalLabel}>PENDING PAYMENT</Text>
          <Text style={styles.totalValue}>
            {summary.data
              ? fmt.money(summary.data.pending_payment_cents, summary.data.currency)
              : '—'}
          </Text>
        </Card>
        <Card padded={false} style={styles.totalCard}>
          <Text style={styles.totalLabel}>PAID</Text>
          <Text style={[styles.totalValue, { color: Brand.success }]}>
            {summary.data
              ? fmt.money(summary.data.successful_payment_cents, summary.data.currency)
              : '—'}
          </Text>
        </Card>
      </View>

      <Card heading="Payment history" icon="billing" padded={false}>
        {invoices.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={4} />
          </View>
        ) : invoices.error ? (
          <ErrorState message={invoices.error.message} onRetry={invoices.reload} />
        ) : rows.length === 0 ? (
          <EmptyState
            icon="billing"
            title="No invoices yet"
            body="An invoice is raised when a delivery is completed."
          />
        ) : (
          rows.map((invoice: PortalInvoice, index: number) => (
            <View
              key={invoice.id}
              style={[styles.row, index < rows.length - 1 && styles.divider]}>
              <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                <Text style={styles.number}>{invoice.number}</Text>
                <Text style={styles.sub} numberOfLines={1}>
                  {/* The haul it is for. Without it, reconciling an invoice
                      against a delivery is a customer matching dates by eye. */}
                  {invoice.trip_reference ?? 'No trip'} · issued{' '}
                  {fmt.date(invoice.issued_at)}
                </Text>
                <Text style={styles.sub}>
                  {invoice.paid_at
                    ? `Paid ${fmt.date(invoice.paid_at)}`
                    : `Due ${fmt.date(invoice.due_at)}`}
                </Text>
              </View>

              <View style={styles.right}>
                <Text style={styles.amount}>
                  {fmt.money(invoice.amount_cents, invoice.currency)}
                </Text>
                <StatusPill status={invoice.status} />
              </View>
            </View>
          ))
        )}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  totals: { flexDirection: 'row', gap: Spacing.three },
  totalCard: { flex: 1, minWidth: 0, padding: Spacing.three, gap: 6 },
  totalLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  totalValue: {
    fontSize: 20,
    fontWeight: '700',
    color: Brand.ink,
    fontVariant: ['tabular-nums'],
  },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three,
    borderRadius: Radius.control,
  },
  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  number: { fontSize: 14, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },
  sub: { fontSize: 12, color: Brand.inkMuted },
  right: { alignItems: 'flex-end', gap: 6 },
  amount: { fontSize: 15, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },
});
