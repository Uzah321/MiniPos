import React, { useEffect, useMemo, useState } from "react";
import { Download, FileText } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import { downloadCashupHistoryExcel, downloadCashupHistoryPdf, getCashupHistoryReport } from "../../../services/CashupReportsService";
import { getCurrentTradingDay } from "../../../services/MenuRecipeDsrService";
import { canViewCashupFinancialAmounts } from "../../../utils/CashupPermissions";

const n = (v) => Number(v || 0);
const money = (v, c = "") => `${c ? `${c} ` : ""}${n(v).toFixed(2)}`;
const dateText = (v) => (v ? String(v).replace("T", " ").slice(0, 19) : "-");
const rowsOf = (payload) => payload?.data?.data || payload?.data || payload?.rows || payload || [];
const toArray = (value) => {
  if (Array.isArray(value)) return value;
  if (typeof value === "string" && value.trim()) {
    try { const decoded = JSON.parse(value); return Array.isArray(decoded) ? decoded : []; } catch { return []; }
  }
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
  const salesSummary = toObject(row.sales_summary || row.cashup_sales_summary);
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
const currencyCodes = (row) => toArray(row?.currency_summary).map((r) => r.currency_code).filter(Boolean).join(", ") || row?.currency_code || "-";

export default function CashupHistoryReportModal({ open, onClose, currentUser }) {
  const [filters, setFilters] = useState({ business_date: "", date_from: "", date_to: "", branch_id: currentUser?.branch_id || "", till_id: "", cashier_id: "", shift_session_id: "", currency_code: "", currency_codes: [], business_mode: "fast_food_boh", per_page: 100 });
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const lookups = useHospitalityReportLookups(open, filters);
  const canViewAmounts = canViewCashupFinancialAmounts(report);

  const sourceRows = useMemo(() => rowsOf(report), [report]);
  const rows = useMemo(() => {
    const code = String(filters.currency_code || "").trim().toUpperCase();
    if (!code) return sourceRows;
    return sourceRows.filter((row) => toArray(row.currency_summary).some((c) => String(c.currency_code || "").toUpperCase() === code));
  }, [sourceRows, filters.currency_code]);

  const paymentMethodRows = useMemo(() => rows.flatMap((row) =>
    toArray(row.payment_summary).map((payment) => ({
      cashup_id: row.id,
      trading_date: row.cashup_bracket_date || row.trading_date || "",
      closed_at: row.closed_at,
      cashier_name: row.cashier_name || "Unknown",
      till: `${row.till_code || ""} ${row.till_name || ""}`.trim() || "-",
      payment_name: payment.payment_name || "Unknown payment method",
      money_code: payment.money_code || "-",
      currency_code: payment.currency_code || "N/A",
      payment_count: n(payment.payment_count),
      expected_amount: n(payment.expected_amount),
      counted_amount: n(payment.counted_amount),
      variance: n(payment.variance),
    }))
  ), [rows]);

  const paymentMethodTotals = useMemo(() => {
    const map = new Map();
    paymentMethodRows.forEach((row) => {
      const key = `${row.payment_name}|${row.money_code}|${row.currency_code}`;
      const item = map.get(key) || {
        payment_name: row.payment_name,
        money_code: row.money_code,
        currency_code: row.currency_code,
        cashup_ids: new Set(),
        payment_count: 0,
        expected_amount: 0,
        counted_amount: 0,
        variance: 0,
      };
      item.cashup_ids.add(row.cashup_id);
      item.payment_count += row.payment_count;
      item.expected_amount += row.expected_amount;
      item.counted_amount += row.counted_amount;
      item.variance += row.variance;
      map.set(key, item);
    });

    return Array.from(map.values())
      .map(({ cashup_ids: cashupIds, ...item }) => ({ ...item, cashup_count: cashupIds.size }))
      .sort((a, b) => `${a.payment_name}|${a.currency_code}`.localeCompare(`${b.payment_name}|${b.currency_code}`));
  }, [paymentMethodRows]);

  const accountCreditRows = useMemo(() => rows.flatMap((row) => creditRowsOf(row).map((credit) => ({
    cashup_id: row.id,
    trading_date: row.cashup_bracket_date || row.trading_date || "",
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
    accountCreditRows.forEach((row) => {
      const key = `${row.payment_name}|${row.money_code}|${row.currency_code}`;
      const item = map.get(key) || { ...row, cashup_ids: new Set(), payment_count: 0, amount: 0 };
      item.cashup_ids.add(row.cashup_id);
      item.payment_count += row.payment_count;
      item.amount += row.amount;
      map.set(key, item);
    });
    return Array.from(map.values()).map(({ cashup_ids: cashupIds, ...row }) => ({ ...row, cashup_count: cashupIds.size }));
  }, [accountCreditRows]);

  const cashupCurrencyRows = useMemo(() => rows.flatMap((row) =>
    toArray(row.currency_summary).map((currency) => ({
      trading_date: row.cashup_bracket_date || row.trading_date || "",
      closed_at: row.closed_at,
      cashier_name: row.cashier_name || "Unknown",
      closed_by_name: row.closed_by_name || "Unknown",
      till: `${row.till_code || ""} ${row.till_name || ""}`.trim() || "-",
      currency_code: currency.currency_code || "N/A",
      expected_amount: n(currency.expected_amount),
      counted_amount: n(currency.counted_amount),
      variance: n(currency.variance),
      payment_count: n(currency.payment_count),
    }))
  ), [rows]);

  const currencyTotals = useMemo(() => {
    const map = new Map();
    cashupCurrencyRows.forEach((row) => {
      const code = row.currency_code || "N/A";
      const item = map.get(code) || { currency_code: code, cashups: 0, expected: 0, counted: 0, variance: 0, payment_count: 0 };
      item.cashups += 1;
      item.expected += n(row.expected_amount);
      item.counted += n(row.counted_amount);
      item.variance += n(row.variance);
      item.payment_count += n(row.payment_count);
      map.set(code, item);
    });
    return Array.from(map.values()).sort((a, b) => a.currency_code.localeCompare(b.currency_code));
  }, [cashupCurrencyRows]);

  const totals = useMemo(() => rows.reduce((acc, row) => ({
    cashups: acc.cashups + 1,
    transactions: acc.transactions + n(row.transaction_count),
  }), { cashups: 0, transactions: 0 }), [rows]);

  const load = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setNotice("");
      setReport(await getCashupHistoryReport(nextFilters));
    } catch (error) {
      setNotice(error?.response?.data?.message || error?.message || "Failed to load cashup history.");
    } finally {
      setLoading(false);
    }
  };

  const withAuthoritativeTradingDate = async (nextFilters) => {
    const day = await getCurrentTradingDay({
      ...(nextFilters.branch_id ? { branch_id: nextFilters.branch_id } : {}),
    });
    const tradingDate = day?.trading_date || "";

    if (!tradingDate) {
      throw new Error(day?.sale_block_reason || "No authoritative Hospitality trading date is open for this branch.");
    }

    return {
      ...nextFilters,
      business_date: tradingDate,
      date_from: tradingDate,
      date_to: tradingDate,
    };
  };

  const refreshBranchTradingDate = async (nextFilters, key) => {
    if (key !== "branch_id") return;

    try {
      setLoading(true);
      setNotice("");
      const authoritativeFilters = await withAuthoritativeTradingDate(nextFilters);
      setFilters(authoritativeFilters);
      setReport(await getCashupHistoryReport(authoritativeFilters));
    } catch (error) {
      setNotice(error?.response?.data?.message || error?.message || "Failed to resolve the branch trading date.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    const initialize = async () => {
      try {
        setLoading(true);
        setNotice("");
        const authoritativeFilters = await withAuthoritativeTradingDate(filters);
        setFilters(authoritativeFilters);
        setReport(await getCashupHistoryReport(authoritativeFilters));
      } catch (error) {
        setNotice(error?.response?.data?.message || error?.message || "Failed to load the current trading date.");
      } finally {
        setLoading(false);
      }
    };
    initialize();
    /* eslint-disable-next-line */
  }, [open]);
  if (!open) return null;

  return (
    <FullScreenModal open={open} onClose={onClose} title="Cashup History Report" subtitle="Cashups grouped by Hospitality Trading Date with currency audit, counted totals, variances and drawer cashouts">
      <div className="relative flex h-[calc(100vh-86px)] flex-col overflow-hidden bg-[#E9EEEE] text-[#122424] text-[11px]">
        {notice && <Notice message={notice} />}
        <header className="shrink-0 bg-white">
          <HospitalityReportScopePanel filters={filters} setFilters={setFilters} lookups={lookups} loading={loading} onApply={() => load()} onFilterChange={refreshBranchTradingDate} singleTradingDate actions={<><button type="button" onClick={() => downloadCashupHistoryExcel(filters)} className="inline-flex h-9 w-10 items-center justify-center border border-[#027A48] bg-white text-[#027A48]" title="Excel"><Download className="h-4 w-4" /></button><button type="button" onClick={() => downloadCashupHistoryPdf(filters)} className="inline-flex h-9 w-10 items-center justify-center border border-[#B42318] bg-white text-[#B42318]" title="PDF"><FileText className="h-4 w-4" /></button></>} />
        </header>
        <div className="grid shrink-0 gap-2 border-b border-[#AEB9B9] bg-[#F6F8FA] p-2 md:grid-cols-3 xl:grid-cols-6">
          <Kpi label="Cashups" value={totals.cashups} />
          <Kpi label="Transactions" value={totals.transactions} />
          <Kpi label="Currencies" value={currencyTotals.length} />
          {canViewAmounts && currencyTotals.slice(0, 2).map((row) => <Kpi key={row.currency_code} label={`${row.currency_code} Variance`} value={money(row.variance, row.currency_code)} danger={row.variance < 0} good={row.variance >= 0} />)}
        </div>
        <div className="min-h-0 flex-1 overflow-auto p-2 space-y-2">
          {!canViewAmounts && <RestrictedAmountsNotice />}
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Payment Method Summary" subtitle="Cash and every non-cash tender are reported separately by method and currency." />
            <ReportTable columns={canViewAmounts ? ["Payment Method", "Money Code", "Currency", "Cashups", "Payments", "Expected", "Counted", "Variance"] : ["Payment Method", "Money Code", "Currency", "Cashups", "Payments"]} rows={paymentMethodTotals.map((r) => canViewAmounts ? [r.payment_name, r.money_code, r.currency_code, r.cashup_count, r.payment_count, money(r.expected_amount, r.currency_code), money(r.counted_amount, r.currency_code), money(r.variance, r.currency_code)] : [r.payment_name, r.money_code, r.currency_code, r.cashup_count, r.payment_count])} />
          </section>
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Cashup Payment Method Detail" subtitle="Tender-level evidence for cash, card, mobile money, vouchers and every configured payment method." />
            <ReportTable columns={canViewAmounts ? ["Trading Date", "Closed At", "Cashier", "Till", "Payment Method", "Money Code", "Currency", "Payments", "Expected", "Counted", "Variance"] : ["Trading Date", "Closed At", "Cashier", "Till", "Payment Method", "Money Code", "Currency", "Payments"]} rows={paymentMethodRows.map((r) => canViewAmounts ? [r.trading_date, dateText(r.closed_at), r.cashier_name, r.till, r.payment_name, r.money_code, r.currency_code, r.payment_count, money(r.expected_amount, r.currency_code), money(r.counted_amount, r.currency_code), money(r.variance, r.currency_code)] : [r.trading_date, dateText(r.closed_at), r.cashier_name, r.till, r.payment_name, r.money_code, r.currency_code, r.payment_count])} />
          </section>
          {accountCreditRows.length > 0 && <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Account Credit Activity" subtitle="Customer-account sales create debt and are shown separately; they are not treated as cash or received tender." />
            <ReportTable columns={canViewAmounts ? ["Method", "Money Code", "Currency", "Cashups", "Credit Sales", "Credit Issued"] : ["Method", "Money Code", "Currency", "Cashups", "Credit Sales"]} rows={accountCreditTotals.map((r) => canViewAmounts ? [r.payment_name, r.money_code, r.currency_code, r.cashup_count, r.payment_count, money(r.amount, r.currency_code)] : [r.payment_name, r.money_code, r.currency_code, r.cashup_count, r.payment_count])} />
            <ReportTable columns={canViewAmounts ? ["Trading Date", "Cashier", "Till", "Method", "Money Code", "Currency", "Credit Sales", "Credit Issued"] : ["Trading Date", "Cashier", "Till", "Method", "Money Code", "Currency", "Credit Sales"]} rows={accountCreditRows.map((r) => canViewAmounts ? [r.trading_date, r.cashier_name, r.till, r.payment_name, r.money_code, r.currency_code, r.payment_count, money(r.amount, r.currency_code)] : [r.trading_date, r.cashier_name, r.till, r.payment_name, r.money_code, r.currency_code, r.payment_count])} />
          </section>}
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Currency Summary" subtitle="Totals are separated per currency. No mixed currency totals are displayed." />
            <ReportTable columns={canViewAmounts ? ["Currency", "Cashups", "Payments", "Expected", "Counted", "Variance"] : ["Currency", "Cashups", "Payments"]} rows={currencyTotals.map((r) => canViewAmounts ? [r.currency_code, r.cashups, r.payment_count, money(r.expected, r.currency_code), money(r.counted, r.currency_code), money(r.variance, r.currency_code)] : [r.currency_code, r.cashups, r.payment_count])} />
          </section>
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Cashup Currency Detail" subtitle="Each cashup split by its own currency_summary." />
            <ReportTable columns={canViewAmounts ? ["Trading Date", "Closed At", "Cashier", "Closed By", "Till", "Currency", "Payments", "Expected", "Counted", "Variance"] : ["Trading Date", "Closed At", "Cashier", "Closed By", "Till", "Currency", "Payments"]} rows={cashupCurrencyRows.map((r) => canViewAmounts ? [r.trading_date, dateText(r.closed_at), r.cashier_name, r.closed_by_name, r.till, r.currency_code, r.payment_count, money(r.expected_amount, r.currency_code), money(r.counted_amount, r.currency_code), money(r.variance, r.currency_code)] : [r.trading_date, dateText(r.closed_at), r.cashier_name, r.closed_by_name, r.till, r.currency_code, r.payment_count])} />
          </section>
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Cashup Register" subtitle="Register view with currency list per cashup." />
            <ReportTable columns={canViewAmounts ? ["Trading Date", "Closed At", "Opened At", "Cashier", "Closed By", "Till", "Currencies", "Status", "Txns", "Cashouts", "Expected After Cashouts", "Cash Var", "Notes"] : ["Trading Date", "Closed At", "Opened At", "Cashier", "Closed By", "Till", "Currencies", "Status", "Txns", "Notes"]} rows={rows.map((r) => { const multiCurrency = r.is_multi_currency || String(r.cashup_currency_code || "").toUpperCase() === "MULTI"; const tradingDate = r.cashup_bracket_date || r.trading_date || "-"; return canViewAmounts ? [tradingDate, dateText(r.closed_at), dateText(r.opened_at), r.cashier_name || "Unknown", r.closed_by_name || "Unknown", `${r.till_code || ""} ${r.till_name || ""}`.trim() || "-", currencyCodes(r), r.status, r.transaction_count, multiCurrency ? "By currency" : money(r.cashout_total || r.effective_cashout_total), multiCurrency ? "By currency" : money(r.expected_cash_after_cashouts || r.effective_expected_cash_after_cashouts), multiCurrency ? "By currency" : money(r.cash_variance), r.notes || "-"] : [tradingDate, dateText(r.closed_at), dateText(r.opened_at), r.cashier_name || "Unknown", r.closed_by_name || "Unknown", `${r.till_code || ""} ${r.till_name || ""}`.trim() || "-", currencyCodes(r), r.status, r.transaction_count, r.notes || "-"]; })} />
          </section>
        </div>
      </div>
    </FullScreenModal>
  );
}

const SectionTitle = ({ title, subtitle }) => <div className="border-b border-[#C6CFCF] bg-[#F5F7FA] px-3 py-2"><div className="text-[12px] font-semibold uppercase text-[#003636]">{title}</div>{subtitle && <div className="text-[10px] text-[#627474]">{subtitle}</div>}</div>;
const Kpi = ({ label, value, good = false, danger = false, warning = false }) => <div className="border border-[#C6CFCF] bg-white p-2"><div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div><div className={`mt-1 truncate text-[16px] font-semibold ${danger ? "text-[#B42318]" : good ? "text-[#15803D]" : warning ? "text-[#B45309]" : "text-[#003636]"}`}>{value}</div></div>;
const Notice = ({ message }) => <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 border border-[#B42318] bg-white px-4 py-2 text-[12px] font-semibold text-[#B42318] shadow-xl">{message}</div>;
const RestrictedAmountsNotice = () => <div className="border border-[#F2C94C] bg-[#FFF8E1] px-3 py-2 text-[11px] font-semibold text-[#7A5200]">Financial cashup amounts are restricted for this role. Operational cashup history remains available.</div>;
const ReportTable = ({ columns, rows }) => <div className="overflow-auto"><table className="sx-enterprise-table w-full min-w-[980px] border-collapse text-[11px]"><thead><tr className="border-b border-[#AEB9B9] bg-[#EEF2F2]">{columns.map((c) => <th key={c} className="whitespace-nowrap border-r border-[#D5DDDD] px-2 py-2 text-left text-[10px] font-semibold uppercase text-[#324444] last:border-r-0">{c}</th>)}</tr></thead><tbody>{rows.length ? rows.map((row, i) => <tr key={i} className="border-b border-[#E3E9E9] odd:bg-white even:bg-[#FAFBFC] hover:bg-[#F0F7F5]">{row.map((cell, j) => <td key={j} className="whitespace-nowrap border-r border-[#EEF2F2] px-2 py-2 text-[#324443] last:border-r-0">{cell ?? "-"}</td>)}</tr>) : <tr><td colSpan={columns.length} className="px-3 py-10 text-center text-[12px] text-[#627474]">No records found.</td></tr>}</tbody></table></div>;
