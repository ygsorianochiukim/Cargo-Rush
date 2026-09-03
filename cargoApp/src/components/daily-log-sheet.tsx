import { useEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { LedgerEntryPayload, Truck } from '@/models/finance/finance.model';
import { financeService } from '@/services/finance/finance.service';
import { Icon } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { Brand, Radius, Spacing } from '@/constants/theme';

/**
 * The driver's end-of-run entry — the workbook's instruction to
 * "record the daily trip income and expenses in each truck", done from the cab.
 *
 * Total expenses and net income are derived live and never typed, using the
 * same two formulas the back office applies (DESIGN.md section 5.3).
 */

type Field = {
  key: keyof Omit<Amounts, never>;
  label: string;
};

interface Amounts {
  trip_income: string;
  fuel: string;
  driver_salary: string;
  helper_salary: string;
  maintenance: string;
  allowance: string;
}

const EXPENSE_FIELDS: Field[] = [
  { key: 'fuel', label: 'Fuel' },
  { key: 'driver_salary', label: 'Driver salary' },
  { key: 'helper_salary', label: 'Helper salary' },
  { key: 'maintenance', label: 'Maintenance' },
  { key: 'allowance', label: 'Allowance' },
];

const EMPTY: Amounts = {
  trip_income: '',
  fuel: '',
  driver_salary: '',
  helper_salary: '',
  maintenance: '',
  allowance: '',
};

const peso = (cents: number) =>
  `₱${(cents / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const toCents = (v: string) => Math.round((Number(v) || 0) * 100);

export function DailyLogSheet({
  open,
  onClose,
  vehicleId,
  plate,
  defaultRoute,
}: {
  open: boolean;
  onClose: () => void;
  /**
   * The unit this row belongs to. Matched on rather than the plate, because a
   * plate is a label that gets corrected and reformatted; this is the key the
   * ledger sheet actually hangs off.
   */
  vehicleId: string | null;
  /** Null when no unit is assigned — the sheet says so rather than guessing. */
  plate: string | null;
  defaultRoute?: string | null;
}) {
  const [amounts, setAmounts] = useState<Amounts>(EMPTY);
  const [route, setRoute] = useState(defaultRoute ?? '');
  const [remarks, setRemarks] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // The unit list and the route suggestions are the fleet's, not this
  // screen's — the same rows the back office keeps sheets for.
  const [trucks, setTrucks] = useState<Truck[]>([]);
  const [routes, setRoutes] = useState<string[]>([]);

  useEffect(() => {
    if (!open) return;

    financeService.trucks().then(setTrucks).catch(() => setTrucks([]));
    financeService.routes().then(setRoutes).catch(() => setRoutes([]));
  }, [open]);

  // The ledger is kept per truck, and a truck is the sheet for one vehicle.
  // Matched on that id: the workbook's own units 7 and 8 have no plate, so a
  // plate can never be the thing that identifies a unit.
  const truck =
    vehicleId === null ? null : (trucks.find((t) => t.vehicle_id === vehicleId) ?? null);

  const totals = useMemo(() => {
    const expenses =
      toCents(amounts.fuel) +
      toCents(amounts.driver_salary) +
      toCents(amounts.helper_salary) +
      toCents(amounts.maintenance) +
      toCents(amounts.allowance);
    const income = toCents(amounts.trip_income);
    return { income, expenses, net: income - expenses };
  }, [amounts]);

  const set = (key: keyof Amounts, value: string) =>
    setAmounts((prev) => ({ ...prev, [key]: value.replace(/[^0-9.]/g, '') }));

  const submit = () => {
    if (truck === null) {
      setError('This unit is not on the ledger yet — ask the office to add it.');

      return;
    }

    setSaving(true);
    setError(null);

    // No total and no net: both are derived by the API from the five
    // expense fields, and sending one would let it disagree with its parts.
    const payload: LedgerEntryPayload = {
      truck_id: truck.id,
      date: new Date().toISOString().slice(0, 10),
      trip_income_cents: toCents(amounts.trip_income),
      fuel_cents: toCents(amounts.fuel),
      driver_salary_cents: toCents(amounts.driver_salary),
      helper_salary_cents: toCents(amounts.helper_salary),
      maintenance_cents: toCents(amounts.maintenance),
      allowance_cents: toCents(amounts.allowance),
      route: route || null,
      remarks: remarks || null,
    };

    financeService
      .log(payload)
      .then(() => {
        setAmounts(EMPTY);
        setRemarks('');
        onClose();
      })
      .catch((e: Error) => setError(e.message))
      .finally(() => setSaving(false));
  };

  return (
    <Sheet
      open={open}
      onClose={onClose}
      title="Record today's trip"
      subtitle={`${plate ?? 'No unit assigned'} · ${new Date().toLocaleDateString([], { day: 'numeric', month: 'long' })}`}
      icon="clipboard"
      footer={
        <>
          {error ? (
            <Text style={styles.error} accessibilityLiveRegion="polite">
              {error}
            </Text>
          ) : null}
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Save entry"
            disabled={saving}
            onPress={submit}
            style={[styles.save, saving && { opacity: 0.6 }]}>
            <Text style={styles.saveText}>{saving ? 'Saving…' : 'Save entry'}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" onPress={onClose} style={styles.cancel}>
            <Text style={styles.cancelText}>Cancel</Text>
          </Pressable>
        </>
      }>
      <ScrollView style={styles.scroll} keyboardShouldPersistTaps="handled">
        <Text style={styles.label}>TRIP INCOME (₱)</Text>
        <TextInput
          value={amounts.trip_income}
          onChangeText={(v) => set('trip_income', v)}
          keyboardType="decimal-pad"
          placeholder="0"
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel="Trip income in pesos"
          style={[styles.input, styles.incomeInput]}
        />

        <Text style={[styles.label, { marginTop: Spacing.four }]}>EXPENSES (₱)</Text>
        <View style={styles.grid}>
          {EXPENSE_FIELDS.map((f) => (
            <View key={f.key} style={styles.cell}>
              <Text style={styles.cellLabel}>{f.label}</Text>
              <TextInput
                value={amounts[f.key]}
                onChangeText={(v) => set(f.key, v)}
                keyboardType="decimal-pad"
                placeholder="0"
                placeholderTextColor={Brand.inkMuted}
                accessibilityLabel={`${f.label} in pesos`}
                style={styles.input}
              />
            </View>
          ))}
        </View>

        <Text style={[styles.label, { marginTop: Spacing.four }]}>ROUTE</Text>
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerStyle={styles.chips}>
          {routes.map((r) => {
            const on = route === r;
            return (
              <Pressable
                key={r}
                onPress={() => setRoute(on ? '' : r)}
                accessibilityRole="button"
                accessibilityState={{ selected: on }}
                style={[styles.chip, on && { backgroundColor: Brand.blue }]}>
                <Text style={[styles.chipText, on && { color: Brand.surface }]}>{r}</Text>
              </Pressable>
            );
          })}
        </ScrollView>

        <Text style={[styles.label, { marginTop: Spacing.four }]}>REMARKS</Text>
        <TextInput
          value={remarks}
          onChangeText={setRemarks}
          placeholder="Optional note"
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel="Remarks"
          style={styles.input}
        />

        {/* Derived, never entered */}
        <View style={styles.derived}>
          <View style={styles.derivedRow}>
            <Text style={styles.derivedLabel}>Total expenses</Text>
            <Text style={styles.derivedValue}>{peso(totals.expenses)}</Text>
          </View>
          <View style={[styles.derivedRow, { marginTop: Spacing.two }]}>
            <Text style={styles.derivedLabel}>Net income</Text>
            <Text
              style={[
                styles.derivedValue,
                { color: totals.net < 0 ? Brand.red : totals.net > 0 ? Brand.success : Brand.ink },
              ]}>
              {peso(totals.net)}
            </Text>
          </View>
        </View>

        <View style={styles.hint}>
          <Icon name="check" size={14} color={Brand.blue} />
          <Text style={styles.hintText}>
            Dispatch sees this immediately in Daily Trip Monitoring.
          </Text>
        </View>
      </ScrollView>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  scroll: { maxHeight: 460 },
  label: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  input: {
    marginTop: 6,
    minHeight: 46,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    paddingHorizontal: Spacing.three,
    fontSize: 15,
    color: Brand.ink,
    backgroundColor: Brand.surface,
  },
  incomeInput: { fontSize: 20, fontWeight: '700' },

  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: Spacing.three, marginTop: 2 },
  cell: { width: '47%', flexGrow: 1, minWidth: 0 },
  cellLabel: { fontSize: 12, color: Brand.ink, marginTop: 6 },

  chips: { gap: Spacing.two, paddingVertical: 6 },
  chip: {
    minHeight: 36,
    justifyContent: 'center',
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.control,
    backgroundColor: Brand.tint,
  },
  chipText: { fontSize: 13, fontWeight: '500', color: Brand.ink },

  derived: {
    marginTop: Spacing.four,
    padding: Spacing.three,
    borderRadius: Radius.card,
    backgroundColor: Brand.tint,
  },
  derivedRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  derivedLabel: { fontSize: 13, color: Brand.inkMuted },
  derivedValue: { fontSize: 18, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },

  hint: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: Spacing.three },
  hintText: { flex: 1, fontSize: 12, color: Brand.inkMuted },

  save: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  saveText: { fontSize: 15, fontWeight: '600', color: Brand.surface },
  error: {
    marginBottom: Spacing.two,
    fontSize: 13,
    fontWeight: '500',
    color: Brand.red,
  },
  cancel: { minHeight: 48, alignItems: 'center', justifyContent: 'center', borderRadius: Radius.control },
  cancelText: { fontSize: 15, fontWeight: '600', color: Brand.ink },
});
