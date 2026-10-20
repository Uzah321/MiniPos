// src/backOffice/pages/report/PosReportsModal.jsx

import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  Box,
  List,
  ListItemButton,
  ListItemText,
  Typography,
} from "@mui/material";
import {
  Download,
  RefreshCw,
  X,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import { getAuthorisedCompany, getPosUsers } from "../../../services/AuthenticationService";
import { getCurrencies } from "../../../services/CurrencyService";
import { getTills } from "../../../services/TillService";
import { getShiftSessions } from "../../../services/ShiftService";
import { getCurrentTradingDay } from "../../../services/TradingDayEndEnterpriseService";
import {
  getSalesDashboard,
  getProfitAndLossReport,
  getSalesReport,
  getSaleDrilldown,
  getPaymentReport,
  getProductSalesReport,
  getProductDrilldown,
  getCashierSalesReport,
  getBranchTurnoverReport,
  getShiftSalesReport,
  getDayEndsReport,
  getDailySalesReport,
  downloadSalesReportExport,
} from "../../../services/SalesReportService";
import {
  getCashupHistory,
  getCashoutReport,
  downloadCashupHistoryExport,
  downloadCashoutReportExport,
} from "../../../services/CashupService";
import { canViewCashupFinancialAmounts } from "../../../utils/CashupPermissions";

const REPORTS = [
  ["profit-loss", "Management Overview", "Turnover, cost of sales, margin and operating result"],
  ["dashboard", "Sales Performance", "Trading activity, currency mix and top products"],
  ["branch-turnover", "Branch Turnover", "Sales, cost and profit for every accessible branch"],
  ["daily-sales", "Daily Trading Summary", "One trading day with product and receipt detail"],
  ["sales-list", "Sales Register", "Auditable receipt-level sales, cost and profit"],
  ["payments", "Tender & Payments", "Payment methods, currencies, tendered and change"],
  ["products", "Product Profitability", "Quantity, revenue, cost, gross profit and margin"],
  ["cashiers", "Cashier Performance", "Sales and transaction totals by cashier"],
  ["shift-sales", "Shift Performance", "Trading and control totals by shift"],
  ["cashups", "Cashup Control", "Expected, counted and variance history"],
  ["cashouts", "Cash Movement", "Operating cash in and cash out movements"],
  ["day-ends", "Day-End Audit", "Trading-day closure and reconciliation evidence"],
];

const REPORT_META = Object.fromEntries(REPORTS.map(([key, title, description]) => [key, { key, title, description }]));
const today = () => new Date().toISOString().slice(0, 10);
const code = (value, fallback = "") => String(value || fallback).trim().toUpperCase();
const HOSPITALITY_BUSINESS_MODE = "fast_food_boh";
const isTrue = (value) => value === true || value === 1 || value === "1" || String(value).toLowerCase() === "true";
const number = (value, decimals = 2) => Number(value || 0).toFixed(decimals);
const money = (value, currency = "") => `${currency ? `${currency} ` : ""}${number(value)}`;
export const reportTillLabel = (row = {}) => {
  const tillCode = String(row.till_code || row.code || "").trim();
  const tillName = String(row.till_name || row.name || "").trim();

  if (tillCode && tillName && tillCode.toLowerCase() !== tillName.toLowerCase()) {
    return `${tillCode} - ${tillName}`;
  }

  return tillCode || tillName || "-";
};

export const resolveAuthoritativeTradingDate = async (
  branchId = "",
  loadCurrentTradingDay = getCurrentTradingDay
) => {
  const day = await loadCurrentTradingDay(branchId ? { branch_id: branchId } : {});
  const tradingDay = day?.data?.data || day?.data || day || {};
  const tradingDate = tradingDay.trading_date || tradingDay.current_trading_date || "";

  if (!tradingDate) {
    throw new Error(
      tradingDay.sale_block_reason || "No authoritative Hospitality trading date is open for this branch."
    );
  }

  return tradingDate;
};

export const applyAuthoritativeTradingDate = (filters = {}, tradingDate = "") => ({
  ...filters,
  business_date: tradingDate,
  date_from: tradingDate,
  date_to: tradingDate,
});
const jsonObject = (value) => {
  if (value && typeof value === "object" && !Array.isArray(value)) return value;
  if (typeof value !== "string" || !value.trim()) return {};
  try {
    const decoded = JSON.parse(value);
    return decoded && typeof decoded === "object" && !Array.isArray(decoded) ? decoded : {};
  } catch {
    return {};
  }
};
const jsonArray = (value) => {
  if (Array.isArray(value)) return value;
  if (typeof value !== "string" || !value.trim()) return [];
  try {
    const decoded = JSON.parse(value);
    return Array.isArray(decoded) ? decoded : [];
  } catch {
    return [];
  }
};
const accountCreditRows = (row) => {
  const salesSummary = jsonObject(row?.sales_summary || row?.cashup_sales_summary);
  const credit = jsonObject(salesSummary.credit);
  const tenderRows = jsonArray(credit.credit_sale_tender_rows);
  if (tenderRows.length) return tenderRows;
  return jsonArray(credit.sales_by_currency).map((entry) => ({
    ...entry,
    payment_name: entry.payment_name || "Customer Account",
    money_code: entry.money_code || "customer_credit",
    payment_count: entry.payment_count ?? entry.transaction_count ?? entry.sale_count ?? 0,
    amount: entry.amount ?? entry.sales_value ?? entry.credit_value ?? 0,
  }));
};

export const defaultFilters = ({ branchId = "", tradingDate = "", useTradingDate = false } = {}) => {
  const selectedDate = useTradingDate ? tradingDate : (tradingDate || today());
  return {
    date_from: useTradingDate ? selectedDate : today(),
    date_to: useTradingDate ? selectedDate : today(),
    business_date: selectedDate,
    branch_id: branchId,
    till_id: "",
    cashier_id: "",
    shift_session_id: "",
    currency_code: "",
    currency_codes: [],
    business_mode: HOSPITALITY_BUSINESS_MODE,
    per_page: 500,
    page: 1,
  };
};

const snapshotFilters = (source = {}) => ({
  ...source,
  currency_codes: Array.isArray(source.currency_codes) ? [...source.currency_codes] : [],
});

const getPayload = (response) => response?.data || response || {};
const getRows = (response) => {
  const payload = getPayload(response);
  const data = payload?.data || payload;
  if (Array.isArray(data?.data)) return data.data;
  if (Array.isArray(data)) return data;
  return [];
};
const getMeta = (response) => {
  const payload = getPayload(response);
  const data = payload?.data || payload;
  return {
    current_page: Number(data?.current_page || 1),
    last_page: Number(data?.last_page || 1),
    per_page: Number(data?.per_page || 50),
    total: Number(data?.total || (Array.isArray(data) ? data.length : 0)),
  };
};
const summaryOf = (response) => response?.summary || response?.data?.summary || response?.data?.data?.summary || response?.data?.base_summary || response?.base_summary || {};
const listOf = (response) => {
  const payload = response?.data || response || [];
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.company?.branches)) return payload.company.branches;
  if (Array.isArray(payload?.branches)) return payload.branches;
  if (Array.isArray(payload)) return payload;
  return [];
};
const formatDateTime = (value) => {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return new Intl.DateTimeFormat("en-ZW", { year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" }).format(date);
};
const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  if (data?.errors && typeof data.errors === "object") return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

const reportCurrencyCodes = (preferred = [], rows = []) => {
  const values = [
    ...(Array.isArray(preferred) ? preferred : []),
    ...(Array.isArray(rows) ? rows.map((row) => row?.currency_code || row?.receipt_currency || row?.base_currency_code) : []),
  ];
  return [...new Set(values.map((value) => code(value)).filter(Boolean))];
};

export const cashMovementKpiRows = ({
  summary = {},
  rows = [],
  currencyCodes = [],
  total = rows.length,
  canViewAmounts = true,
} = {}) => {
  const totalsByCurrency = Array.isArray(summary?.totals_by_currency) ? summary.totals_by_currency : [];
  const codes = reportCurrencyCodes(currencyCodes, [...totalsByCurrency, ...rows]);
  const cashOutTypes = ["cash_out", "bank_drop", "paid_out", "petty_cash", "float_out"];
  const cashInTypes = ["cash_in", "float_in"];

  return [["Movements", summary?.movement_count ?? total], ...(canViewAmounts ? codes.flatMap((currencyCode) => {
    const totals = totalsByCurrency.find((row) => code(row.currency_code) === currencyCode);
    const currencyRows = rows.filter((row) => code(row.currency_code) === currencyCode);
    const pageCashOut = currencyRows.filter((row) => cashOutTypes.includes(row.movement_type)).reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const pageCashIn = currencyRows.filter((row) => cashInTypes.includes(row.movement_type)).reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const cashOut = totals?.cashout_total ?? pageCashOut;
    const cashIn = totals?.cashin_total ?? pageCashIn;
    const net = totals?.net_cash_movement ?? (cashIn - cashOut);

    return [[`${currencyCode} Cash Out`, money(cashOut, currencyCode)], [`${currencyCode} Cash In`, money(cashIn, currencyCode)], [`${currencyCode} Net`, money(net, currencyCode)]];
  }) : [])];
};

const pivotCurrencyRows = (rows = [], keyOf) => {
  const grouped = new Map();
  rows.forEach((row, index) => {
    const key = String(keyOf(row, index) ?? index);
    const currency = code(row?.currency_code || row?.receipt_currency || row?.base_currency_code);
    if (!grouped.has(key)) grouped.set(key, { key, first: row, rows: [], byCurrency: {} });
    const entry = grouped.get(key);
    entry.rows.push(row);
    if (currency) entry.byCurrency[currency] = row;
  });
  return [...grouped.values()];
};

const currencyAmount = (entry, currency, field, fallbackField = "") => {
  const row = entry?.byCurrency?.[currency];
  if (!row) return "-";
  const value = row?.[field] ?? (fallbackField ? row?.[fallbackField] : 0);
  return number(value);
};

const currencyAmountForRow = (row, currency, field, fallbackField = "") => (
  code(row?.currency_code || row?.receipt_currency) === currency
    ? number(row?.[field] ?? (fallbackField ? row?.[fallbackField] : 0))
    : "-"
);

function useReportLookups(open) {
  const [lookups, setLookups] = useState({ branches: [], tills: [], cashiers: [], currencies: [], shifts: [], baseCurrencyCode: "" });

  const load = useCallback(async (filters = {}) => {
    const [company, tills, currencies, users, shifts] = await Promise.allSettled([
      getAuthorisedCompany(),
      getTills({ per_page: 300, is_active: true, ...(filters.branch_id ? { branch_id: filters.branch_id } : {}) }),
      getCurrencies({ per_page: 300, is_active: true }),
      getPosUsers({ per_page: 300, is_active: true, ...(filters.branch_id ? { branch_id: filters.branch_id } : {}) }),
      getShiftSessions({ per_page: 300, ...(filters.branch_id ? { branch_id: filters.branch_id } : {}), ...(filters.date_from ? { date_from: filters.date_from } : {}), ...(filters.date_to ? { date_to: filters.date_to } : {}) }),
    ]);

    const companyPayload = company.status === "fulfilled" ? company.value?.data || company.value || {} : {};
    const currencyRows = currencies.status === "fulfilled" ? listOf(currencies.value) : [];
    const baseCurrency = currencyRows.find((row) => isTrue(row?.is_base_currency)) || currencyRows[0] || null;
    setLookups({
      branches: companyPayload?.company?.branches || companyPayload?.branches || companyPayload?.data?.branches || [],
      tills: tills.status === "fulfilled" ? listOf(tills.value) : [],
      currencies: currencyRows,
      baseCurrencyCode: code(baseCurrency?.code || baseCurrency?.currency_code),
      cashiers: users.status === "fulfilled" ? listOf(users.value) : [],
      shifts: shifts.status === "fulfilled" ? listOf(shifts.value) : [],
    });
  }, []);

  useEffect(() => { if (open) load(defaultFilters()); }, [open, load]);
  return { ...lookups, load };
}

const REPORT_LOADERS = {
  "profit-loss": getProfitAndLossReport,
  dashboard: getSalesDashboard,
  "daily-sales": getDailySalesReport,
  "sales-list": getSalesReport,
  payments: getPaymentReport,
  products: getProductSalesReport,
  cashiers: getCashierSalesReport,
  "branch-turnover": getBranchTurnoverReport,
  cashups: getCashupHistory,
  cashouts: getCashoutReport,
  "shift-sales": getShiftSalesReport,
  "day-ends": getDayEndsReport,
};

export default function PosReportsModal({
  open,
  onClose,
  initialReport = "dashboard",
  branchId = "",
  tradingDate = "",
  useTradingDate = false,
  allowedReports = null,
  title = "SALES REPORTS",
}) {
  const visibleReports = useMemo(() => {
    if (!Array.isArray(allowedReports) || allowedReports.length === 0) return REPORTS;
    const allowed = new Set(allowedReports);
    return REPORTS.filter(([key]) => allowed.has(key));
  }, [allowedReports]);
  const firstReport = visibleReports[0]?.[0] || "dashboard";
  const safeInitialReport = visibleReports.some(([key]) => key === initialReport) ? initialReport : firstReport;
  const [activeReport, setActiveReport] = useState(safeInitialReport || "dashboard");
  const [filters, setFilters] = useState(defaultFilters({ branchId, tradingDate, useTradingDate }));
  const [response, setResponse] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [viewing, setViewing] = useState(false);
  const [drilldown, setDrilldown] = useState(null);
  const [exporting, setExporting] = useState(false);
  const [downloadOpen, setDownloadOpen] = useState(false);
  const [chooserSource, setChooserSource] = useState("configuration");
  const [outputFilters, setOutputFilters] = useState(null);
  const [appliedFilters, setAppliedFilters] = useState(null);
  const lookups = useReportLookups(open);
  const loadReportLookups = lookups.load;

  useEffect(() => {
    if (!open || !lookups.currencies.length || filters.currency_codes.length) return;
    const allCurrencyCodes = lookups.currencies
      .map((row) => code(row.code || row.currency_code))
      .filter(Boolean);
    setFilters((current) => ({
      ...current,
      currency_code: "",
      currency_codes: allCurrencyCodes,
      page: 1,
    }));
  }, [open, lookups.currencies, filters.currency_codes.length]);

  const meta = REPORT_META[activeReport] || REPORT_META.dashboard;

  useEffect(() => {
    if (!open) return;
    let active = true;
    const nextFilters = defaultFilters({ branchId, tradingDate, useTradingDate });
    setFilters(nextFilters);
    setActiveReport(safeInitialReport || "dashboard");
    setResponse(null);
    setViewing(false);
    setAppliedFilters(null);
    setOutputFilters(null);
    setDownloadOpen(false);
    if (useTradingDate) {
      if (!nextFilters.branch_id) {
        setFilters(applyAuthoritativeTradingDate(nextFilters, ""));
        setLoading(false);
        setError("Select a Hospitality branch to resolve its authoritative trading date.");
        return () => { active = false; };
      }
      setLoading(true);
      setError("");
      resolveAuthoritativeTradingDate(nextFilters.branch_id)
        .then((resolvedDate) => {
          if (!active) return;
          const resolvedFilters = applyAuthoritativeTradingDate(nextFilters, resolvedDate);
          setFilters(resolvedFilters);
          loadReportLookups(resolvedFilters);
        })
        .catch((requestError) => {
          if (!active) return;
          setFilters(applyAuthoritativeTradingDate(nextFilters, ""));
          setError(getErrorMessage(requestError, "Failed to resolve the current Hospitality trading date."));
        })
        .finally(() => {
          if (active) setLoading(false);
        });
    }

    return () => { active = false; };
  }, [open, initialReport, branchId, tradingDate, useTradingDate, safeInitialReport, loadReportLookups]);

  const loadReport = async (reportKey = activeReport, nextFilters = filters) => {
    try {
      setLoading(true);
      setError("");
      setDrilldown(null);
      setResponse(null);
      const loader = REPORT_LOADERS[reportKey] || getSalesDashboard;
      const reportDate = nextFilters.business_date || nextFilters.date_from;
      const isRangeTradingReport = reportKey === "cashups";
      const params = {
        ...nextFilters,
        business_mode: HOSPITALITY_BUSINESS_MODE,
        ...(useTradingDate && !isRangeTradingReport ? { business_date: reportDate, date_from: reportDate, date_to: reportDate } : {}),
        ...(useTradingDate && isRangeTradingReport ? {
          business_date_from: nextFilters.date_from,
          business_date_to: nextFilters.date_to,
          trading_date_from: nextFilters.date_from,
          trading_date_to: nextFilters.date_to,
        } : {}),
        ...(reportKey === "daily-sales" ? { business_date: reportDate } : {}),
      };
      const res = await loader(params);
      setResponse(res);
      return true;
    } catch (err) {
      setResponse(null);
      setError(getErrorMessage(err, "Failed to load report."));
      return false;
    } finally {
      setLoading(false);
    }
  };

  const updateFilter = async (key, value) => {
    const next = { ...filters, [key]: value, page: key === "page" ? value : 1 };
    if (key === "business_date") {
      next.date_from = value;
      next.date_to = value;
    }
    if (key === "branch_id") {
      next.till_id = "";
      next.cashier_id = "";
      next.shift_session_id = "";
      if (useTradingDate) {
        if (!value) {
          Object.assign(next, applyAuthoritativeTradingDate(next, ""));
          setError("Select a Hospitality branch to resolve its authoritative trading date.");
        } else {
          try {
            setLoading(true);
            setError("");
            const resolvedDate = await resolveAuthoritativeTradingDate(value);
            Object.assign(next, applyAuthoritativeTradingDate(next, resolvedDate));
          } catch (requestError) {
            Object.assign(next, applyAuthoritativeTradingDate(next, ""));
            setError(getErrorMessage(requestError, "Failed to resolve the selected branch trading date."));
          } finally {
            setLoading(false);
          }
        }
      }
      await loadReportLookups(next);
    }
    if (key === "date_from" || key === "date_to") await loadReportLookups(next);
    setFilters(next);
    setViewing(false);
  };

  const changeReport = (key) => {
    setActiveReport(key);
    const next = { ...filters, page: 1 };
    setFilters(next);
    setResponse(null);
    setError("");
    setViewing(false);
    setAppliedFilters(null);
    setOutputFilters(null);
    setDownloadOpen(false);
  };

  const openDownloadModal = (source = "configuration") => {
    const nextFilters = source === "result" && appliedFilters
      ? snapshotFilters(appliedFilters)
      : snapshotFilters(filters);
    setChooserSource(source);
    setOutputFilters(nextFilters);
    setDownloadOpen(true);
  };

  const viewReport = async () => {
    const nextFilters = snapshotFilters(outputFilters || filters);
    setDownloadOpen(false);
    setViewing(false);
    const loaded = await loadReport(activeReport, nextFilters);
    if (loaded) {
      setAppliedFilters(nextFilters);
      setViewing(true);
    }
  };

  const exportReport = async (format) => {
    try {
      setExporting(true);
      setError("");
      const selectedFilters = outputFilters || appliedFilters || filters;
      const params = { ...snapshotFilters(selectedFilters), business_mode: HOSPITALITY_BUSINESS_MODE, export_limit: 10000 };
      if (activeReport === "cashups") await downloadCashupHistoryExport(format, params);
      else if (activeReport === "cashouts") await downloadCashoutReportExport(format, params);
      else await downloadSalesReportExport(activeReport, format, params);
      setDownloadOpen(false);
    } catch (err) {
      setError(getErrorMessage(err, "Failed to download report."));
      setDownloadOpen(false);
    } finally {
      setExporting(false);
    }
  };

  const pageTo = (page) => {
    const next = { ...(appliedFilters || filters), page };
    setFilters(next);
    setAppliedFilters(snapshotFilters(next));
    loadReport(activeReport, next);
  };

  const refreshReport = () =>
    loadReport(activeReport, snapshotFilters(appliedFilters || filters));

  const openSale = async (saleId) => {
    try {
      setLoading(true);
      const res = await getSaleDrilldown(saleId);
      setDrilldown({ title: "Sale Detail", payload: getPayload(res) });
    } catch (err) {
      setError(getErrorMessage(err, "Failed to open sale."));
    } finally {
      setLoading(false);
    }
  };

  const openProduct = async (productId, productName) => {
    try {
      setLoading(true);
      const res = await getProductDrilldown(productId, appliedFilters || filters);
      setDrilldown({ title: `Product Receipts - ${productName}`, payload: { rows: getRows(res), meta: getMeta(res) } });
    } catch (err) {
      setError(getErrorMessage(err, "Failed to open product."));
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal open={open} onClose={onClose} title={title} subtitle={meta.title}>
      <div className="relative flex h-full min-h-0 bg-white text-[#293232]">
        <aside className="w-[350px] shrink-0 border-r border-[#D8DCDC] bg-white">
          <div className="flex h-14 items-center border-b border-[#D8DCDC] px-5 text-[15px] font-medium text-[#293232]">Report list</div>
          <div className="h-[calc(100%-56px)] overflow-y-auto">
            <ReportCatalogue reports={visibleReports} activeReport={activeReport} onSelect={changeReport} />
          </div>
        </aside>

        <section className="flex min-w-0 flex-1 flex-col bg-white">
          {!viewing && error && <div className="whitespace-pre-line border-b border-[#FDA29B] bg-[#FEF3F2] px-5 py-2 text-[12px] text-[#B42318]">{error}</div>}
          {!viewing && loading && <div className="border-b border-[#D8DCDC] bg-white px-5 py-2 text-[12px] text-[#008585]">Loading report…</div>}

          {viewing ? (
            <ReportViewShell
              title={meta.title}
              subtitle={meta.description}
              backLabel="Configuration / Back"
              onBackToFilters={() => setViewing(false)}
              busy={loading}
              actions={<>
                <button type="button" onClick={refreshReport} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#B9C0C0] bg-white px-3 text-[12px] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
                <button type="button" onClick={() => openDownloadModal("result")} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#069494] bg-white px-3 text-[12px] text-[#008585] disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
              </>}
            >
              {error && <div className="whitespace-pre-line border-b border-[#FDA29B] bg-[#FEF3F2] px-5 py-2 text-[12px] text-[#B42318]">{error}</div>}
              {loading && <div className="border-b border-[#D8DCDC] bg-white px-5 py-2 text-[12px] text-[#008585]">Loading report…</div>}
              <div className="p-4"><ReportBody activeReport={activeReport} response={response} lookups={lookups} currencyCodes={(appliedFilters || filters).currency_codes} onPage={pageTo} onSale={openSale} onProduct={openProduct} /></div>
            </ReportViewShell>
          ) : (
            <ReportConfiguration activeReport={activeReport} filters={filters} updateFilter={updateFilter} lookups={lookups} useTradingDate={useTradingDate} onGenerate={() => openDownloadModal("configuration")} disabled={exporting || loading || (useTradingDate && (!filters.branch_id || !filters.date_from || !filters.date_to))} />
          )}

        {drilldown && <DrilldownPanel drilldown={drilldown} onClose={() => setDrilldown(null)} />}
        <ReportOutputChooser
          open={downloadOpen}
          reportTitle={meta.title}
          busy={exporting}
          busyLabel="Preparing report download…"
          onClose={() => setDownloadOpen(false)}
          onView={viewReport}
          onDownloadPdf={() => exportReport("pdf")}
          onDownloadExcel={() => exportReport("excel")}
          outputs={chooserSource === "result" ? { view: { disabled: true, reason: "This report is already open." } } : undefined}
        />

        <style>{`
          .report-input { height: 38px; width: 100%; border: 1px solid #B9C0C0; background: white; padding: 0 10px; font-size: 13px; color: #293232; outline: none; }
          .report-input:focus { border-color: #069495; }
          @media print { button, .no-print { display: none !important; } }
        `}</style>
        </section>
      </div>
    </FullScreenModal>
  );
}

const REPORT_GROUPS = [
  { title: "Management", keys: ["profit-loss", "dashboard", "branch-turnover"] },
  { title: "Sales reports", keys: ["daily-sales", "sales-list", "payments", "products", "cashiers", "shift-sales"] },
  { title: "Cash management", keys: ["cashups", "cashouts"] },
  { title: "Audit reports", keys: ["day-ends"] },
];

function ReportCatalogue({ reports, activeReport, onSelect }) {
  const available = new Map(reports);
  return <List disablePadding>
    {REPORT_GROUPS.map((group) => {
      const rows = group.keys.filter((key) => available.has(key));
      if (!rows.length) return null;
      return <Box key={group.title} sx={{ borderBottom: "1px solid", borderColor: "divider" }}>
        <Typography component="div" sx={{ height: 44, display: "flex", alignItems: "center", px: 2.5, bgcolor: "#f7f8f9", fontSize: 13, fontWeight: 500, color: "#485353" }}>{group.title}</Typography>
        {rows.map((key) => <ListItemButton key={key} selected={activeReport === key} onClick={() => onSelect(key)} sx={{ minHeight: 54, px: 3, borderLeft: "2px solid", borderLeftColor: activeReport === key ? "primary.main" : "transparent", "&.Mui-selected": { bgcolor: "primary.light", color: "primary.dark" }, "&.Mui-selected:hover": { bgcolor: "primary.light" } }}><Typography sx={{ mr: 1.5, color: "text.secondary" }}>›</Typography><ListItemText primary={available.get(key)} secondary={REPORT_META[key]?.description} primaryTypographyProps={{ fontSize: 13 }} secondaryTypographyProps={{ fontSize: 10, noWrap: true }} /></ListItemButton>)}
      </Box>;
    })}
  </List>;
}

function ReportConfiguration({ activeReport, filters, updateFilter, lookups, useTradingDate, onGenerate, disabled }) {
  const singleTradingDate = activeReport === "daily-sales" || (useTradingDate && activeReport !== "cashups");
  return <div className="flex min-h-0 flex-1 flex-col">
    <div className="min-h-0 flex-1 overflow-y-auto p-6">
      <div className="grid max-w-[900px] grid-cols-1 gap-px border border-[#D8DCDC] bg-[#D8DCDC] md:grid-cols-2">
        <ConfigCell label="Branch"><select value={filters.branch_id} onChange={(event) => updateFilter("branch_id", event.target.value)} className="report-input"><option value="">{useTradingDate ? "Select branch" : "All branches"}</option>{lookups.branches.map((row) => <option key={row.id} value={row.id}>{row.branch_name || row.name || row.code || "Branch"}</option>)}</select></ConfigCell>
        {singleTradingDate ? <ConfigCell label="Trading date"><input type="date" value={filters.business_date} readOnly={useTradingDate} disabled={useTradingDate} onChange={(event) => updateFilter("business_date", event.target.value)} className="report-input disabled:bg-[#F7F8F9]" /></ConfigCell> : <ConfigCell label="Period"><div className="grid grid-cols-2 gap-2"><input aria-label="Date from" type="date" value={filters.date_from} onChange={(event) => updateFilter("date_from", event.target.value)} className="report-input" /><input aria-label="Date to" type="date" value={filters.date_to} onChange={(event) => updateFilter("date_to", event.target.value)} className="report-input" /></div></ConfigCell>}
        <ConfigCell label="Till"><select value={filters.till_id} onChange={(event) => updateFilter("till_id", event.target.value)} className="report-input"><option value="">All tills</option>{lookups.tills.map((row) => <option key={row.id} value={row.id}>{reportTillLabel(row)}</option>)}</select></ConfigCell>
        <ConfigCell label="Cashier"><select value={filters.cashier_id} onChange={(event) => updateFilter("cashier_id", event.target.value)} className="report-input"><option value="">All cashiers</option>{lookups.cashiers.map((row) => <option key={row.id} value={row.id}>{row.name || row.username || row.email}</option>)}</select></ConfigCell>
        <ConfigCell label="Shift"><select value={filters.shift_session_id} onChange={(event) => updateFilter("shift_session_id", event.target.value)} className="report-input"><option value="">All shifts</option>{lookups.shifts.map((row) => <option key={row.id} value={row.id}>{row.shift_name || "Shift"} / {formatDateTime(row.opened_at)}</option>)}</select></ConfigCell>
        <ConfigCell label="Currencies"><CurrencyChecklist currencies={lookups.currencies} selected={filters.currency_codes} onChange={(value) => updateFilter("currency_codes", value)} /></ConfigCell>
        <ConfigCell label="Business mode"><div className="flex h-[38px] items-center border border-[#D8DCDC] bg-[#F7F8F9] px-3 text-[13px] text-[#4D5756]">Hospitality</div></ConfigCell>
      </div>
    </div>
    <div className="flex h-16 shrink-0 items-center justify-end border-t border-[#D8DCDC] px-6"><button type="button" disabled={disabled} onClick={onGenerate} className="h-9 border border-[#069494] bg-white px-6 text-[13px] font-medium text-[#008585] hover:bg-[#F2F8F8] disabled:opacity-50">Generate</button></div>
  </div>;
}

function ConfigCell({ label, children }) {
  return <div className="min-h-[98px] bg-white p-4"><div className="mb-2 text-[13px] font-medium text-[#323B3A]">{label}</div>{children}</div>;
}

function CurrencyChecklist({ currencies = [], selected = [], onChange }) {
  const codes = currencies.map((row) => code(row.code || row.currency_code)).filter(Boolean);
  const selectedSet = new Set((selected || []).map((value) => code(value)).filter(Boolean));
  const allSelected = codes.length > 0 && codes.every((value) => selectedSet.has(value));

  const toggle = (currencyCode) => {
    const next = new Set(selectedSet);
    if (next.has(currencyCode)) next.delete(currencyCode);
    else next.add(currencyCode);
    if (next.size === 0) return;
    onChange(codes.filter((value) => next.has(value)));
  };

  if (!codes.length) return <div className="text-[12px] text-[#7C8685]">No active currency</div>;

  return <div className="border border-[#B9C0C0] bg-white">
    <label className="flex cursor-pointer items-center gap-2 border-b border-[#D8DCDC] bg-[#F7F8F9] px-2 py-1.5 text-[11px] font-semibold">
      <input type="checkbox" checked={allSelected} onChange={() => !allSelected && onChange(codes)} />
      All currencies
    </label>
    <div className="grid grid-cols-2 gap-x-3 gap-y-1 p-2 sm:grid-cols-3">
      {currencies.map((row) => {
        const currencyCode = code(row.code || row.currency_code);
        return <label key={row.id || currencyCode} className="flex cursor-pointer items-center gap-2 text-[11px]">
          <input type="checkbox" checked={selectedSet.has(currencyCode)} onChange={() => toggle(currencyCode)} />
          <span>{currencyCode}{isTrue(row.is_base_currency) ? " · Base" : ""}</span>
        </label>;
      })}
    </div>
  </div>;
}


function ReportBody({ activeReport, response, lookups, currencyCodes, onPage, onSale, onProduct }) {
  if (activeReport === "profit-loss") return <ManagementOverviewReport response={response} currencyCodes={currencyCodes} />;
  if (activeReport === "dashboard") return <Dashboard response={response} currencyCodes={currencyCodes} />;
  if (activeReport === "daily-sales") return <DailySales response={response} currencyCodes={currencyCodes} onPage={onPage} />;
  if (activeReport === "sales-list") return <SalesList response={response} currencyCodes={currencyCodes} onPage={onPage} onSale={onSale} />;
  if (activeReport === "payments") return <PaymentReport response={response} currencyCodes={currencyCodes} />;
  if (activeReport === "products") return <ProductReport response={response} currencyCodes={currencyCodes} onProduct={onProduct} />;
  if (activeReport === "cashiers") return <CashierReport response={response} currencyCodes={currencyCodes} />;
  if (activeReport === "branch-turnover") return <BranchTurnoverReport response={response} currencyCodes={currencyCodes} />;
  if (activeReport === "cashups") return <CashupHistory response={response} lookups={lookups} currencyCodes={currencyCodes} onPage={onPage} />;
  if (activeReport === "cashouts") return <CashoutReport response={response} currencyCodes={currencyCodes} onPage={onPage} />;
  if (activeReport === "shift-sales") return <ShiftSales response={response} currencyCodes={currencyCodes} onPage={onPage} />;
  if (activeReport === "day-ends") return <DayEnds response={response} currencyCodes={currencyCodes} />;
  return null;
}

function ManagementOverviewReport({ response, currencyCodes = [] }) {
  const payload = getPayload(response);
  const summary = payload.summary || {};
  const currency = summary.currency_code || summary.base_currency_code || "";
  const sales = Number(summary.sales_value || 0);
  const cost = Number(summary.cost_value || 0);
  const grossProfit = Number(summary.gross_profit || (sales - cost));
  const cashouts = Number(summary.cashout_total || payload?.cashouts?.summary?.cashout_total || 0);
  const cashins = Number(summary.cashin_total || payload?.cashouts?.summary?.cashin_total || 0);
  const netResult = Number(summary.net_profit_after_cashouts || (grossProfit - cashouts + cashins));
  const costPercent = sales > 0 ? (cost / sales) * 100 : 0;
  const margin = sales > 0 ? (grossProfit / sales) * 100 : 0;
  const costBasis = "Recipe / BOM ingredient cost";
  const productRows = Array.isArray(payload.product_summary) ? payload.product_summary : getRows(payload.product_summary);
  const currencySummary = Object.fromEntries(currencyCodes.map((currencyCode) => {
    const rows = productRows.filter((row) => code(row.currency_code) === currencyCode);
    const currencySales = rows.reduce((sum, row) => sum + Number((row.money_received_value ?? row.sales_value) || 0), 0);
    const currencyCost = rows.reduce((sum, row) => sum + Number(row.cost_value || 0), 0);
    return [currencyCode, { sales: currencySales, cost: currencyCost, profit: currencySales - currencyCost }];
  }));
  const Row = ({ label, value, strong = false, note = "" }) => <div className={`grid min-h-8 grid-cols-[1fr_auto_auto] items-center gap-3 ${strong ? "mt-2 border-t border-[#CFD4D4] pt-2 font-medium" : ""}`}><span>{label}</span>{note && <span className="text-[11px] text-[#75A43D]">{note}</span>}<span className="text-right tabular-nums">{value}</span></div>;
  return <div className="mx-auto max-w-[920px]">
    <div className="mb-4 w-fit min-w-[260px] border-b-2 border-[#00B1B1] pb-2 text-[15px] text-[#069494]">MANAGEMENT OVERVIEW</div>
    <div className="grid gap-3 md:grid-cols-2">
      <section className="min-h-[430px] border border-[#D8DCDC] bg-white p-4">
        <div className="text-[14px]">Turnover</div>
        <div className="my-7 text-center text-[32px] font-medium tabular-nums">{money(sales, currency)}</div>
        <div className="text-[12px]">
          <Row label="Products sold" value={number(summary.quantity_sold, 3)} />
          <Row label="Products contributing" value={Number(summary.product_count || 0).toLocaleString()} />
          <Row label="Turnover" value={money(sales, currency)} strong />
        </div>
      </section>
      <section className="min-h-[430px] border border-[#D8DCDC] bg-white p-4">
        <div className="flex items-baseline justify-between"><span className="text-[14px]">Cost of Sales</span><span className="text-[10px] text-[#7C8685]">{costBasis}</span></div>
        <div className="my-7 text-center text-[32px] font-medium tabular-nums">{costPercent.toFixed(2)}%</div>
        <div className="text-[12px]">
          <Row label="Actual sales" value={money(sales, currency)} strong />
          <Row label="Less: Cost of sales" value={money(cost, currency)} />
          <Row label="Gross profit" value={money(grossProfit, currency)} note={`${margin.toFixed(2)}%`} strong />
          <Row label="Less: Operating cashouts" value={money(cashouts, currency)} />
          <Row label="Add: Cash movements in" value={money(cashins, currency)} />
          <Row label="Net operating result" value={money(netResult, currency)} strong />
        </div>
      </section>
    </div>
    <div className="mt-3">
      <ReportTable title="Profit & Loss by Currency" columns={["Metric", ...currencyCodes]} rows={[
        ["Sales", ...currencyCodes.map((currencyCode) => number(currencySummary[currencyCode]?.sales))],
        ["Cost of Sales", ...currencyCodes.map((currencyCode) => number(currencySummary[currencyCode]?.cost))],
        ["Gross Profit / Loss", ...currencyCodes.map((currencyCode) => number(currencySummary[currencyCode]?.profit))],
      ]} compact />
    </div>
  </div>;
}

function Dashboard({ response, currencyCodes = [] }) {
  const payload = getPayload(response);
  const summary = payload.base_summary || {};
  const currency = summary.currency_code || summary.base_currency_code || "";
  const currencyRows = payload.currency_summary || [];
  const topProductRows = payload.top_products || [];
  const codes = reportCurrencyCodes(currencyCodes, [...currencyRows, ...topProductRows]);
  const currencyByCode = Object.fromEntries(currencyRows.map((row) => [code(row.currency_code), row]));
  const products = pivotCurrencyRows(topProductRows, (row) => row.product_id || row.product_name);
  return <>
    <Cards rows={[
      ["Transactions", summary.transaction_count], ["Gross", money(summary.gross_sales, currency)], ["Net", money(summary.net_sales, currency)], ["Tax", money(summary.tax, currency)], ["Discounts", money(summary.discounts, currency)], ["Voids", summary.void_count], ["Refunds", summary.refund_count], ["Average Basket", money(summary.average_basket, currency)],
    ]} />
    <div className="grid gap-3 xl:grid-cols-[0.8fr_1.2fr]">
      <ReportTable compact title="Sales by Currency" columns={["Currency", "Transactions", "Sales", "Tax"]} rows={codes.map((currencyCode) => [currencyCode, Number(currencyByCode[currencyCode]?.transaction_count || 0).toLocaleString(), number(currencyByCode[currencyCode]?.total), number(currencyByCode[currencyCode]?.tax)])} />
      <ReportTable compact title="Top Products · Sales" columns={["Product", "Qty", ...codes]} rows={products.map((entry) => [
        entry.first.product_name,
        number(entry.rows.reduce((sum, row) => sum + Number(row.quantity_sold || 0), 0), 3),
        ...codes.map((currencyCode) => currencyAmount(entry, currencyCode, "money_received_value", "sales_value")),
      ])} />
    </div>
    <SalesPerformanceVisuals currencyRows={currencyRows} productRows={topProductRows} />
  </>;
}

function HorizontalBarGraph({ title, subtitle, rows = [], valueFormatter = (value) => number(value), color = "#069494" }) {
  const safeRows = rows.slice(0, 10);
  const maxValue = Math.max(...safeRows.map((row) => Number(row.value || 0)), 1);
  return <section className="border border-[#D8DCDC] bg-white p-4">
    <div className="text-[13px] font-semibold text-[#293232]">{title}</div>
    <div className="mb-4 text-[10px] text-[#7C8685]">{subtitle}</div>
    <div className="space-y-2" role="img" aria-label={title}>
      {safeRows.length === 0 && <Empty />}
      {safeRows.map((row) => <div key={row.label} className="grid grid-cols-[110px_minmax(120px,1fr)_100px] items-center gap-2 text-[10px]">
        <div className="truncate text-right font-medium text-[#485353]" title={row.label}>{row.label}</div>
        <div className="relative h-5 border-l border-b border-[#ACB8B8] bg-[#F7F8F9]">
          <div className="h-[calc(100%-3px)]" style={{ width: `${Math.max((Number(row.value || 0) / maxValue) * 100, Number(row.value || 0) > 0 ? 1 : 0)}%`, backgroundColor: color }} />
        </div>
        <div className="tabular-nums text-[#293232]">{valueFormatter(row.value)}</div>
      </div>)}
      {safeRows.length > 0 && <div className="grid grid-cols-[110px_minmax(120px,1fr)_100px] gap-2 text-[9px] text-[#7C8685]"><span /><div className="flex justify-between"><span>0</span><span>{valueFormatter(maxValue)}</span></div><span /></div>}
    </div>
  </section>;
}

function GroupedPerformanceGraph({ title, subtitle, rows = [], currency = "" }) {
  const safeRows = rows.slice(0, 8);
  const maxValue = Math.max(...safeRows.flatMap((row) => row.metrics.map((metric) => Math.abs(Number(metric.value || 0)))), 1);
  const colors = { Sales: "#069494", Cost: "#F59E0B", Profit: "#2F9E63" };
  return <section className="border border-[#D8DCDC] bg-white p-4">
    <div className="text-[13px] font-semibold text-[#293232]">{title}</div>
    <div className="text-[10px] text-[#7C8685]">{subtitle}</div>
    <div className="my-3 flex flex-wrap gap-4 text-[9px] uppercase text-[#5D6767]">{Object.entries(colors).map(([label, metricColor]) => <span key={label} className="inline-flex items-center gap-1"><i className="h-2.5 w-2.5" style={{ backgroundColor: metricColor }} />{label}</span>)}</div>
    <div className="space-y-3" role="img" aria-label={title}>
      {safeRows.length === 0 && <Empty />}
      {safeRows.map((row) => <div key={row.label} className="grid grid-cols-[120px_minmax(160px,1fr)] gap-3">
        <div className="truncate pt-0.5 text-right text-[10px] font-medium text-[#485353]" title={row.label}>{row.label}</div>
        <div className="space-y-1">
          {row.metrics.map((metric) => <div key={metric.label} className="grid grid-cols-[minmax(100px,1fr)_115px] items-center gap-2">
            <div className="h-3 border-l border-b border-[#D8DCDC] bg-[#F7F8F9]"><div className="h-[10px]" style={{ width: `${Math.max((Math.abs(Number(metric.value || 0)) / maxValue) * 100, Number(metric.value || 0) !== 0 ? 1 : 0)}%`, backgroundColor: colors[metric.label] || "#637877" }} /></div>
            <div className="text-[9px] tabular-nums text-[#485353]">{metric.label}: {money(metric.value, currency)}</div>
          </div>)}
        </div>
      </div>)}
      {safeRows.length > 0 && <div className="ml-[132px] flex justify-between border-t border-[#ACB8B8] pt-1 text-[9px] text-[#7C8685]"><span>0</span><span>{money(maxValue, currency)}</span></div>}
    </div>
  </section>;
}

function SalesPerformanceVisuals({ currencyRows = [], productRows = [] }) {
  const codes = reportCurrencyCodes([], [...currencyRows, ...productRows]);
  const products = pivotCurrencyRows(productRows, (row) => row.product_id || row.product_name);
  return <div className="mt-3 space-y-3">
    <HorizontalBarGraph title="Transaction mix by sale currency" subtitle="Completed receipt count; monetary values are never mixed across currencies" rows={currencyRows.map((row) => ({ label: code(row.currency_code), value: Number(row.transaction_count || 0) }))} valueFormatter={(value) => Number(value || 0).toLocaleString()} color="#008585" />
    <div className="grid gap-3 xl:grid-cols-2">
      {codes.map((currencyCode) => {
        const currencyProducts = products.map((entry) => {
          const row = entry.byCurrency[currencyCode];
          if (!row) return null;
          const sales = Number(row.money_received_value ?? row.sales_value ?? row.total ?? 0);
          const cost = Number(row.cost_value || 0);
          const profit = Number(row.money_received_gross_profit ?? row.gross_profit ?? row.estimated_profit ?? (sales - cost));
          return { label: entry.first.product_name, metrics: [{ label: "Sales", value: sales }, { label: "Cost", value: cost }, { label: "Profit", value: profit }] };
        }).filter(Boolean).sort((a, b) => Number(b.metrics[0].value) - Number(a.metrics[0].value));
        return <GroupedPerformanceGraph key={currencyCode} title={`${currencyCode} product performance`} subtitle={`Sales, cost and profit recorded in ${currencyCode}; no cross-currency mixing`} rows={currencyProducts} currency={currencyCode} />;
      })}
    </div>
  </div>;
}


function DailySales({ response, currencyCodes = [], onPage }) {
  const payload = getPayload(response);
  const summary = payload.summary || {};
  const sales = payload.sales || {};
  const rows = getRows(sales);
  const meta = getMeta(sales);
  const productRows = payload.product_sales || [];
  const codes = reportCurrencyCodes(currencyCodes, [...productRows, ...rows]);
  const products = pivotCurrencyRows(productRows, (row) => row.product_id || row.product_name);
  return <>
    <Cards rows={[["Trading Date", payload.business_date], ["Transactions", summary.transaction_count], ["Sales", money(summary.sales_value, summary.currency_code || summary.base_currency_code)], ["Cost", money(summary.cost_value, summary.cost_currency_code || summary.currency_code || summary.base_currency_code)], ["Profit", money(summary.estimated_profit, summary.currency_code || summary.base_currency_code)], ["Products", number(summary.product_sold_quantity, 3)]]} />
    <ReportTable title="Product Sales" columns={["Product", "Receipts", "Qty", ...codes.flatMap((currencyCode) => [`${currencyCode} Sales`, `${currencyCode} Cost`, `${currencyCode} Profit`])]} rows={products.map((entry) => [entry.first.product_name, entry.rows.reduce((sum, row) => sum + Number(row.receipt_count || 0), 0), number(entry.rows.reduce((sum, row) => sum + Number(row.quantity_sold || 0), 0), 3), ...codes.flatMap((currencyCode) => [currencyAmount(entry, currencyCode, "sales_value"), currencyAmount(entry, currencyCode, "cost_value"), currencyAmount(entry, currencyCode, "estimated_profit")])])} />
    <ReportTable title={`Sales List (${meta.total})`} columns={["Receipt", "Date", "Cashier", "Till", "Payment", "Qty", ...codes.flatMap((currencyCode) => [`${currencyCode} Sales`, `${currencyCode} Cost`, `${currencyCode} Profit`]), "Status"]} rows={rows.map((r) => [r.invoice_number, formatDateTime(r.transaction_date), r.cashier_name, r.till_name, r.payment_method, number(r.product_sold_quantity, 3), ...codes.flatMap((currencyCode) => [currencyAmountForRow(r, currencyCode, "sales_value"), currencyAmountForRow(r, currencyCode, "cost_value"), currencyAmountForRow(r, currencyCode, "estimated_profit")]), r.status])} footer={<Pagination meta={meta} onPage={onPage} />} />
  </>;
}

function SalesList({ response, currencyCodes = [], onPage, onSale }) {
  const rows = getRows(response);
  const meta = getMeta(response);
  const summary = summaryOf(response);
  const codes = reportCurrencyCodes(currencyCodes, rows);
  return <>
    <Cards rows={[["Transactions", summary.transaction_count], ["Products Sold", number(summary.product_sold_quantity, 3)], ["Sales", money(summary.sales_value, summary.currency_code || summary.base_currency_code)], ["Cost", money(summary.cost_value, summary.cost_currency_code || summary.currency_code || summary.base_currency_code)], ["Profit", money(summary.estimated_profit, summary.currency_code || summary.base_currency_code)]]} />
    <ReportTable title={`Sales Transactions (${meta.total})`} columns={["Receipt", "Date", "Cashier", "Till", "Customer", "Payment", "Qty", ...codes.flatMap((currencyCode) => [`${currencyCode} Sales`, `${currencyCode} Cost`, `${currencyCode} Profit`]), "Status"]} rows={rows.map((r) => [<button key="receipt" type="button" onClick={() => onSale(r.id)} className="font-semibold text-[#008585] underline-offset-2 hover:underline">{r.invoice_number}</button>, formatDateTime(r.transaction_date), r.cashier_name, r.till_name, r.customer_name || "Walk-in", r.payment_method, number(r.product_sold_quantity, 3), ...codes.flatMap((currencyCode) => [currencyAmountForRow(r, currencyCode, "sales_value", "total_amount"), currencyAmountForRow(r, currencyCode, "cost_value"), currencyAmountForRow(r, currencyCode, "estimated_profit")]), <Status key="s" value={r.status} />])} footer={<Pagination meta={meta} onPage={onPage} />} />
  </>;
}

function PaymentReport({ response, currencyCodes = [] }) {
  const rows = getRows(response);
  const codes = reportCurrencyCodes(currencyCodes, rows);
  const payments = pivotCurrencyRows(rows, (row) => `${row.payment_name || "Payment"}|${row.money_code || ""}`);
  return <ReportTable title="Payment Summary" columns={["Payment", "Money Code", "Count", ...codes.flatMap((currencyCode) => [`${currencyCode} Amount`, `${currencyCode} Tendered`, `${currencyCode} Change`])]} rows={payments.map((entry) => [entry.first.payment_name, entry.first.money_code, entry.rows.reduce((sum, row) => sum + Number(row.payment_count || 0), 0), ...codes.flatMap((currencyCode) => [currencyAmount(entry, currencyCode, "amount"), currencyAmount(entry, currencyCode, "amount_tendered"), currencyAmount(entry, currencyCode, "change_amount")])])} />;
}

function ProductReport({ response, currencyCodes = [], onProduct }) {
  const rows = getRows(response);
  const codes = reportCurrencyCodes(currencyCodes, rows);
  const products = pivotCurrencyRows(rows, (row) => row.product_id || row.product_name);
  return <ReportTable title="Product Sales With Cost" columns={["Product", "Receipts", "Qty", ...codes.flatMap((currencyCode) => [`${currencyCode} Avg Cost`, `${currencyCode} Sales`, `${currencyCode} Cost`, `${currencyCode} Gross Profit`, `${currencyCode} Margin`])]} rows={products.map((entry) => [<button key="product" type="button" onClick={() => onProduct(entry.first.product_id, entry.first.product_name)} className="font-semibold text-[#008585] underline-offset-2 hover:underline">{entry.first.product_name}</button>, entry.rows.reduce((sum, row) => sum + Number(row.receipt_count || 0), 0), number(entry.rows.reduce((sum, row) => sum + Number(row.quantity_sold || 0), 0), 3), ...codes.flatMap((currencyCode) => { const row = entry.byCurrency[currencyCode]; return row ? [number(row.average_cost), number(row.sales_value ?? row.total), number(row.cost_value), number(row.estimated_profit ?? row.gross_profit), `${number(row.gross_margin_percent || 0)}%`] : ["-", "-", "-", "-", "-"]; })])} />;
}

function CashierReport({ response, currencyCodes = [] }) {
  const rows = getRows(response);
  const codes = reportCurrencyCodes(currencyCodes, rows);
  const cashiers = pivotCurrencyRows(rows, (row) => row.cashier_id || row.cashier_name);
  return <ReportTable title="Cashier Sales" columns={["Cashier", "Transactions", ...codes.flatMap((currencyCode) => [`${currencyCode} Subtotal`, `${currencyCode} Discount`, `${currencyCode} Tax`, `${currencyCode} Sales`])]} rows={cashiers.map((entry) => [entry.first.cashier_name, entry.rows.reduce((sum, row) => sum + Number(row.transaction_count || 0), 0), ...codes.flatMap((currencyCode) => [currencyAmount(entry, currencyCode, "subtotal"), currencyAmount(entry, currencyCode, "discount"), currencyAmount(entry, currencyCode, "tax"), currencyAmount(entry, currencyCode, "total")])])} />;
}

function BranchTurnoverReport({ response, currencyCodes = [] }) {
  const payload = getPayload(response);
  const rows = Array.isArray(payload.rows) ? payload.rows : [];
  const summary = payload.summary || {};
  const baseCurrency = code(payload.base_currency_code || "");
  const codes = reportCurrencyCodes(currencyCodes, rows);
  const branches = pivotCurrencyRows(rows, (row) => row.branch_id || row.branch_name);
  return <>
    <Cards rows={[
      ["Accessible Branches", Number(summary.branch_count || branches.length).toLocaleString()],
      ["Transactions", Number(summary.transaction_count || 0).toLocaleString()],
      ["Sales", money(summary.base_sales_value, baseCurrency)],
      ["Cost", money(summary.base_cost_value, baseCurrency)],
      ["Profit", money(summary.base_profit_value, baseCurrency)],
    ]} />
    <div className="mb-3 grid gap-3 xl:grid-cols-2">{codes.map((currencyCode) => {
      const graphRows = branches.map((entry) => {
        const row = entry.byCurrency[currencyCode];
        if (!row) return null;
        return { label: entry.first.branch_name || "Branch", metrics: [{ label: "Sales", value: Number(row.sales_value || 0) }, { label: "Cost", value: Number(row.cost_value || 0) }, { label: "Profit", value: Number(row.profit_value || 0) }] };
      }).filter(Boolean).sort((a, b) => Number(b.metrics[0].value) - Number(a.metrics[0].value));
      return <GroupedPerformanceGraph key={currencyCode} title={`${currencyCode} branch turnover`} subtitle={`Branch sales, cost and profit recorded in ${currencyCode}`} rows={graphRows} currency={currencyCode} />;
    })}</div>
    <ReportTable title="Branch Turnover" columns={["Branch", "Transactions", ...codes.flatMap((currencyCode) => [`${currencyCode} Sales`, `${currencyCode} Cost`, `${currencyCode} Profit`])]} rows={branches.map((entry) => [entry.first.branch_name || "Branch", entry.rows.reduce((sum, row) => sum + Number(row.transaction_count || 0), 0), ...codes.flatMap((currencyCode) => [currencyAmount(entry, currencyCode, "sales_value"), currencyAmount(entry, currencyCode, "cost_value"), currencyAmount(entry, currencyCode, "profit_value")])])} />
  </>;
}

function CashupHistory({ response, lookups, currencyCodes = [], onPage }) {
  const rows = getRows(response);
  const meta = getMeta(response);
  const canViewAmounts = canViewCashupFinancialAmounts(response);
  const totalRows = rows.flatMap((row) => Array.isArray(row.currency_summary) ? row.currency_summary : []);
  const cashRows = rows.flatMap((row) => Array.isArray(row.cash_by_currency) ? row.cash_by_currency : []);
  const codes = reportCurrencyCodes(currencyCodes, [...totalRows, ...cashRows]);
  return <>
    <Cards rows={[["Cashups", meta.total], ["Transactions", rows.reduce((sum, row) => sum + Number(row.transaction_count || 0), 0)], ...(canViewAmounts ? codes.flatMap((currencyCode) => {
      const totals = totalRows.filter((row) => code(row.currency_code) === currencyCode);
      const cash = cashRows.filter((row) => code(row.currency_code) === currencyCode);
      return [
        [`${currencyCode} Expected`, money(totals.reduce((sum, row) => sum + Number(row.expected_amount || 0), 0), currencyCode)],
        [`${currencyCode} Counted`, money(totals.reduce((sum, row) => sum + Number(row.counted_amount || 0), 0), currencyCode)],
        [`${currencyCode} Variance`, money(totals.reduce((sum, row) => sum + Number(row.variance || 0), 0), currencyCode)],
        [`${currencyCode} Cashouts`, money(cash.reduce((sum, row) => sum + Number(row.cashout_total || 0), 0), currencyCode)],
      ];
    }) : [])]} />
    {!canViewAmounts && <RestrictedAmountsNotice />}
    <div className="space-y-2">
      {rows.length === 0 && <Empty />}
      {rows.map((row) => <CashupBracketCard key={row.id} row={row} lookups={lookups} canViewAmounts={canViewAmounts} />)}
      <Pagination meta={meta} onPage={onPage} />
    </div>
  </>;
}

function CashupBracketCard({ row, lookups, canViewAmounts }) {
  const mode = "Hospitality";
  const period = row.cashup_period_label || row.cashup_period_key || `${formatDateTime(row.opened_at)} - ${formatDateTime(row.closed_at)}`;
  const bracketDate = row.cashup_bracket_date || row.trading_date || String(row.opened_at || "").slice(0, 10) || "-";
  const bracketLabel = "Trading Date";
  const paymentSummary = Array.isArray(row.payment_summary) ? row.payment_summary : [];
  const creditRows = accountCreditRows(row);
  const denominations = Array.isArray(row.denominations) ? row.denominations : [];
  const currencySummary = Array.isArray(row.currency_summary) ? row.currency_summary : [];
  const cashSummary = Array.isArray(row.cash_by_currency) ? row.cash_by_currency : [];
  const paymentCodes = reportCurrencyCodes([], [...paymentSummary, ...denominations, ...currencySummary, ...cashSummary]);
  const currencyLabel = paymentCodes.length > 0 ? paymentCodes.join(", ") : code(row.cashup_currency_code || row.currency_code || "USD");
  const paymentRows = pivotCurrencyRows(paymentSummary, (payment) => `${payment.payment_name || "Payment"}|${payment.money_code || ""}`);
  const denominationRows = pivotCurrencyRows(denominations, (denomination) => denomination.denomination_value || denomination.value);
  return (
    <div className="border border-[#ACB8B8] bg-white">
      <div className="grid gap-2 border-b border-[#ACB8B8] bg-[#F5F7FA] p-2 lg:grid-cols-[1fr_180px_180px_160px]">
        <div><div className="text-[12px] font-semibold uppercase text-[#003636]">{period}</div><div className="mt-0.5 text-[10px] font-semibold uppercase text-[#637877]">{mode} / {currencyLabel} / {bracketLabel} {bracketDate}</div></div>
        <Metric label="Cashier" value={row.cashier_name || lookups.cashiers.find((u) => u.id === row.cashier_id)?.name || "-"} />
        <Metric label="Till" value={reportTillLabel(row)} />
        <Metric label="Status" value={row.status || "-"} />
      </div>
      {!canViewAmounts && <div className="grid gap-2 p-2 md:grid-cols-3 xl:grid-cols-6">
        <Metric label="Opened" value={formatDateTime(row.opened_at)} />
        <Metric label="Closed" value={formatDateTime(row.closed_at)} />
        <Metric label="Transactions" value={number(row.transaction_count, 0)} />
        <Metric label="Voids" value={number(row.void_count, 0)} />
        <Metric label="Refunds" value={number(row.refund_count, 0)} />
        <Metric label="Closed By" value={row.closed_by_name || "-"} />
      </div>}
      {canViewAmounts && <div className="space-y-2 p-2">{paymentCodes.map((currencyCode) => {
        const totals = currencySummary.find((entry) => code(entry.currency_code) === currencyCode) || {};
        const cash = cashSummary.find((entry) => code(entry.currency_code) === currencyCode) || {};
        return <div key={currencyCode} className="border border-[#D8DCDC] bg-[#FBFCFD] p-2">
          <div className="mb-2 text-[10px] font-bold uppercase text-[#008585]">{currencyCode} reconciliation</div>
          <div className="grid gap-2 md:grid-cols-4 xl:grid-cols-7">
            <Metric label="Expected Cash" value={money(cash.expected_cash, currencyCode)} />
            <Metric label="Cashouts" value={money(cash.cashout_total, currencyCode)} danger />
            <Metric label="After Cashouts" value={money(cash.expected_cash_after_cashouts, currencyCode)} />
            <Metric label="Counted Cash" value={money(cash.counted_cash, currencyCode)} />
            <Metric label="Cash Variance" value={money(cash.cash_variance, currencyCode)} danger={Number(cash.cash_variance || 0) < 0} />
            <Metric label="Expected Total" value={money(totals.expected_amount, currencyCode)} />
            <Metric label="Total Variance" value={money(totals.variance, currencyCode)} danger={Number(totals.variance || 0) !== 0} />
          </div>
        </div>;
      })}</div>}
      {(paymentSummary.length > 0 || (canViewAmounts && denominations.length > 0)) && <div className="grid gap-2 border-t border-[#E3E9E9] p-2 xl:grid-cols-2">
        {paymentSummary.length > 0 && <ReportTable compact title="All Payment Methods (Cash and Non-Cash)" columns={canViewAmounts ? ["Payment Method", "Money Code", ...paymentCodes.flatMap((currencyCode) => [`${currencyCode} Expected`, `${currencyCode} Counted`, `${currencyCode} Variance`])] : ["Payment Method", "Money Code", "Currency", "Payments"]} rows={canViewAmounts ? paymentRows.map((entry) => [entry.first.payment_name, entry.first.money_code || "-", ...paymentCodes.flatMap((currencyCode) => [currencyAmount(entry, currencyCode, "expected_amount"), currencyAmount(entry, currencyCode, "counted_amount"), currencyAmount(entry, currencyCode, "variance")])]) : paymentSummary.map((payment) => [payment.payment_name || "Payment", payment.money_code || "-", code(payment.currency_code) || "-", Number(payment.payment_count || 0)])} />}
        {canViewAmounts && denominations.length > 0 && <ReportTable compact title="Denominations" columns={["Denomination", ...paymentCodes.flatMap((currencyCode) => [`${currencyCode} Qty`, `${currencyCode} Total`])]} rows={denominationRows.map((entry) => [number(entry.first.denomination_value || entry.first.value), ...paymentCodes.flatMap((currencyCode) => { const denomination = entry.byCurrency[currencyCode]; return denomination ? [denomination.quantity, number(denomination.line_total)] : ["-", "-"]; })])} />}
      </div>}
      {creditRows.length > 0 && <div className="border-t border-[#E3E9E9] p-2">
        <ReportTable compact title="Account Credit Activity (Not Received Tender)" columns={canViewAmounts ? ["Method", "Money Code", "Currency", "Credit Sales", "Credit Issued"] : ["Method", "Money Code", "Currency", "Credit Sales"]} rows={creditRows.map((entry) => canViewAmounts ? [entry.payment_name || "Customer Account", entry.money_code || "customer_credit", code(entry.currency_code) || "-", Number(entry.payment_count || 0), money(entry.amount, code(entry.currency_code))] : [entry.payment_name || "Customer Account", entry.money_code || "customer_credit", code(entry.currency_code) || "-", Number(entry.payment_count || 0)])} />
      </div>}
    </div>
  );
}

function CashoutReport({ response, currencyCodes = [], onPage }) {
  const rows = getRows(response);
  const meta = getMeta(response);
  const summary = summaryOf(response);
  const canViewAmounts = canViewCashupFinancialAmounts(response);
  const totalsByCurrency = Array.isArray(summary?.totals_by_currency) ? summary.totals_by_currency : [];
  const codes = reportCurrencyCodes(currencyCodes, [...totalsByCurrency, ...rows]);
  return <>
    <Cards rows={cashMovementKpiRows({ summary, rows, currencyCodes, total: meta.total, canViewAmounts })} />
    {!canViewAmounts && <RestrictedAmountsNotice />}
    <ReportTable title={`Cash Drawer Movements (${meta.total})`} columns={canViewAmounts ? ["Time", "Cashier", "Till", "Shift", "Type", "Reason", "Reference", ...codes.map((currencyCode) => `${currencyCode} Amount`), "Cashup"] : ["Time", "Cashier", "Till", "Shift", "Type", "Currency", "Reason", "Reference", "Cashup"]} rows={rows.map((r) => canViewAmounts ? [formatDateTime(r.occurred_at), r.cashier_name, reportTillLabel(r), r.shift_name || r.shift_session_id, r.movement_type, r.reason || "-", r.reference_no || "-", ...codes.map((currencyCode) => currencyAmountForRow(r, currencyCode, "amount")), r.cashup_status || "-"] : [formatDateTime(r.occurred_at), r.cashier_name, reportTillLabel(r), r.shift_name || r.shift_session_id, r.movement_type, r.currency_code || "-", r.reason || "-", r.reference_no || "-", r.cashup_status || "-"])} footer={<Pagination meta={meta} onPage={onPage} />} />
  </>;
}

function ShiftSales({ response, currencyCodes = [], onPage }) {
  const rows = getRows(response);
  const meta = getMeta(response);
  const canViewCashupAmounts = canViewCashupFinancialAmounts(response);
  const allCurrencyRows = rows.flatMap((row) => Array.isArray(row.currency_summary) ? row.currency_summary : []);
  const codes = reportCurrencyCodes(currencyCodes, allCurrencyRows);
  return <>
    {!canViewCashupAmounts && <RestrictedAmountsNotice />}
    <ReportTable title={`Shift Sessions (${meta.total})`} columns={["Shift", "Cashier", "Opened", "Closed", "Status", "Transactions", ...(canViewCashupAmounts ? [...codes.map((currencyCode) => `${currencyCode} Sales`), "Cashup Variance"] : [])]} rows={rows.map((r) => { const byCurrency = Object.fromEntries((r.currency_summary || []).map((summary) => [code(summary.currency_code), summary])); const multiCurrencyCashup = r.cashup?.is_multi_currency || code(r.cashup?.cashup_currency_code) === "MULTI"; return [r.shift_session?.shift_name || r.shift_session?.shift_session_id, r.shift_session?.cashier_name, formatDateTime(r.shift_session?.opened_at), formatDateTime(r.shift_session?.closed_at), r.shift_session?.status, r.base_summary?.transaction_count || 0, ...(canViewCashupAmounts ? [...codes.map((currencyCode) => number(byCurrency[currencyCode]?.total)), multiCurrencyCashup ? "By currency" : money(r.cashup?.total_variance, r.cashup?.cashup_currency_code || r.shift_session?.base_currency_code)] : [])]; })} footer={<Pagination meta={meta} onPage={onPage} />} />
  </>;
}

function DayEnds({ response, currencyCodes = [] }) {
  const payload = getPayload(response);
  const canViewCashupAmounts = canViewCashupFinancialAmounts(response);
  const salesRows = payload.sales_by_day_currency || [];
  const cashupRows = payload.cashups_by_day || [];
  const codes = reportCurrencyCodes(currencyCodes, [...salesRows, ...cashupRows]);
  const days = pivotCurrencyRows(salesRows, (row) => row.business_date);
  const cashupDays = pivotCurrencyRows(cashupRows, (row) => row.business_date);
  return <>
    {!canViewCashupAmounts && <RestrictedAmountsNotice />}
    <TwoTables leftTitle="Sales by Day and Currency" leftColumns={["Date", "Transactions", ...(canViewCashupAmounts ? codes.flatMap((currencyCode) => [`${currencyCode} Subtotal`, `${currencyCode} Tax`, `${currencyCode} Total`]) : [])]} leftRows={days.map((entry) => [entry.first.business_date, entry.rows.reduce((sum, row) => sum + Number(row.transaction_count || 0), 0), ...(canViewCashupAmounts ? codes.flatMap((currencyCode) => [currencyAmount(entry, currencyCode, "subtotal"), currencyAmount(entry, currencyCode, "tax"), currencyAmount(entry, currencyCode, "total")]) : [])])} rightTitle="Cashups by Day" rightColumns={["Date", ...codes.flatMap((currencyCode) => canViewCashupAmounts ? [`${currencyCode} Cashups`, `${currencyCode} Expected`, `${currencyCode} Cashouts`, `${currencyCode} Counted`, `${currencyCode} Variance`] : [`${currencyCode} Cashups`])]} rightRows={cashupDays.map((entry) => [entry.first.business_date, ...codes.flatMap((currencyCode) => canViewCashupAmounts ? [currencyAmount(entry, currencyCode, "cashup_count"), currencyAmount(entry, currencyCode, "expected_cash"), currencyAmount(entry, currencyCode, "cashout_total"), currencyAmount(entry, currencyCode, "counted_cash"), currencyAmount(entry, currencyCode, "cash_variance")] : [currencyAmount(entry, currencyCode, "cashup_count")])])} />
  </>;
}

function TwoTables({ leftTitle, leftColumns, leftRows, rightTitle, rightColumns, rightRows }) {
  return <div className="grid gap-2 xl:grid-cols-2"><ReportTable title={leftTitle} columns={leftColumns} rows={leftRows} /><ReportTable title={rightTitle} columns={rightColumns} rows={rightRows} /></div>;
}

function Cards({ rows }) { return <div className="mb-2 grid gap-2 md:grid-cols-2 xl:grid-cols-5">{rows.map(([label, value], index) => <Metric key={`${label}-${index}`} label={label} value={value ?? "-"} />)}</div>; }

function RestrictedAmountsNotice() {
  return <div className="mb-2 border border-[#F2C94C] bg-[#FFF8E1] px-3 py-2 text-[11px] font-semibold text-[#7A5200]">Financial cashup amounts are restricted for this role. Operational dates, statuses and audit references remain available.</div>;
}
function Metric({ label, value, danger = false }) { return <div className="border border-[#ACB8B8] bg-white p-2"><div className="text-[10px] font-semibold uppercase text-[#637877]">{label}</div><div className={`mt-1 truncate text-[16px] font-semibold ${danger ? "text-[#B42318]" : "text-[#003636]"}`}>{value}</div></div>; }
function Status({ value }) { return <span className="inline-flex border border-[#ACB8B8] bg-[#F6F8FA] px-2 py-0.5 text-[10px] font-semibold uppercase text-[#324444]">{value || "-"}</span>; }
function Empty() { return <div className="flex min-h-[100px] items-center justify-center border border-[#ACB8B8] bg-white text-[12px] font-semibold text-[#637877]">No records found.</div>; }

function ReportTable({ title, columns, rows, footer, compact = false }) {
  return (
    <div className="overflow-hidden border border-[#ACB8B8] bg-white">
      <div className="border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2 text-[12px] font-semibold uppercase text-[#003636]">{title}</div>
      {(!rows || rows.length === 0) ? <Empty /> : <div className="overflow-x-auto"><table className={`w-full border-collapse ${compact ? "min-w-[640px]" : "min-w-[1200px]"} text-[11px]`}><thead><tr className="bg-[#0E4D4D] text-white">{columns.map((c) => <th key={String(c)} className="whitespace-nowrap border-r border-white/10 px-2 py-2 text-left text-[10px] font-semibold uppercase">{c}</th>)}</tr></thead><tbody>{rows.map((row, i) => <tr key={i} className="border-b border-[#E3E9E9] hover:bg-[#F6F8FA]">{row.map((cell, j) => <td key={j} className="whitespace-nowrap border-r border-[#E3E9E9] px-2 py-2 text-[#324443]">{cell ?? "-"}</td>)}</tr>)}</tbody></table></div>}
      {footer}
    </div>
  );
}

function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null;
  return <div className="flex items-center justify-between border-t border-[#ACB8B8] bg-[#F6F8FA] px-3 py-2"><div className="text-[11px] font-semibold uppercase text-[#637877]">Page {meta.current_page} of {meta.last_page} / {meta.total} records</div><div className="flex gap-1"><button type="button" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} className="mini-btn disabled:opacity-50">Previous</button><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)} className="mini-btn disabled:opacity-50">Next</button></div></div>;
}

function DrilldownPanel({ drilldown, onClose }) {
  const payload = drilldown.payload || {};
  const sale = payload.sale;
  const rows = payload.rows || sale?.receipt_lines || sale?.receiptLines || [];
  return <div className="fixed inset-0 z-[90] bg-black/45 p-3"><div className="mx-auto flex h-full max-w-[1400px] flex-col overflow-hidden border border-[#ACB8B8] bg-white"><div className="flex items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2"><div className="text-[14px] font-semibold uppercase text-[#003636]">{drilldown.title}</div><button type="button" onClick={onClose} className="border border-[#ACB8B8] bg-white p-1.5"><X className="h-4 w-4" /></button></div><div className="min-h-0 flex-1 overflow-y-auto bg-[#DFE5E5] p-2">{sale && <Cards rows={[["Receipt", sale.invoice_number], ["Date", formatDateTime(sale.transaction_date)], ["Customer", sale.customer_name || "Walk-in"], ["Currency", sale.currency_code], ["Total", money(sale.total_amount, sale.currency_code)]]} />}<ReportTable title="Details" columns={["Line", "Product", "Qty", "Price", "Discount", "Tax", "Total", "Cost", "Profit"]} rows={rows.map((r) => [r.line_no || r.invoice_number, r.line_name || r.product_name, number(r.line_quantity || r.quantity_sold, 3), money(r.line_price || r.selling_price, r.currency_code), money(r.line_discount || r.discount, r.currency_code), money(r.line_tax || r.tax, r.currency_code), money(r.line_total || r.sales_value, r.currency_code), money(r.cost_value, r.cost_currency_code || r.currency_code), money(r.gross_profit || r.estimated_profit, r.currency_code)])} /></div></div></div>;
}
