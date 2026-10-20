import React, { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import HospitalityReportNavigator, {
  HOSPITALITY_REPORT_KEYS,
} from "../../../components/HospitalityReportNavigator";
import { downloadShiftReportExcel, downloadShiftReportPdf, getShiftReport } from "../../../services/CashupReportsService";
import { getCurrentTradingDay } from "../../../services/MenuRecipeDsrService";
import { canViewCashupFinancialAmounts } from "../../../utils/CashupPermissions";

const n = (v) => Number(v || 0);
export const nullableNumber = (v) => (v === null || v === undefined || v === "" ? null : Number(v));
const money = (v, c = "") => `${c ? `${c} ` : ""}${n(v).toFixed(2)}`;
export const nullableMoney = (v, c = "") => (v === null || v === undefined ? "Pending" : money(v, c));
const dateText = (v) => (v ? String(v).replace("T", " ").slice(0, 19) : "-");
const rowsOf = (payload) => payload?.data?.rows || payload?.rows || payload?.data || [];
const toArray = (value) => {
  if (Array.isArray(value)) return value;
  if (typeof value === "string" && value.trim()) { try { const decoded = JSON.parse(value); return Array.isArray(decoded) ? decoded : []; } catch { return []; } }
  if (value && typeof value === "object") return Object.values(value).filter((v) => v && typeof v === "object");
  return [];
};
const toObject = (value) => {
  if (value && typeof value === "object" && !Array.isArray(value)) return value;
  if (typeof value !== "string" || !value.trim()) return {};
  try {
    const decoded = JSON.parse(value);
    return decoded && typeof decoded === "object" && !Array.isArray(decoded) ? decoded : {};
  } catch {
    return {};
  }
};
const creditRowsOf = (row) => {
  const salesSummary = toObject(row.cashup_sales_summary || row.sales_summary);
  const credit = toObject(salesSummary.credit);
  const tenderRows = toArray(credit.credit_sale_tender_rows);
  if (tenderRows.length) return tenderRows;
  return toArray(credit.sales_by_currency).map((entry) => ({
    ...entry,
    payment_name: entry.payment_name || "Customer Account",
    money_code: entry.money_code || "customer_credit",
    payment_count: entry.payment_count ?? entry.transaction_count ?? entry.sale_count ?? 0,
    amount: entry.amount ?? entry.sales_value ?? entry.credit_value ?? 0,
  }));
};
export const buildPaymentMethodTotals = (shiftPaymentRows = []) => {
  const map = new Map();
  shiftPaymentRows.forEach((row) => {
    const key = `${row.payment_name}|${row.money_code}|${row.currency_code}`;
    const item = map.get(key) || {
      payment_name: row.payment_name,
      money_code: row.money_code,
      currency_code: row.currency_code,
      shift_ids: new Set(),
      payment_count: 0,
      expected_amount: 0,
      counted_amount: 0,
      variance: 0,
      expected_complete: true,
      counted_complete: true,
      variance_complete: true,
    };
    item.shift_ids.add(row.shift_id);
    item.payment_count += row.payment_count;
    if (row.expected_amount === null) item.expected_complete = false;
    else item.expected_amount += row.expected_amount;
    if (row.counted_amount === null) item.counted_complete = false;
    else item.counted_amount += row.counted_amount;
    if (row.variance === null) item.variance_complete = false;
    else item.variance += row.variance;
    map.set(key, item);
  });

  return Array.from(map.values())
    .map(({ shift_ids: shiftIds, ...item }) => ({
      ...item,
      shift_count: shiftIds.size,
      expected_amount: item.expected_complete ? item.expected_amount : null,
      counted_amount: item.counted_complete ? item.counted_amount : null,
      variance: item.variance_complete ? item.variance : null,
    }))
    .sort((a, b) => `${a.payment_name}|${a.currency_code}`.localeCompare(`${b.payment_name}|${b.currency_code}`));
};
export const buildCurrencyTotals = (shiftCurrencyRows = []) => {
  const map = new Map();
  shiftCurrencyRows.forEach((row) => {
    const currencyCode = row.currency_code || "N/A";
    const item = map.get(currencyCode) || {
      currency_code: currencyCode,
      shifts: 0,
      payments: 0,
      expected: 0,
      counted: 0,
      variance: 0,
      expected_complete: true,
      counted_complete: true,
      variance_complete: true,
    };
    item.shifts += 1;
    item.payments += n(row.payment_count);
    if (row.expected_amount === null) item.expected_complete = false;
    else item.expected += row.expected_amount;
    if (row.counted_amount === null) item.counted_complete = false;
    else item.counted += row.counted_amount;
    if (row.variance === null) item.variance_complete = false;
    else item.variance += row.variance;
    map.set(currencyCode, item);
  });

  return Array.from(map.values())
    .map((item) => ({
      ...item,
      expected: item.expected_complete ? item.expected : null,
      counted: item.counted_complete ? item.counted : null,
      variance: item.variance_complete ? item.variance : null,
    }))
    .sort((a, b) => a.currency_code.localeCompare(b.currency_code));
};
const currencyCodes = (row) => toArray(row.cashup_currency_summary || row.currency_summary).map((r) => r.currency_code).filter(Boolean).join(", ") || "-";
const snapshotFilters = (value = {}) => ({ ...value, currency_codes: [...(value.currency_codes || [])] });

export default function ShiftReportModal({ open, onClose, currentUser, onNavigateReport, tradingDate = "" }) {
  const [filters, setFilters] = useState({ date_from: tradingDate, date_to: tradingDate, branch_id: currentUser?.branch_id || "", till_id: "", cashier_id: "", shift_session_id: "", status: "", currency_code: "", currency_codes: [], business_mode: "fast_food_boh" });
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [notice, setNotice] = useState("");
  const [viewing, setViewing] = useState(false);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserSource, setChooserSource] = useState("configuration");
  const [outputFilters, setOutputFilters] = useState(null);
  const [appliedFilters, setAppliedFilters] = useState(null);
  const lookups = useHospitalityReportLookups(open, filters);
  const activeFilters = appliedFilters || filters;
  const sourceRows = useMemo(() => rowsOf(report), [report]);
  const rows = useMemo(() => {
    const code = String(activeFilters.currency_code || "").trim().toUpperCase();
    if (!code) return sourceRows;
    return sourceRows.filter((row) => !row.cashup_id || toArray(row.cashup_currency_summary || row.currency_summary).some((c) => String(c.currency_code || "").toUpperCase() === code));
  }, [sourceRows, activeFilters.currency_code]);
  const summary = report?.summary || {};
  const canViewAmounts = canViewCashupFinancialAmounts(report);

  const shiftCurrencyRows = useMemo(() => rows.flatMap((row) => toArray(row.cashup_currency_summary || row.currency_summary).map((c) => ({
    opened_at: row.opened_at,
    closed_at: row.closed_at,
    cashier_name: row.cashier_name || "Unknown",
    till: `${row.till_code || ""} ${row.till_name || ""}`.trim() || "-",
    shift_name: row.shift_name || "-",
    status: row.status,
    currency_code: c.currency_code || "N/A",
    expected_amount: nullableNumber(c.expected_amount),
    counted_amount: nullableNumber(c.counted_amount),
    variance: nullableNumber(c.variance),
    payment_count: n(c.payment_count),
  }))), [rows]);

  const shiftPaymentRows = useMemo(() => rows.flatMap((row) => toArray(row.cashup_payment_summary || row.payment_summary).map((payment) => ({
    shift_id: row.id || row.shift_session_id || `${row.cashier_name || "Unknown"}-${row.opened_at || ""}`,
    opened_at: row.opened_at,
    closed_at: row.closed_at,
    cashier_name: row.cashier_name || "Unknown",
    till: `${row.till_code || ""} ${row.till_name || ""}`.trim() || "-",
    shift_name: row.shift_name || "-",
    status: row.status,
    payment_name: payment.payment_name || "Unknown payment method",
    money_code: payment.money_code || "-",
    currency_code: payment.currency_code || "N/A",
    payment_count: n(payment.payment_count),
    expected_amount: nullableNumber(payment.expected_amount),
    counted_amount: nullableNumber(payment.counted_amount),
    variance: nullableNumber(payment.variance),
  }))), [rows]);

  const paymentMethodTotals = useMemo(() => buildPaymentMethodTotals(shiftPaymentRows), [shiftPaymentRows]);

  const shiftAccountCreditRows = useMemo(() => rows.flatMap((row) => creditRowsOf(row).map((credit) => ({
    shift_id: row.id || row.shift_session_id || "-",
    opened_at: row.opened_at,
    cashier_name: row.cashier_name || "Unknown",
    till: `${row.till_code || ""} ${row.till_name || ""}`.trim() || "-",
    payment_name: credit.payment_name || "Customer Account",
    money_code: credit.money_code || "customer_credit",
    currency_code: credit.currency_code || "N/A",
    payment_count: n(credit.payment_count),
    amount: n(credit.amount ?? credit.sales_value ?? credit.credit_value),
  }))), [rows]);

  const accountCreditTotals = useMemo(() => {
    const map = new Map();
    shiftAccountCreditRows.forEach((row) => {
      const key = `${row.payment_name}|${row.money_code}|${row.currency_code}`;
      const item = map.get(key) || { ...row, shift_ids: new Set(), payment_count: 0, amount: 0 };
      item.shift_ids.add(row.shift_id);
      item.payment_count += row.payment_count;
      item.amount += row.amount;
      map.set(key, item);
    });
    return Array.from(map.values()).map(({ shift_ids: shiftIds, ...row }) => ({ ...row, shift_count: shiftIds.size }));
  }, [shiftAccountCreditRows]);

  const currencyTotals = useMemo(() => buildCurrencyTotals(shiftCurrencyRows), [shiftCurrencyRows]);

  const withAuthoritativeTradingDate = async (nextFilters) => {
    const day = await getCurrentTradingDay({
      ...(nextFilters.branch_id ? { branch_id: nextFilters.branch_id } : {}),
    });
    const tradingDate = day?.trading_date || "";

    if (!tradingDate) {
      throw new Error(day?.sale_block_reason || "No authoritative Hospitality trading date is open for this branch.");
    }

    return { ...nextFilters, business_mode: "fast_food_boh", date_from: tradingDate, date_to: tradingDate };
  };

  const refreshBranchTradingDate = async (nextFilters, key) => {
    if (key !== "branch_id") return;

    try {
      setLoading(true);
      setNotice("");
      setFilters(await withAuthoritativeTradingDate(nextFilters));
    } catch (error) {
      setNotice(error?.response?.data?.message || error?.message || "Failed to resolve the branch trading date.");
    } finally {
      setLoading(false);
    }
  };

  const load = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setNotice("");
      setReport(null);
      setReport(await getShiftReport(nextFilters));
      return true;
    } catch (error) {
      setReport(null);
      setNotice(error?.response?.data?.message || error?.message || "Failed to load shift report.");
      return false;
    } finally {
      setLoading(false);
    }
  };

  const openChooser = (source = "configuration") => {
    const nextFilters = source === "result" && appliedFilters ? appliedFilters : filters;
    setChooserSource(source);
    setOutputFilters(snapshotFilters(nextFilters));
    setChooserOpen(true);
  };

  const viewReport = async () => {
    const nextFilters = snapshotFilters(outputFilters || filters);
    setChooserOpen(false);
    setViewing(false);
    setAppliedFilters(null);
    const loaded = await load(nextFilters);
    if (loaded) {
      setAppliedFilters(nextFilters);
      setViewing(true);
    }
  };

  const refreshReport = async () => {
    const nextFilters = snapshotFilters(appliedFilters || filters);
    const loaded = await load(nextFilters);
    if (!loaded) {
      setAppliedFilters(null);
      setViewing(false);
    }
  };

  const downloadReport = async (format) => {
    try {
      setExporting(true);
      setNotice("");
      const nextFilters = snapshotFilters(outputFilters || appliedFilters || filters);
      if (format === "excel") await downloadShiftReportExcel(nextFilters);
      else await downloadShiftReportPdf(nextFilters);
      setChooserOpen(false);
    } catch (error) {
      setNotice(error?.response?.data?.message || error?.message || "Failed to download shift report.");
      setChooserOpen(false);
    } finally {
      setExporting(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    setReport(null);
    setViewing(false);
    setAppliedFilters(null);
    setOutputFilters(null);
    setChooserOpen(false);
    setNotice("");
    const initializeTradingDate = async () => {
      try {
        setLoading(true);
        const selectedDate = tradingDate || filters.date_from;
        setFilters(await withAuthoritativeTradingDate({
          ...filters,
          business_mode: "fast_food_boh",
          date_from: selectedDate,
          date_to: selectedDate,
        }));
      } catch (error) {
        setNotice(error?.response?.data?.message || error?.message || "Failed to load the current trading date.");
      } finally {
        setLoading(false);
      }
    };
    initializeTradingDate();
    /* eslint-disable-next-line react-hooks/exhaustive-deps */
  }, [open, tradingDate]);
  if (!open) return null;

  return (
    <FullScreenModal open={open} onClose={onClose} title="Shift Report" subtitle="Cash and non-cash payment methods, currency split cashup totals and cashier compliance">
      <div className="relative flex h-full min-h-0 overflow-hidden bg-[#E9EEEE] text-[#122424] text-[11px]">
        {notice && <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 border border-[#B42318] bg-white px-4 py-2 text-[12px] font-semibold text-[#B42318] shadow-xl">{notice}</div>}
        <HospitalityReportNavigator
          section="sales"
          activeKey={HOSPITALITY_REPORT_KEYS.SHIFT}
          onNavigate={onNavigateReport}
        />
        <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        {!viewing && <header className="shrink-0 bg-white">
          <HospitalityReportScopePanel filters={filters} setFilters={setFilters} lookups={lookups} loading={loading} onApply={() => openChooser("configuration")} onFilterChange={refreshBranchTradingDate} supported={{ shift_session_id: false }} businessModeLabel="Hospitality" periodLabel="Trading Date Period" />
          <div className="border-b border-[#AEB9B9] bg-white p-2"><div className="max-w-[300px]"><Field label="Status"><select value={filters.status} onChange={(e) => setFilters((p) => ({ ...p, status: e.target.value }))} className="report-filter"><option value="">All</option><option value="open">Open</option><option value="closed">Closed</option></select></Field></div></div>
          <style>{`.report-filter{height:32px;width:100%;border:1px solid #AEB9B9;background:white;padding:0 8px;font-size:11px;font-weight:500;color:#122424;outline:none}.report-filter:focus{border-color:#057B7C}`}</style>
        </header>}
        {!viewing ? (
          <ReportGenerationPrompt
            title="Configure Shift Report"
            message="Choose the branch, trading-date period, till, cashier, currency and shift status, then select Generate."
          />
        ) : <ReportViewShell
          title="Shift Report"
          subtitle="Cash and non-cash payment methods, currency split cashup totals and cashier compliance"
          backLabel="Configuration / Back"
          onBackToFilters={() => setViewing(false)}
          busy={loading}
          actions={<>
            <button type="button" onClick={refreshReport} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#AEB9B9] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
            <button type="button" onClick={() => openChooser("result")} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#069494] bg-white px-3 text-[11px] font-semibold text-[#008585] disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
          </>}
        >
        <div className="grid shrink-0 gap-2 border-b border-[#AEB9B9] bg-[#F6F8FA] p-2 md:grid-cols-3 xl:grid-cols-6">
          <Kpi label="Shifts" value={summary.shift_count || rows.length} />
          <Kpi label="Open" value={summary.open_shift_count || rows.filter((r) => r.status === "open").length} warning />
          <Kpi label="Closed" value={summary.closed_shift_count || rows.filter((r) => r.status === "closed").length} good />
          <Kpi label="Currencies" value={currencyTotals.length} />
          {canViewAmounts && currencyTotals.slice(0, 2).map((row) => <Kpi key={row.currency_code} label={`${row.currency_code} Variance`} value={nullableMoney(row.variance, row.currency_code)} danger={row.variance !== null && row.variance < 0} good={row.variance !== null && row.variance >= 0} />)}
        </div>
        <main className="min-h-0 flex-1 overflow-auto p-2 space-y-2">
          {!canViewAmounts && <RestrictedAmountsNotice />}
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Payment Method Summary" subtitle="Cash and every non-cash tender are reported separately by payment method and currency." />
            <ReportTable columns={canViewAmounts ? ["Payment Method", "Money Code", "Currency", "Shifts", "Payments", "Expected", "Counted", "Variance"] : ["Payment Method", "Money Code", "Currency", "Shifts", "Payments"]} rows={paymentMethodTotals.map((r) => canViewAmounts ? [r.payment_name, r.money_code, r.currency_code, r.shift_count, r.payment_count, nullableMoney(r.expected_amount, r.currency_code), nullableMoney(r.counted_amount, r.currency_code), nullableMoney(r.variance, r.currency_code)] : [r.payment_name, r.money_code, r.currency_code, r.shift_count, r.payment_count])} />
          </section>
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Shift Payment Method Detail" subtitle="Tender-level reconciliation for cash, card, mobile money, vouchers and every configured payment method." />
            <ReportTable columns={canViewAmounts ? ["Opened", "Closed", "Cashier", "Till", "Shift", "Status", "Payment Method", "Money Code", "Currency", "Payments", "Expected", "Counted", "Variance"] : ["Opened", "Closed", "Cashier", "Till", "Shift", "Status", "Payment Method", "Money Code", "Currency", "Payments"]} rows={shiftPaymentRows.map((r) => canViewAmounts ? [dateText(r.opened_at), dateText(r.closed_at), r.cashier_name, r.till, r.shift_name, r.status, r.payment_name, r.money_code, r.currency_code, r.payment_count, nullableMoney(r.expected_amount, r.currency_code), nullableMoney(r.counted_amount, r.currency_code), nullableMoney(r.variance, r.currency_code)] : [dateText(r.opened_at), dateText(r.closed_at), r.cashier_name, r.till, r.shift_name, r.status, r.payment_name, r.money_code, r.currency_code, r.payment_count])} />
          </section>
          {shiftAccountCreditRows.length > 0 && <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Account Credit Activity" subtitle="Customer-account sales create debt and are shown separately; they are not treated as cash or received tender." />
            <ReportTable columns={canViewAmounts ? ["Method", "Money Code", "Currency", "Shifts", "Credit Sales", "Credit Issued"] : ["Method", "Money Code", "Currency", "Shifts", "Credit Sales"]} rows={accountCreditTotals.map((r) => canViewAmounts ? [r.payment_name, r.money_code, r.currency_code, r.shift_count, r.payment_count, money(r.amount, r.currency_code)] : [r.payment_name, r.money_code, r.currency_code, r.shift_count, r.payment_count])} />
            <ReportTable columns={canViewAmounts ? ["Opened", "Cashier", "Till", "Method", "Money Code", "Currency", "Credit Sales", "Credit Issued"] : ["Opened", "Cashier", "Till", "Method", "Money Code", "Currency", "Credit Sales"]} rows={shiftAccountCreditRows.map((r) => canViewAmounts ? [dateText(r.opened_at), r.cashier_name, r.till, r.payment_name, r.money_code, r.currency_code, r.payment_count, money(r.amount, r.currency_code)] : [dateText(r.opened_at), r.cashier_name, r.till, r.payment_name, r.money_code, r.currency_code, r.payment_count])} />
          </section>}
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Currency Summary" subtitle="Shift cashup values are split by currency from pos_cashups.currency_summary." />
            <ReportTable columns={canViewAmounts ? ["Currency", "Shifts", "Payments", "Expected", "Counted", "Variance"] : ["Currency", "Shifts", "Payments"]} rows={currencyTotals.map((r) => canViewAmounts ? [r.currency_code, r.shifts, r.payments, nullableMoney(r.expected, r.currency_code), nullableMoney(r.counted, r.currency_code), nullableMoney(r.variance, r.currency_code)] : [r.currency_code, r.shifts, r.payments])} />
          </section>
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Shift Currency Detail" />
            <ReportTable columns={canViewAmounts ? ["Opened", "Closed", "Cashier", "Till", "Shift", "Status", "Currency", "Payments", "Expected", "Counted", "Variance"] : ["Opened", "Closed", "Cashier", "Till", "Shift", "Status", "Currency", "Payments"]} rows={shiftCurrencyRows.map((r) => canViewAmounts ? [dateText(r.opened_at), dateText(r.closed_at), r.cashier_name, r.till, r.shift_name, r.status, r.currency_code, r.payment_count, nullableMoney(r.expected_amount, r.currency_code), nullableMoney(r.counted_amount, r.currency_code), nullableMoney(r.variance, r.currency_code)] : [dateText(r.opened_at), dateText(r.closed_at), r.cashier_name, r.till, r.shift_name, r.status, r.currency_code, r.payment_count])} />
          </section>
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Shift Register" />
            <ReportTable columns={canViewAmounts ? ["Opened", "Closed", "Cashier", "Closed By", "Till", "Shift", "Status", "Currencies", "Opening Float", "Closing Cash", "Cashup Closed", "Cashouts"] : ["Opened", "Closed", "Cashier", "Closed By", "Till", "Shift", "Status", "Currencies", "Cashup Closed"]} rows={rows.map((r) => { const multiCurrency = r.cashup_is_multi_currency || String(r.cashup_currency_code || "").toUpperCase() === "MULTI"; const till = `${r.till_code || ""} ${r.till_name || ""}`.trim() || "-"; return canViewAmounts ? [dateText(r.opened_at), dateText(r.closed_at), r.cashier_name || "Unknown", r.closed_by_name || "-", till, r.shift_name || "-", r.status, currencyCodes(r), money(r.opening_float), nullableMoney(r.closing_cash), dateText(r.cashup_closed_at), multiCurrency ? "By currency" : money(r.cashup_cashout_total)] : [dateText(r.opened_at), dateText(r.closed_at), r.cashier_name || "Unknown", r.closed_by_name || "-", till, r.shift_name || "-", r.status, currencyCodes(r), dateText(r.cashup_closed_at)]; })} />
          </section>
        </main>
        </ReportViewShell>}
        </div>
      </div>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle="Shift Report"
        busy={exporting}
        busyLabel="Preparing shift report…"
        onClose={() => setChooserOpen(false)}
        onView={viewReport}
        onDownloadPdf={() => downloadReport("pdf")}
        onDownloadExcel={() => downloadReport("excel")}
        outputs={chooserSource === "result" ? { view: { disabled: true, reason: "This report is already open." } } : undefined}
      />
    </FullScreenModal>
  );
}

const Field = ({ label, children }) => <label><div className="mb-1 text-[9px] font-semibold uppercase tracking-wide text-[#627474]">{label}</div>{children}</label>;
const SectionTitle = ({ title, subtitle }) => <div className="border-b border-[#C6CFCF] bg-[#F5F7FA] px-3 py-2"><div className="text-[12px] font-semibold uppercase text-[#003636]">{title}</div>{subtitle && <div className="text-[10px] text-[#627474]">{subtitle}</div>}</div>;
const Kpi = ({ label, value, good = false, danger = false, warning = false }) => <div className="border border-[#C6CFCF] bg-white p-2"><div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div><div className={`mt-1 truncate text-[16px] font-semibold ${danger ? "text-[#B42318]" : good ? "text-[#15803D]" : warning ? "text-[#B45308]" : "text-[#003636]"}`}>{value}</div></div>;
const RestrictedAmountsNotice = () => <div className="border border-[#F2C94C] bg-[#FFF8E1] px-3 py-2 text-[11px] font-semibold text-[#7A5200]">Financial cashup amounts are restricted for this role. Shift status and audit timing remain available.</div>;
const ReportTable = ({ columns, rows }) => <div className="overflow-auto"><table className="sx-enterprise-table w-full min-w-[980px] border-collapse text-[11px]"><thead><tr className="border-b border-[#AEB9B9] bg-[#EEF2F2]">{columns.map((c) => <th key={c} className="whitespace-nowrap border-r border-[#D5DDDD] px-2 py-2 text-left text-[10px] font-semibold uppercase text-[#324444] last:border-r-0">{c}</th>)}</tr></thead><tbody>{rows.length ? rows.map((row, i) => <tr key={i} className="border-b border-[#E3E9E9] odd:bg-white even:bg-[#FAFBFC] hover:bg-[#F0F7F5]">{row.map((cell, j) => <td key={j} className="whitespace-nowrap border-r border-[#EEF2F2] px-2 py-2 text-[#324443] last:border-r-0">{cell ?? "-"}</td>)}</tr>) : <tr><td colSpan={columns.length} className="px-3 py-10 text-center text-[12px] text-[#627474]">No records found.</td></tr>}</tbody></table></div>;
