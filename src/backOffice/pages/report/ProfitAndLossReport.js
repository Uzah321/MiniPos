import React, { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw } from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel from "../../../components/HospitalityReportScopePanel";
import ManagementReportNavigator, {
  MANAGEMENT_REPORT_KEYS,
} from "../../../components/ManagementReportNavigator";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import { getAuthorisedCompany, getPosUsers } from "../../../services/AuthenticationService";
import { getCurrencies } from "../../../services/CurrencyService";
import { getTills } from "../../../services/TillService";
import { getShiftSessions } from "../../../services/ShiftService";
import {
  downloadSalesReportExport,
  getProfitAndLossReport,
} from "../../../services/SalesReportService";

const today = () => new Date().toISOString().slice(0, 10);

const defaultFilters = () => ({
  date_from: today(),
  date_to: today(),
  branch_id: "",
  till_id: "",
  cashier_id: "",
  shift_session_id: "",
  currency_code: "",
  currency_codes: [],
  per_page: 500,
  page: 1,
});

const money = (value, currency = "USD") =>
  `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const number = (value, decimals = 2) => Number(value || 0).toFixed(decimals);

const firstDefined = (...values) => values.find((value) => value !== undefined);

const isNumericValue = (value) =>
  value !== null && value !== undefined && value !== "" && Number.isFinite(Number(value));

export const nullableMoney = (value, currency = "USD", perCurrency = false) => {
  if (!isNumericValue(value)) return perCurrency ? "Per currency" : "—";
  return `${currency || "USD"} ${Number(value).toFixed(2)}`;
};

export const nullablePercent = (value, perCurrency = false, decimals = 2) => {
  if (!isNumericValue(value)) return perCurrency ? "Per currency" : "—";
  return `${Number(value).toFixed(decimals)}%`;
};

const booleanValue = (value) => value === true || value === 1 || value === "1" || String(value).toLowerCase() === "true";

export const getCashMovementDisplayState = (summary = {}, cashoutSummary = {}) => {
  const compatibleValue = firstDefined(
    summary.cash_movement_currency_compatible,
    cashoutSummary.currency_compatible,
  );
  const mixedValue = firstDefined(
    summary.cash_movement_is_mixed_currency,
    cashoutSummary.is_mixed_currency,
  );
  const unconvertedValue = firstDefined(
    summary.cash_movement_has_unconverted_amounts,
    cashoutSummary.has_unconverted_amounts,
  );
  const totalsByCurrency = Array.isArray(cashoutSummary.totals_by_currency)
    ? cashoutSummary.totals_by_currency
    : [];
  const isMixedCurrency = mixedValue === undefined
    ? totalsByCurrency.length > 1
    : booleanValue(mixedValue);
  const hasUnconvertedAmounts = booleanValue(unconvertedValue);
  const currencyCompatible = compatibleValue === undefined
    ? !hasUnconvertedAmounts
    : booleanValue(compatibleValue);
  const message = firstDefined(
    summary.cash_movement_message,
    cashoutSummary.message,
  ) || "";

  return {
    currencyCompatible,
    isMixedCurrency,
    hasUnconvertedAmounts,
    message,
    perCurrency: isMixedCurrency && (!currencyCompatible || hasUnconvertedAmounts),
  };
};

const getPayload = (response) => response?.data || response || {};

const normalizeList = (response) => {
  const payload = response?.data || response;

  if (Array.isArray(response)) return response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.company?.branches)) return payload.company.branches;
  if (Array.isArray(payload?.branches)) return payload.branches;

  return [];
};

const getPaginatedRows = (response) => {
  const payload = getPayload(response);
  const data = payload?.data || payload;

  if (Array.isArray(data?.data)) return data.data;
  if (Array.isArray(data)) return data;

  return [];
};

const getPaginationMeta = (response) => {
  const payload = getPayload(response);
  const data = payload?.data || payload;

  return {
    current_page: Number(data?.current_page || 1),
    last_page: Number(data?.last_page || 1),
    total: Number(data?.total || 0),
    per_page: Number(data?.per_page || 100),
  };
};

const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;

  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }

  return data?.message || data?.error || error?.message || fallback;
};

const getCurrencyCode = (currency) => currency?.code || currency?.currency_code || "";
const isBaseCurrency = (currency) => currency?.is_base_currency === true || currency?.is_base_currency === 1 || currency?.is_base_currency === "1" || String(currency?.is_base_currency).toLowerCase() === "true";

const useProfitLossLookups = (open, filters) => {
  const [branches, setBranches] = useState([]);
  const [tills, setTills] = useState([]);
  const [cashiers, setCashiers] = useState([]);
  const [currencies, setCurrencies] = useState([]);
  const [baseCurrencyCode, setBaseCurrencyCode] = useState("");
  const [shiftSessions, setShiftSessions] = useState([]);
  const [loadingLookups, setLoadingLookups] = useState(false);

  const normalizeCompanyBranches = (response) => {
    const payload = response?.data || response;

    return (
      payload?.company?.branches ||
      payload?.data?.company?.branches ||
      payload?.data?.branches ||
      payload?.branches ||
      []
    );
  };

  const loadLookups = async (nextFilters = {}) => {
    try {
      setLoadingLookups(true);

      const [companyRes, tillRes, currencyRes, cashierRes, shiftRes] =
        await Promise.all([
          getAuthorisedCompany(),
          getTills({
            per_page: 300,
            is_active: true,
            ...(nextFilters.branch_id ? { branch_id: nextFilters.branch_id } : {}),
          }),
          getCurrencies({
            per_page: 300,
            is_active: true,
          }),
          getPosUsers({
            per_page: 300,
            is_active: true,
            ...(nextFilters.branch_id ? { branch_id: nextFilters.branch_id } : {}),
          }),
          getShiftSessions({
            per_page: 300,
            ...(nextFilters.branch_id ? { branch_id: nextFilters.branch_id } : {}),
            ...(nextFilters.date_from ? { date_from: nextFilters.date_from } : {}),
            ...(nextFilters.date_to ? { date_to: nextFilters.date_to } : {}),
          }),
        ]);

      setBranches(normalizeCompanyBranches(companyRes));
      setTills(normalizeList(tillRes));
      const currencyRows = normalizeList(currencyRes);
      const baseCurrency = currencyRows.find(isBaseCurrency) || currencyRows[0] || null;
      setCurrencies(currencyRows);
      setBaseCurrencyCode(getCurrencyCode(baseCurrency));
      setCashiers(normalizeList(cashierRes));
      setShiftSessions(normalizeList(shiftRes));
    } catch {
      // Keep report usable if lookup endpoints fail.
    } finally {
      setLoadingLookups(false);
    }
  };

  useEffect(() => {
    if (open) loadLookups(filters || defaultFilters());
  }, [open]);

  const maps = useMemo(() => ({
    branchById: Object.fromEntries(branches.map((item) => [item.id, item])),
    tillById: Object.fromEntries(tills.map((item) => [item.id, item])),
    cashierById: Object.fromEntries(cashiers.map((item) => [item.id, item])),
    shiftById: Object.fromEntries(shiftSessions.map((item) => [item.id, item])),
  }), [branches, tills, cashiers, shiftSessions]);

  return {
    branches,
    tills,
    cashiers,
    currencies,
    baseCurrencyCode,
    shiftSessions,
    loadingLookups,
    loadLookups,
    ...maps,
  };
};

const pivotByCurrency = (rows = [], keyOf) => {
  const grouped = new Map();
  rows.forEach((row, index) => {
    const key = String(keyOf(row, index) ?? index);
    const currencyCode = String(row?.currency_code || "").toUpperCase();
    if (!grouped.has(key)) grouped.set(key, { first: row, rows: [], byCurrency: {} });
    const entry = grouped.get(key);
    entry.rows.push(row);
    if (currencyCode) entry.byCurrency[currencyCode] = row;
  });
  return [...grouped.values()];
};

const productColumnsForCurrencies = (currencyCodes = []) => [
  "Product", "Code", "SKU", "Category", "Receipts", "Lines", "Qty Sold",
  ...currencyCodes.flatMap((currencyCode) => [
    `${currencyCode} Avg Selling`, `${currencyCode} Min Selling`, `${currencyCode} Max Selling`,
    `${currencyCode} Avg Cost`, `${currencyCode} Sales`, `${currencyCode} Cost`,
    `${currencyCode} Gross Profit / Loss`, `${currencyCode} Margin %`,
  ]),
];

const productRowsForExport = (rows = [], currencyCodes = []) =>
  pivotByCurrency(rows, (row) => row.product_id || row.product_name).map((entry) => [
    entry.first.product_name,
    entry.first.product_code || "-",
    entry.first.sku || "-",
    entry.first.category_name || "-",
    entry.rows.reduce((sum, row) => sum + Number(row.receipt_count || 0), 0),
    entry.rows.reduce((sum, row) => sum + Number(row.line_count || 0), 0),
    number(entry.rows.reduce((sum, row) => sum + Number(row.quantity_sold || 0), 0), 3),
    ...currencyCodes.flatMap((currencyCode) => {
      const row = entry.byCurrency[currencyCode];
      return row ? [
        number(row.average_selling_price), number(row.minimum_selling_price), number(row.maximum_selling_price),
        number(row.average_cost), number(row.sales_value), number(row.cost_value), number(row.gross_profit),
        `${number(row.gross_margin_percent, 2)}%`,
      ] : ["-", "-", "-", "-", "-", "-", "-", "-"];
    }),
  ]);

const cashoutColumnsForCurrencies = (currencyCodes = []) => ["Date", "Type", "Count", ...currencyCodes.map((currencyCode) => `${currencyCode} Amount`)];
const cashoutByDateRowsForExport = (rows = [], currencyCodes = []) =>
  pivotByCurrency(rows, (row) => `${row.movement_date}|${row.movement_type}`).map((entry) => [
    entry.first.movement_date,
    entry.first.movement_type,
    entry.rows.reduce((sum, row) => sum + Number(row.movement_count || 0), 0),
    ...currencyCodes.map((currencyCode) => entry.byCurrency[currencyCode] ? number(entry.byCurrency[currencyCode].amount) : "-"),
  ]);

const ReportStatus = ({ loading, error }) => {
  if (loading) {
    return (
      <div className="mb-2 flex h-12 items-center justify-center border border-[#ACB8B8] bg-white text-[12px] font-semibold text-[#069495]">
        <RefreshCw className="mr-2 h-3.5 w-3.5 animate-spin" />
        Loading profit and loss report...
      </div>
    );
  }

  if (error) {
    return (
      <div className="mb-2 whitespace-pre-line border border-[#D92D20] bg-[#FFF4F3] p-3 text-[12px] font-semibold text-[#B42318]">
        {error}
      </div>
    );
  }

  return null;
};

const StatCard = ({ label, value, subValue, positive = false, danger = false }) => (
  <div className="border border-[#ACB8B8] bg-white p-3">
    <div className="text-[11px] font-semibold text-[#637877]">{label}</div>
    <div className={`mt-1 truncate text-[20px] font-semibold ${danger ? "text-[#D92D20]" : positive ? "text-[#057B7C]" : "text-[#003636]"}`}>
      {value}
    </div>
    {subValue && <div className="mt-1 text-[11px] font-normal text-[#637877]">{subValue}</div>}
  </div>
);

const ReportCard = ({ title, children }) => (
  <div className="overflow-hidden border border-[#ACB8B8] bg-white">
    <div className="flex min-h-9 items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2">
      <div className="text-[13px] font-semibold text-[#003636]">{title}</div>
    </div>
    <div className="overflow-x-auto">{children}</div>
  </div>
);

const DataTable = ({ columns, rows }) => {
  if (!rows || rows.length === 0) {
    return (
      <div className="flex min-h-[120px] items-center justify-center p-4 text-[12px] font-normal text-[#637877]">
        No records found.
      </div>
    );
  }

  return (
    <table className="w-full min-w-[1420px] border-collapse text-[12px]">
      <thead>
        <tr className="border-b border-[#ACB8B8] bg-[#F3F6F8]">
          {columns.map((column) => (
            <th key={column} className="whitespace-nowrap px-3 py-2 text-left text-[11px] font-semibold text-[#324444]">
              {column}
            </th>
          ))}
        </tr>
      </thead>

      <tbody>
        {rows.map((row, rowIndex) => (
          <tr key={rowIndex} className="border-b border-[#E3E9E9] hover:bg-[#F6F8FA]">
            {row.map((cell, cellIndex) => (
              <td key={cellIndex} className="whitespace-nowrap px-3 py-2 text-[12px] font-normal text-[#324443]">
                {cell ?? "-"}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
};

const Pagination = ({ meta, onPage }) => {
  if (!meta || meta.last_page <= 1) return null;

  return (
    <div className="flex items-center justify-between border-t border-[#ACB8B8] bg-[#F6F8FA] px-3 py-2">
      <div className="text-[11px] font-semibold text-[#637877]">
        Page {meta.current_page} of {meta.last_page} · {meta.total} records
      </div>

      <div className="flex gap-1">
        <button
          type="button"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
          className="border border-[#ACB8B8] bg-white px-3 py-1 text-[11px] font-semibold text-[#003636] disabled:opacity-50"
        >
          Previous
        </button>

        <button
          type="button"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
          className="border border-[#ACB8B8] bg-white px-3 py-1 text-[11px] font-semibold text-[#003636] disabled:opacity-50"
        >
          Next
        </button>
      </div>
    </div>
  );
};

export default function ProfitAndLossReport({
  open,
  onClose,
  lookups: externalLookups,
  businessModeLabel = "Hospitality",
  onNavigateReport,
}) {
  const [filters, setFilters] = useState(defaultFilters());
  const [loading, setLoading] = useState(false);
  const [payload, setPayload] = useState(null);
  const [error, setError] = useState("");
  const [generated, setGenerated] = useState(false);
  const [appliedFilters, setAppliedFilters] = useState(null);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserMode, setChooserMode] = useState("generate");
  const [exporting, setExporting] = useState(false);

  const localLookups = useProfitLossLookups(open, filters);
  const lookups = externalLookups || localLookups;

  useEffect(() => {
    if (!open || !lookups?.currencies?.length || filters.currency_codes.length) return;
    setFilters((current) => ({
      ...current,
      currency_code: "",
      currency_codes: lookups.currencies.map(getCurrencyCode).filter(Boolean),
      page: 1,
    }));
  }, [open, lookups?.currencies, filters.currency_codes.length]);

  const clearResults = () => {
    setGenerated(false);
    setAppliedFilters(null);
    setPayload(null);
  };

  const load = async (nextFilters = filters) => {
    const snapshot = { ...nextFilters, currency_codes: [...(nextFilters.currency_codes || [])] };
    try {
      setLoading(true);
      setError("");

      const res = await getProfitAndLossReport(snapshot);
      setPayload(getPayload(res));
      setAppliedFilters(snapshot);
      setGenerated(true);
      setChooserOpen(false);
      return true;
    } catch (err) {
      clearResults();
      setChooserOpen(false);
      setError(getErrorMessage(err, "Failed to load profit and loss report."));
      return false;
    } finally {
      setLoading(false);
    }
  };

  const exportReport = async (type) => {
    const snapshot = appliedFilters || {
      ...filters,
      currency_codes: [...(filters.currency_codes || [])],
    };
    try {
      setExporting(true);
      setError("");
      await downloadSalesReportExport("profit-loss", type, snapshot);
      setChooserOpen(false);
    } catch (exportError) {
      setChooserOpen(false);
      setError(getErrorMessage(exportError, "The backend could not generate this report."));
    } finally {
      setExporting(false);
    }
  };

  const openChooser = (mode = "generate") => {
    setChooserMode(mode);
    setChooserOpen(true);
  };

  useEffect(() => {
    if (!open) return;
    setPayload(null);
    setError("");
    setGenerated(false);
    setAppliedFilters(null);
    setChooserOpen(false);
  }, [open]);

  if (!open) return null;

  const summary = payload?.summary || {};
  const productSummaryResponse = payload?.product_summary || {};
  const productSummary = getPaginatedRows(productSummaryResponse);
  const productSummaryMeta = getPaginationMeta(productSummaryResponse);
  const cashoutSummary = payload?.cashouts?.summary || {};
  const cashoutByDate = payload?.cashouts?.by_date || [];
  const currency = summary.currency_code || summary.base_currency_code || "USD";
  const cashMovementCurrency = cashoutSummary.report_currency_code || currency;
  const cashMovementState = getCashMovementDisplayState(summary, cashoutSummary);
  const cashoutTotal = firstDefined(summary.total_cashouts, summary.cashout_total);
  const cashinTotal = firstDefined(summary.total_cashin, summary.cashin_total);
  const netCashMovement = firstDefined(summary.net_cash_movement, cashoutSummary.net_cash_movement);
  const netProfitAfterCashouts = summary.net_profit_after_cashouts;
  const netMarginAfterCashouts = summary.net_margin_after_cashouts_percent;
  const selectedCurrencyCodes = (appliedFilters || filters).currency_codes || [];
  const productCurrencySummary = Object.fromEntries(selectedCurrencyCodes.map((currencyCode) => {
    const currencyRows = productSummary.filter((row) => String(row.currency_code || "").toUpperCase() === currencyCode);
    const sales = currencyRows.reduce((sum, row) => sum + Number((row.money_received_value ?? row.sales_value) || 0), 0);
    const cost = currencyRows.reduce((sum, row) => sum + Number(row.cost_value || 0), 0);
    return [currencyCode, { sales, cost, profit: sales - cost }];
  }));
  const cashoutTypeRows = pivotByCurrency(cashoutSummary.summary_by_type_currency || [], (row) => row.movement_type);

  const reloadProductPage = async (page) => {
    const next = { ...(appliedFilters || filters), page };
    await load(next);
  };

  return (
    <FullScreenModal open={open} onClose={onClose} title="PROFIT & LOSS" subtitle="SERVENEST HOSPITALITY ENTERPRISE">
      <div className="flex h-full min-h-0 overflow-hidden bg-[#DFE5E5] text-[#122424] print:bg-white">
        <ManagementReportNavigator
          activeKey={MANAGEMENT_REPORT_KEYS.PROFIT_LOSS}
          onNavigate={onNavigateReport}
          businessModeLabel={businessModeLabel}
        />
        <div className="min-h-0 min-w-0 flex-1 overflow-hidden p-2">
        {!generated ? (
        <div className="h-full overflow-y-auto">
        <div className="mb-2 border border-[#ACB8B8] bg-white">
          <HospitalityReportScopePanel
            filters={filters}
            setFilters={(next) => {
              clearResults();
              setFilters(next);
            }}
            lookups={lookups}
            onApply={() => openChooser("generate")}
            loading={loading}
            businessModeLabel={businessModeLabel}
            supported={{
              branch_id: true,
              date_from: true,
              date_to: true,
              till_id: true,
              cashier_id: true,
              shift_session_id: true,
              currency_codes: true,
            }}
          />
        </div>

        {error && <ReportStatus loading={false} error={error} />}
        <ReportGenerationPrompt
          title="Configure Profit & Loss"
          message="Choose the branch, reporting period, till, cashier, shift and currencies, then select Generate."
        />
        </div>
        ) : (
        <ReportViewShell
          title="Profit & Loss"
          subtitle={`${appliedFilters?.date_from || ""} to ${appliedFilters?.date_to || ""}`}
          onBackToFilters={() => setGenerated(false)}
          busy={loading || exporting}
          actions={(
            <>
              <button
                type="button"
                onClick={() => load(appliedFilters)}
                disabled={loading || exporting}
                className="inline-flex h-8 items-center gap-1 border border-[#ACB8B8] bg-white px-3 text-[11px] font-semibold text-[#003636] hover:bg-[#F6F8FA] disabled:opacity-60"
              >
                <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />
                Refresh
              </button>
              <button
                type="button"
                onClick={() => openChooser("download")}
                disabled={loading || exporting}
                className="inline-flex h-8 items-center gap-1 border border-[#027A48] bg-white px-3 text-[11px] font-semibold text-[#027A48] disabled:opacity-60"
              >
                <Download className="h-3.5 w-3.5" />
                Download
              </button>
            </>
          )}
        >
        <div className="p-2">
        <ReportStatus loading={loading} error={error} />

        {(cashMovementState.message || cashMovementState.isMixedCurrency || cashMovementState.hasUnconvertedAmounts || !cashMovementState.currencyCompatible) && (
          <div
            role="status"
            className={`mb-2 border px-3 py-2 ${cashMovementState.hasUnconvertedAmounts || !cashMovementState.currencyCompatible ? "border-[#F79009] bg-[#FFFAEB] text-[#7A2E0E]" : "border-[#069494] bg-[#F1F8F8] text-[#057B7B]"}`}
          >
            <div className="text-[12px] font-semibold">Cash movement currency handling</div>
            <div className="mt-0.5 text-[11px]">
              {cashMovementState.message || "Cash movements contain more than one currency and are kept in separate currency totals."}
            </div>
            <div className="mt-1 flex flex-wrap gap-1 text-[10px] font-semibold uppercase tracking-wide">
              {cashMovementState.isMixedCurrency && <span className="border border-current px-1.5 py-0.5">Mixed currencies</span>}
              {cashMovementState.hasUnconvertedAmounts && <span className="border border-current px-1.5 py-0.5">Unconverted amounts</span>}
              {!cashMovementState.currencyCompatible && <span className="border border-current px-1.5 py-0.5">Per-currency totals only</span>}
              {Number(cashoutSummary.movement_count || 0) > 0 && <span className="border border-current px-1.5 py-0.5">{Number(cashoutSummary.movement_count)} movements</span>}
            </div>
          </div>
        )}

        <div className="space-y-2">
          <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-5">
            <StatCard label="Products" value={summary.product_count || 0} />
            <StatCard label="Qty Sold" value={number(summary.quantity_sold, 3)} />
            <StatCard label="Total Sales" value={money(summary.total_sales || summary.sales_value, currency)} positive />
            <StatCard label="Total Cost" value={money(summary.total_cost || summary.cost_value, currency)} />
            <StatCard
              label="Gross Profit / Loss"
              value={money(summary.gross_profit, currency)}
              positive={Number(summary.gross_profit || 0) >= 0}
              danger={Number(summary.gross_profit || 0) < 0}
            />
            <StatCard label="Total Cashouts" value={nullableMoney(cashoutTotal, cashMovementCurrency, cashMovementState.perCurrency)} danger={isNumericValue(cashoutTotal) && Number(cashoutTotal) > 0} />
            <StatCard label="Total Cash In" value={nullableMoney(cashinTotal, cashMovementCurrency, cashMovementState.perCurrency)} positive={isNumericValue(cashinTotal) && Number(cashinTotal) > 0} />
            <StatCard
              label="Net Cash Movement"
              value={nullableMoney(netCashMovement, cashMovementCurrency, cashMovementState.perCurrency)}
              positive={isNumericValue(netCashMovement) && Number(netCashMovement) >= 0}
              danger={isNumericValue(netCashMovement) && Number(netCashMovement) < 0}
            />
            <StatCard
              label="Net Profit / Loss"
              value={nullableMoney(netProfitAfterCashouts, currency, cashMovementState.perCurrency)}
              positive={isNumericValue(netProfitAfterCashouts) && Number(netProfitAfterCashouts) >= 0}
              danger={isNumericValue(netProfitAfterCashouts) && Number(netProfitAfterCashouts) < 0}
            />
            <StatCard label="Gross Margin" value={`${number(summary.gross_margin_percent, 2)}%`} />
            <StatCard label="Net Margin" value={nullablePercent(netMarginAfterCashouts, cashMovementState.perCurrency)} />
          </div>

          <ReportCard title="Executive Profit & Loss Summary">
            <DataTable
              columns={["Metric", ...selectedCurrencyCodes]}
              rows={[
                ["Sales", ...selectedCurrencyCodes.map((currencyCode) => number(productCurrencySummary[currencyCode]?.sales))],
                ["Cost of Sales", ...selectedCurrencyCodes.map((currencyCode) => number(productCurrencySummary[currencyCode]?.cost))],
                ["Gross Profit / Loss", ...selectedCurrencyCodes.map((currencyCode) => number(productCurrencySummary[currencyCode]?.profit))],
              ]}
            />
          </ReportCard>

          <ReportCard title={`Product Profit & Loss Summary (${productSummaryMeta.total})`}>
            <DataTable
              columns={productColumnsForCurrencies(selectedCurrencyCodes)}
              rows={productRowsForExport(productSummary, selectedCurrencyCodes)}
            />
            <Pagination meta={productSummaryMeta} onPage={reloadProductPage} />
          </ReportCard>

          <ReportCard title="Cashout Summary by Type and Currency">
            <DataTable
              columns={["Type", "Count", ...selectedCurrencyCodes.map((currencyCode) => `${currencyCode} Amount`)]}
              rows={cashoutTypeRows.map((entry) => [entry.first.movement_type, entry.rows.reduce((sum, row) => sum + Number(row.movement_count || 0), 0), ...selectedCurrencyCodes.map((currencyCode) => entry.byCurrency[currencyCode] ? number(entry.byCurrency[currencyCode].amount) : "-")])}
            />
          </ReportCard>

          <ReportCard title="Cashouts by Date">
            <DataTable
              columns={cashoutColumnsForCurrencies(selectedCurrencyCodes)}
              rows={cashoutByDateRowsForExport(cashoutByDate, selectedCurrencyCodes)}
            />
          </ReportCard>
        </div>
        </div>
        </ReportViewShell>
        )}
        </div>
      </div>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle="Profit & Loss"
        description={chooserMode === "download" ? "Choose a backend-generated download format." : "View the report or download it directly from the backend."}
        busy={loading || exporting}
        onClose={() => setChooserOpen(false)}
        onView={chooserMode === "generate" ? () => load(filters) : undefined}
        onDownloadPdf={() => exportReport("pdf")}
        onDownloadExcel={() => exportReport("excel")}
        outputs={chooserMode === "download" ? { view: false } : undefined}
      />
    </FullScreenModal>
  );
}
