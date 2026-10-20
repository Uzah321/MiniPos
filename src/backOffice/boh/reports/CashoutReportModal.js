import React, { useEffect, useMemo, useState } from "react";
import { Download, FileText } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import { downloadCashoutReportExcel, downloadCashoutReportPdf, getCashoutReport } from "../../../services/CashupReportsService";
import { getCurrentTradingDay } from "../../../services/MenuRecipeDsrService";
import { canViewCashupFinancialAmounts } from "../../../utils/CashupPermissions";

const n = (v) => Number(v || 0);
const money = (v, c = "") => `${c ? `${c} ` : ""}${n(v).toFixed(2)}`;
const dateText = (v) => (v ? String(v).replace("T", " ").slice(0, 19) : "-");
const pageRows = (payload) => payload?.data?.data || payload?.data?.rows || payload?.rows || [];

const CASH_OUT_TYPES = ["cash_out", "bank_drop", "paid_out", "petty_cash", "float_out"];
const CASH_IN_TYPES = ["cash_in", "float_in"];

export default function CashoutReportModal({ open, onClose, currentUser }) {
  const [filters, setFilters] = useState({ date_from: "", date_to: "", branch_id: currentUser?.branch_id || "", till_id: "", cashier_id: "", shift_session_id: "", movement_type: "", currency_code: "", currency_codes: [], business_mode: "fast_food_boh", per_page: 150 });
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const lookups = useHospitalityReportLookups(open, filters);
  const rows = useMemo(() => pageRows(report), [report]);
  const summary = report?.summary || {};
  const canViewAmounts = canViewCashupFinancialAmounts(report);

  const currencyRows = useMemo(() => {
    const map = new Map();
    rows.forEach((row) => {
      const code = row.currency_code || "N/A";
      const item = map.get(code) || { currency_code: code, movements: 0, cash_out: 0, cash_in: 0, net: 0 };
      item.movements += 1;
      if (CASH_OUT_TYPES.includes(row.movement_type)) item.cash_out += n(row.amount);
      if (CASH_IN_TYPES.includes(row.movement_type)) item.cash_in += n(row.amount);
      item.net = item.cash_in - item.cash_out;
      map.set(code, item);
    });
    return Array.from(map.values()).sort((a, b) => a.currency_code.localeCompare(b.currency_code));
  }, [rows]);

  const load = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setNotice("");
      setReport(await getCashoutReport(nextFilters));
    } catch (error) {
      setNotice(error?.response?.data?.message || error?.message || "Failed to load cashout report.");
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

    return { ...nextFilters, date_from: tradingDate, date_to: tradingDate };
  };

  const refreshBranchTradingDate = async (nextFilters, key) => {
    if (key !== "branch_id") return;

    try {
      setLoading(true);
      setNotice("");
      const authoritativeFilters = await withAuthoritativeTradingDate(nextFilters);
      setFilters(authoritativeFilters);
      setReport(await getCashoutReport(authoritativeFilters));
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
        setReport(await getCashoutReport(authoritativeFilters));
      } catch (error) {
        setNotice(error?.response?.data?.message || error?.message || "Failed to load the current trading date.");
      } finally {
        setLoading(false);
      }
    };
    initialize();
    /* eslint-disable-next-line react-hooks/exhaustive-deps */
  }, [open]);
  if (!open) return null;

  return (
    <FullScreenModal open={open} onClose={onClose} title="Cashout Report" subtitle="Currency split drawer movements, bank drops, paid outs, cash in and float movement audit">
      <div className="relative flex h-[calc(100vh-86px)] flex-col overflow-hidden bg-[#E9EEEE] text-[#122424] text-[11px]">
        {notice && <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 border border-[#B42318] bg-white px-4 py-2 text-[12px] font-semibold text-[#B42318] shadow-xl">{notice}</div>}
        <header className="shrink-0 bg-white">
          <HospitalityReportScopePanel filters={filters} setFilters={setFilters} lookups={lookups} loading={loading} onApply={() => load()} onFilterChange={refreshBranchTradingDate} actions={<><button type="button" title="Excel" onClick={() => downloadCashoutReportExcel(filters)} className="inline-flex h-9 w-10 items-center justify-center border border-[#027A48] bg-white text-[#027A48]"><Download className="h-4 w-4" /></button><button type="button" title="PDF" onClick={() => downloadCashoutReportPdf(filters)} className="inline-flex h-9 w-10 items-center justify-center border border-[#B42318] bg-white text-[#B42318]"><FileText className="h-4 w-4" /></button></>} />
          <div className="border-b border-[#AEB9B9] bg-white p-2"><div className="max-w-[300px]"><Field label="Movement"><select value={filters.movement_type} onChange={(e) => setFilters((p) => ({ ...p, movement_type: e.target.value }))} className="report-filter"><option value="">All</option><option value="cash_out">Cash Out</option><option value="bank_drop">Bank Drop</option><option value="paid_out">Paid Out</option><option value="petty_cash">Petty Cash</option><option value="float_out">Float Out</option><option value="cash_in">Cash In</option><option value="float_in">Float In</option></select></Field></div></div>
          <style>{`.report-filter{height:32px;width:100%;border:1px solid #AEB9B9;background:white;padding:0 8px;font-size:11px;font-weight:500;color:#122424;outline:none}.report-filter:focus{border-color:#057B7C}`}</style>
        </header>
        <div className="grid shrink-0 gap-2 border-b border-[#AEB9B9] bg-[#F6F8FA] p-2 md:grid-cols-3 xl:grid-cols-6">
          <Kpi label="Movements" value={summary.movement_count || rows.length} />
          <Kpi label="Currencies" value={currencyRows.length} />
          {canViewAmounts && currencyRows.slice(0, 4).map((row) => <Kpi key={row.currency_code} label={`${row.currency_code} Net`} value={money(row.net, row.currency_code)} danger={row.net < 0} good={row.net >= 0} />)}
        </div>
        <main className="min-h-0 flex-1 overflow-auto p-2 space-y-2">
          {!canViewAmounts && <RestrictedAmountsNotice />}
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Currency Summary" subtitle="Cash in, cash out and net movement are separated per currency." />
            <ReportTable columns={canViewAmounts ? ["Currency", "Movements", "Cash Out", "Cash In", "Net Movement"] : ["Currency", "Movements"]} rows={currencyRows.map((r) => canViewAmounts ? [r.currency_code, r.movements, money(r.cash_out, r.currency_code), money(r.cash_in, r.currency_code), money(r.net, r.currency_code)] : [r.currency_code, r.movements])} />
          </section>
          <section className="border border-[#AEB9B9] bg-white">
            <SectionTitle title="Drawer Movements" />
            <ReportTable columns={canViewAmounts ? ["Occurred", "Type", "Currency", "Amount", "Cashier", "Till", "Shift", "Reference", "Reason", "Notes", "Cashup"] : ["Occurred", "Type", "Currency", "Cashier", "Till", "Shift", "Reference", "Reason", "Notes", "Cashup"]} rows={rows.map((r) => canViewAmounts ? [dateText(r.occurred_at), r.movement_type, r.currency_code, money(r.amount, r.currency_code), r.cashier_name, `${r.till_code || ""} ${r.till_name || ""}`.trim(), r.shift_name || "-", r.reference_no || "-", r.reason || "-", r.notes || "-", r.cashup_status || "Not cashed up"] : [dateText(r.occurred_at), r.movement_type, r.currency_code, r.cashier_name, `${r.till_code || ""} ${r.till_name || ""}`.trim(), r.shift_name || "-", r.reference_no || "-", r.reason || "-", r.notes || "-", r.cashup_status || "Not cashed up"])} />
          </section>
        </main>
      </div>
    </FullScreenModal>
  );
}

const Field = ({ label, children }) => <label><div className="mb-1 text-[9px] font-semibold uppercase tracking-wide text-[#627474]">{label}</div>{children}</label>;
const SectionTitle = ({ title, subtitle }) => <div className="border-b border-[#C6CFCF] bg-[#F5F7FA] px-3 py-2"><div className="text-[12px] font-semibold uppercase text-[#003636]">{title}</div>{subtitle && <div className="text-[10px] text-[#627474]">{subtitle}</div>}</div>;
const Kpi = ({ label, value, good = false, danger = false, warning = false }) => <div className="border border-[#C6CFCF] bg-white p-2"><div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div><div className={`mt-1 truncate text-[16px] font-semibold ${danger ? "text-[#B42318]" : good ? "text-[#15803D]" : warning ? "text-[#B45308]" : "text-[#003636]"}`}>{value}</div></div>;
const RestrictedAmountsNotice = () => <div className="border border-[#F2C94C] bg-[#FFF8E1] px-3 py-2 text-[11px] font-semibold text-[#7A5200]">Cash movement amounts are restricted for this role. Movement type, currency and audit references remain available.</div>;
const ReportTable = ({ columns, rows }) => <div className="overflow-auto"><table className="sx-enterprise-table w-full min-w-[980px] border-collapse text-[11px]"><thead><tr className="border-b border-[#AEB9B9] bg-[#EEF2F2]">{columns.map((c) => <th key={c} className="whitespace-nowrap border-r border-[#D5DDDD] px-2 py-2 text-left text-[10px] font-semibold uppercase text-[#324444] last:border-r-0">{c}</th>)}</tr></thead><tbody>{rows.length ? rows.map((row, i) => <tr key={i} className="border-b border-[#E3E9E9] odd:bg-white even:bg-[#FAFBFC] hover:bg-[#F0F7F5]">{row.map((cell, j) => <td key={j} className="whitespace-nowrap border-r border-[#EEF2F2] px-2 py-2 text-[#324443] last:border-r-0">{cell ?? "-"}</td>)}</tr>) : <tr><td colSpan={columns.length} className="px-3 py-10 text-center text-[12px] text-[#627474]">No records found.</td></tr>}</tbody></table></div>;
