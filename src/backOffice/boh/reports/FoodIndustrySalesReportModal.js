import React, { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import HospitalityReportNavigator, {
  HOSPITALITY_REPORT_KEYS,
} from "../../../components/HospitalityReportNavigator";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import {
  downloadFoodIndustrySalesExcel,
  downloadFoodIndustrySalesPdf,
  getFoodIndustrySalesCurrencies,
  getFoodIndustrySalesBusinessDates,
  getFoodIndustrySalesTurnover,
} from "../../../services/FoodIndustrySalesReportService";

const today = () => new Date().toISOString().slice(0, 10);
const n = (value) => Number(value || 0);
const qty = (value, decimals = 3) => n(value).toFixed(decimals);
const pct = (value) => `${n(value).toFixed(2)}%`;
const money = (value, currency = "") => `${currency ? `${currency} ` : ""}${n(value).toFixed(2)}`;
const rowsOf = (value) => {
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  if (Array.isArray(value?.data?.data)) return value.data.data;
  return [];
};
const safeKey = (...values) => values.map((value) => String(value ?? "")).join("-");
const currencyCodeOf = (row) => row?.code || row?.currency_code || row?.base_currency_code || "";
const businessDateOf = (row) => row?.business_date || row?.trading_date || row?.date || "";
const hourLabel = (hour) => `${String(hour).padStart(2, "0")}:00`;
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
export const amountColumn = (entry, currencyCode, field) => entry?.byCurrency?.[currencyCode] ? n(entry.byCurrency[currencyCode][field]).toFixed(2) : "-";
const rowCurrencyAmount = (row, currencyCode, field) => String(row?.currency_code || "").toUpperCase() === currencyCode ? n(row?.[field]).toFixed(2) : "-";
export const receiptLineCount = (row = {}) => qty(row.receipt_lines_count ?? row.line_count ?? 0, 0);
const snapshotFilters = (value = {}) => ({ ...value, currency_codes: [...(value.currency_codes || [])] });

const HOUR_GROUPS = [
  { key: "morning", label: "Morning", range: [6, 10] },
  { key: "lunch", label: "Lunch", range: [11, 15] },
  { key: "dinner", label: "Dinner", range: [16, 21] },
  { key: "late", label: "Late", range: [22, 5] },
];

const tabs = [
  { key: "overview", label: "Overview", description: "Sales and recipe-cost summary." },
  { key: "menu", label: "Menu Profitability", description: "Margin and food cost by menu item." },
  { key: "category", label: "Category Sales", description: "Turnover and margin by category." },
  { key: "ingredients", label: "Ingredient Cost", description: "Recipe component cost drivers." },
  { key: "curve", label: "Hourly Sales", description: "Trading performance by hour." },
  { key: "operations", label: "Operations", description: "Payments, cashiers and receipts." },
];

const FoodIndustrySalesReportModal = ({ open, onClose, currentUser = null, onNavigateReport }) => {
  const activeBranchId =
    currentUser?.selectedBranch?.id ||
    currentUser?.selectedBranchId ||
    currentUser?.branch?.id ||
    currentUser?.branch_id ||
    "";
  const [filters, setFilters] = useState({
    date_from: today(),
    date_to: today(),
    branch_id: activeBranchId,
    till_id: "",
    cashier_id: "",
    shift_session_id: "",
    currency_codes: [],
    business_mode: "fast_food_boh",
  });
  const [currencies, setCurrencies] = useState([]);
  const [report, setReport] = useState(null);
  const [activeTab, setActiveTab] = useState("overview");
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserSource, setChooserSource] = useState("configuration");
  const [outputFilters, setOutputFilters] = useState(null);
  const [appliedFilters, setAppliedFilters] = useState(null);
  const [exporting, setExporting] = useState(false);
  const [viewing, setViewing] = useState(false);
  const reportLookups = useHospitalityReportLookups(open, filters);
  const scopeLookups = { ...reportLookups, currencies: currencies.length ? currencies : reportLookups.currencies };
  const activeFilters = appliedFilters || filters;

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 4200);
  };

  const loadBusinessDates = async (baseFilters = filters) => {
    try {
      const data = await getFoodIndustrySalesBusinessDates({ branch_id: baseFilters.branch_id || activeBranchId });
      const rows = rowsOf(data?.business_dates || data?.dates || data);
      const selected = data?.current_business_date || data?.current_open_business_date || businessDateOf(rows[0]);

      if (selected && (baseFilters.date_from === today() || !baseFilters.date_from) && (baseFilters.date_to === today() || !baseFilters.date_to)) {
        const next = { ...baseFilters, date_from: selected, date_to: selected };
        setFilters(next);
        return next;
      }
    } catch {
      // The standard period fields remain available when the trading-day lookup is unavailable.
    }

    return baseFilters;
  };

  const loadCurrencies = async (nextFilters = filters) => {
    try {
      const data = await getFoodIndustrySalesCurrencies({
        date_from: nextFilters.date_from,
        date_to: nextFilters.date_to,
        branch_id: nextFilters.branch_id || activeBranchId,
      });
      const rows = rowsOf(data?.currencies || data);
      const codes = rows.map(currencyCodeOf).filter(Boolean);
      const previousCodes = Array.isArray(nextFilters.currency_codes) ? nextFilters.currency_codes : [];
      const retainedCodes = previousCodes.filter((code) => codes.includes(code));
      const selectedCodes = retainedCodes.length ? retainedCodes : codes;
      const resolvedFilters = {
        ...nextFilters,
        currency_codes: selectedCodes,
        currency_code: selectedCodes.length === 1 ? selectedCodes[0] : "",
      };
      setCurrencies(rows);
      setFilters(resolvedFilters);
      return resolvedFilters;
    } catch {
      setCurrencies([]);
      return nextFilters;
    }
  };

  const loadReport = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setReport(null);
      const data = await getFoodIndustrySalesTurnover(nextFilters);
      setReport(data);
      return true;
    } catch (error) {
      setReport(null);
      showNotice(
        error?.response?.data?.message ||
          error?.response?.data?.error ||
          error.message ||
          "Failed to load food industry sales report."
      );
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
    const loaded = await loadReport(nextFilters);
    if (loaded) {
      setAppliedFilters(nextFilters);
      setViewing(true);
    }
  };

  const refreshReport = async () => {
    const nextFilters = snapshotFilters(appliedFilters || filters);
    const loaded = await loadReport(nextFilters);
    if (!loaded) {
      setAppliedFilters(null);
      setViewing(false);
    }
  };

  const handleScopeChange = async (nextFilters, key) => {
    const resolvedFilters = key === "branch_id" ? await loadBusinessDates(nextFilters) : nextFilters;
    if (["branch_id", "date_from", "date_to"].includes(key)) await loadCurrencies(resolvedFilters);
  };

  useEffect(() => {
    if (!open) return;

    (async () => {
      const branchFilters = { ...filters, branch_id: activeBranchId || filters.branch_id };
      setFilters(branchFilters);
      const nextFilters = await loadBusinessDates(branchFilters);
      await loadCurrencies(nextFilters);
      setReport(null);
      setViewing(false);
      setAppliedFilters(null);
      setOutputFilters(null);
      setChooserOpen(false);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const currencyRows = rowsOf(report?.currency_summary);
  const categoryRows = rowsOf(report?.category_profit || report?.category_sales);
  const ingredientRows = rowsOf(report?.ingredient_consumption || report?.ingredient_cost);
  const hourlyRows = rowsOf(report?.hourly_sales);
  const paymentRows = rowsOf(report?.payments || report?.payment_summary);
  const cashierRows = rowsOf(report?.cashiers || report?.cashier_summary);
  const receiptRows = rowsOf(report?.receipt_detail || report?.receipts || report?.sales);

  const menuRows = useMemo(() => {
    const term = search.trim().toLowerCase();
    const rows = rowsOf(report?.menu_profitability);
    if (!term) return rows;

    return rows.filter((row) =>
      `${row.menu_item_name || ""} ${row.menu_item_code || ""} ${row.category_name || ""} ${row.currency_code || ""}`
        .toLowerCase()
        .includes(term)
    );
  }, [report, search]);

  const totals = useMemo(() => {
    const selectedCodes = Array.isArray(activeFilters.currency_codes) ? activeFilters.currency_codes : [];
    const useBaseCurrency = selectedCodes.length !== 1;
    const reportSummary = report?.summary || {};
    const source = currencyRows.length
      ? currencyRows
      : [
          {
            currency_code: selectedCodes[0] || "",
            net_sales: menuRows.reduce((sum, row) => sum + n(row.net_sales), 0),
            ingredient_cost: menuRows.reduce((sum, row) => sum + n(row.ingredient_cost), 0),
            gross_profit: menuRows.reduce((sum, row) => sum + n(row.gross_profit), 0),
            receipt_count: menuRows.reduce((sum, row) => sum + n(row.receipt_count || row.transaction_count), 0),
          },
        ];

    const netSales = reportSummary.net_sales ?? source.reduce((sum, row) => sum + n(useBaseCurrency ? row.base_net_sales : (row.net_sales || row.total)), 0);
    const ingredientCost = reportSummary.ingredient_cost ?? source.reduce((sum, row) => sum + n(useBaseCurrency ? row.base_ingredient_cost : row.ingredient_cost), 0);
    const grossProfit = reportSummary.gross_profit ?? source.reduce((sum, row) => sum + n(useBaseCurrency ? row.base_gross_profit : row.gross_profit), 0);
    const receipts = reportSummary.receipt_count ?? source.reduce((sum, row) => sum + n(row.receipt_count || row.transaction_count), 0);
    const qtySold = menuRows.reduce((sum, row) => sum + n(row.quantity_sold), 0);
    const currency = reportSummary.currency_code || (useBaseCurrency ? source[0]?.base_currency_code : selectedCodes[0]) || source[0]?.currency_code || "";

    return {
      currency,
      netSales,
      ingredientCost,
      grossProfit,
      receipts,
      qtySold,
      foodCostPercent: netSales > 0 ? (ingredientCost / netSales) * 100 : 0,
      marginPercent: netSales > 0 ? (grossProfit / netSales) * 100 : 0,
      avgCheck: receipts > 0 ? netSales / receipts : 0,
    };
  }, [activeFilters.currency_codes, currencyRows, menuRows, report?.summary]);

  const useBaseCurrency = (activeFilters.currency_codes || []).length !== 1;
  const hourlyValues = useMemo(() => buildHourlyValues(hourlyRows, useBaseCurrency), [hourlyRows, useBaseCurrency]);
  const bestHour = useMemo(() => hourlyValues.reduce((best, row) => (row.net_sales > best.net_sales ? row : best), { hour: "-", net_sales: 0 }), [hourlyValues]);

  const downloadReport = async (format) => {
    try {
      setExporting(true);
      const nextFilters = snapshotFilters(outputFilters || appliedFilters || filters);
      if (format === "excel") await downloadFoodIndustrySalesExcel(nextFilters);
      else await downloadFoodIndustrySalesPdf(nextFilters);
      setChooserOpen(false);
    } catch (error) {
      showNotice(error?.response?.data?.message || error.message || "Report download failed.");
      setChooserOpen(false);
    } finally {
      setExporting(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="Food Industry Sales Reports"
      subtitle="Menu turnover, recipe cost of sales, gross profit and business trading-date performance"
    >
      <div className="relative h-full min-h-0 overflow-hidden bg-[#E9EEEE] text-[#122424] text-[11px]">
        {notice && (
          <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 whitespace-pre-line border border-[#B42318] bg-white px-4 py-2 text-[12px] font-semibold text-[#B42318] shadow-xl">
            {notice}
          </div>
        )}

        <div className="flex h-full min-h-0">
          <HospitalityReportNavigator
            section="sales"
            activeKey={HOSPITALITY_REPORT_KEYS.DETAILED_SALES}
            onNavigate={onNavigateReport}
            groups={[
              {
                title: "Detailed sales views",
                items: tabs,
                activeKey: activeTab,
                onChange: setActiveTab,
              },
            ]}
          />
          <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            {!viewing && <header className="shrink-0 bg-white">
              <HospitalityReportScopePanel
                filters={filters}
                setFilters={setFilters}
                lookups={scopeLookups}
                loading={loading}
                onApply={() => openChooser("configuration")}
                onFilterChange={handleScopeChange}
              />
            </header>}

            {!viewing ? <div className="min-h-0 flex-1 overflow-y-auto p-2">
              <ReportGenerationPrompt
                title="Configure Food Industry Sales Report"
                message="Choose the branch, period, till, cashier, shift and currencies, then select Generate."
              />
            </div> : <ReportViewShell
              title="Hospitality Sales Analysis"
              subtitle="Menu turnover, recipe cost of sales and gross profit"
              backLabel="Configuration / Back"
              onBackToFilters={() => setViewing(false)}
              busy={loading}
              actions={<>
                <button type="button" onClick={refreshReport} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#AEB9B9] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
                <button type="button" onClick={() => openChooser("result")} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#069494] bg-white px-3 text-[11px] font-semibold text-[#008585] disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
              </>}
            >
              <section className="shrink-0 border-b border-[#AEB9B9] bg-[#F6F8FA] px-3 py-2">
                <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-8">
                  <Kpi label="Net Turnover" value={money(totals.netSales, totals.currency)} tone="blue" />
                  <Kpi label="Cost of Sales (Recipe)" value={money(totals.ingredientCost, totals.currency)} tone="amber" />
                  <Kpi label="Gross Profit" value={money(totals.grossProfit, totals.currency)} tone={totals.grossProfit >= 0 ? "green" : "red"} />
                  <Kpi label="Food Cost %" value={pct(totals.foodCostPercent)} tone={totals.foodCostPercent > 45 ? "red" : totals.foodCostPercent > 35 ? "amber" : "green"} />
                  <Kpi label="Gross Margin" value={pct(totals.marginPercent)} tone="teal" />
                  <Kpi label="Receipts" value={qty(totals.receipts, 0)} />
                  <Kpi label="Qty Sold" value={qty(totals.qtySold)} />
                  <Kpi label="Peak Hour" value={bestHour.hour === "-" ? "-" : hourLabel(bestHour.hour)} sub={money(bestHour.net_sales, totals.currency)} />
                </div>
              </section>
              <section className="shrink-0 border-b border-[#AEB9B9] bg-white px-3 py-2">
                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search menu item, code, category, currency" className="h-8 w-full max-w-[420px] border border-[#AEB9B9] bg-white px-3 text-[11px] text-[#122424] outline-none placeholder:text-[#93A6A6] focus:border-[#057B7C]" />
              </section>
              <main className="p-2">
              {activeTab === "overview" ? (
                <OverviewTables
                  currencyRows={currencyRows}
                  menuRows={menuRows}
                  categoryRows={categoryRows}
                  ingredientRows={ingredientRows}
                  currency={totals.currency}
                  currencyCodes={activeFilters.currency_codes}
                />
              ) : activeTab === "menu" ? (
                <MenuProfitabilityTable rows={menuRows} currencyCodes={activeFilters.currency_codes} />
              ) : activeTab === "category" ? (
                <CategorySalesTable rows={categoryRows} currencyCodes={activeFilters.currency_codes} />
              ) : activeTab === "ingredients" ? (
                <IngredientCostTable rows={ingredientRows} currency={totals.currency} currencyCodes={activeFilters.currency_codes} />
              ) : activeTab === "curve" ? (
                <HourlySalesTable rows={hourlyValues} currency={totals.currency} currencyCodes={activeFilters.currency_codes} />
              ) : (
                <OperationsTables paymentRows={paymentRows} cashierRows={cashierRows} receiptRows={receiptRows} currency={totals.currency} currencyCodes={activeFilters.currency_codes} />
              )}
              </main>
            </ReportViewShell>}
          </div>
        </div>

      </div>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle="Hospitality Sales Analysis"
        busy={exporting}
        busyLabel="Preparing hospitality sales report…"
        onClose={() => setChooserOpen(false)}
        onView={viewReport}
        onDownloadPdf={() => downloadReport("pdf")}
        onDownloadExcel={() => downloadReport("excel")}
        outputs={chooserSource === "result" ? { view: { disabled: true, reason: "This report is already open." } } : undefined}
      />
    </FullScreenModal>
  );
};

const buildHourlyValues = (rows = [], useBaseCurrency = false) => Array.from({ length: 24 }).map((_, hour) => {
  const hourRows = rows.filter((row) => Number(row.hour) === hour);
  return {
    hour,
    byCurrency: Object.fromEntries(hourRows.map((row) => [String(row.currency_code || "").toUpperCase(), row])),
    receipt_count: hourRows.reduce((sum, row) => sum + n(row.receipt_count || row.transaction_count), 0),
    net_sales: hourRows.reduce((sum, row) => sum + n(useBaseCurrency ? row.base_net_sales : (row.net_sales || row.total)), 0),
    ingredient_cost: hourRows.reduce((sum, row) => sum + n(row.ingredient_cost), 0),
    gross_profit: hourRows.reduce((sum, row) => sum + n(row.gross_profit), 0),
  };
});

const hourInRange = (hour, range) => {
  const cleanHour = Number(hour);
  const [start, end] = range;
  if (start <= end) return cleanHour >= start && cleanHour <= end;
  return cleanHour >= start || cleanHour <= end;
};

const Kpi = ({ label, value, sub, tone = "slate" }) => {
  const tones = {
    slate: "border-[#C6CFCF] bg-white text-[#122424]",
    blue: "border-[#ADE5E4] bg-[#EFF7F7] text-[#006E6E]",
    teal: "border-[#93F5F4] bg-[#F0FDFA] text-[#057B7B]",
    green: "border-[#BBF7D0] bg-[#F0FDF4] text-[#15803D]",
    amber: "border-[#FED7AA] bg-[#FFFBEB] text-[#B45309]",
    red: "border-[#FECACA] bg-[#FEF2F2] text-[#B42318]",
  };

  return (
    <div className={`border p-2 ${tones[tone] || tones.slate}`}>
      <div className="text-[9px] font-semibold uppercase tracking-wide text-[#627474]">{label}</div>
      <div className="mt-1 truncate text-[16px] font-semibold tabular-nums">{value}</div>
      {sub && <div className="mt-0.5 truncate text-[9px] font-normal text-[#627474]">{sub}</div>}
    </div>
  );
};

const Section = ({ title, subtitle, children }) => (
  <section className="border border-[#AEB9B9] bg-white">
    <div className="border-b border-[#C6CFCF] bg-[#F5F7FA] px-3 py-2">
      <div className="text-[12px] font-semibold uppercase tracking-[0.08em] text-[#003636]">{title}</div>
      {subtitle && <div className="mt-0.5 text-[10px] font-normal text-[#627474]">{subtitle}</div>}
    </div>
    {children}
  </section>
);

const OverviewTables = ({ currencyRows, menuRows, categoryRows, ingredientRows, currency, currencyCodes = [] }) => {
  const byCurrency = Object.fromEntries(currencyRows.map((row) => [String(row.currency_code || "").toUpperCase(), row]));
  return <div className="grid gap-2 2xl:grid-cols-[0.9fr_1.1fr]">
    <Section title="Currency Executive Summary" subtitle="Turnover, food cost and gross profit by currency.">
      <Table
        columns={[["Metric", "currency"], ...currencyCodes.map((currencyCode) => [currencyCode, "money"])]}
        rows={[
          { currency: "Net Sales", money: currencyCodes.map((currencyCode) => n(byCurrency[currencyCode]?.net_sales).toFixed(2)) },
          { currency: "Cost of Sales (Recipe)", money: currencyCodes.map((currencyCode) => n(byCurrency[currencyCode]?.ingredient_cost).toFixed(2)) },
          { currency: "Gross Profit", money: currencyCodes.map((currencyCode) => n(byCurrency[currencyCode]?.gross_profit).toFixed(2)) },
          { currency: "Food Cost %", money: currencyCodes.map((currencyCode) => pct(byCurrency[currencyCode]?.food_cost_percent)) },
          { currency: "Gross Margin %", money: currencyCodes.map((currencyCode) => pct(byCurrency[currencyCode]?.gross_margin_percent)) },
        ]}
      />
    </Section>

    <Section title="Top Menu Sales" subtitle="Menu items ranked by net turnover.">
      <MenuProfitabilityTable rows={[...menuRows].sort((a, b) => n(b.base_net_sales) - n(a.base_net_sales)).slice(0, 24)} currencyCodes={currencyCodes} compact />
    </Section>

    <Section title="Category Sales" subtitle="Food categories with sales, cost and profit.">
      <CategorySalesTable rows={categoryRows.slice(0, 24)} currencyCodes={currencyCodes} compact />
    </Section>

    <Section title="Ingredient Cost Drivers" subtitle="Highest ingredient cost contributors.">
      <IngredientCostTable rows={[...ingredientRows].sort((a, b) => n(b.base_consumed_cost) - n(a.base_consumed_cost)).slice(0, 24)} currency={currency} currencyCodes={currencyCodes} compact />
    </Section>
  </div>
};

const MenuProfitabilityTable = ({ rows, currencyCodes = [], compact = false }) => {
  const products = pivotByCurrency(rows, (row) => row.menu_product_id || row.menu_item_name);
  return (
  <ReportTable
    minWidth={Math.max(compact ? 980 : 1250, 420 + currencyCodes.length * 650)}
    columns={[
      { label: "Menu Item", className: "min-w-[240px]" },
      { label: "Category" },
      { label: "Qty Sold", right: true },
      ...currencyCodes.flatMap((currencyCode) => ["Avg Selling", "Net Sales", "COGS (Recipe)", "Gross Profit", "Food Cost %", "Profit / Item"].map((label) => ({ label: `${currencyCode} ${label}`, right: true }))),
    ]}
    rows={products.map((entry, index) => ({
      key: safeKey(entry.first.menu_product_id, index),
      cells: [
        <NameCell name={entry.first.menu_item_name} sub={entry.first.menu_item_code || "-"} />,
        entry.first.category_name || "Uncategorised",
        qty(entry.rows.reduce((sum, row) => sum + n(row.quantity_sold), 0)),
        ...currencyCodes.flatMap((currencyCode) => { const row = entry.byCurrency[currencyCode]; return row ? [n(row.average_selling_price).toFixed(2), n(row.net_sales).toFixed(2), <Value key={`${currencyCode}-cost`} tone="warn">{n(row.ingredient_cost).toFixed(2)}</Value>, <Value key={`${currencyCode}-profit`} tone={n(row.gross_profit) >= 0 ? "good" : "bad"}>{n(row.gross_profit).toFixed(2)}</Value>, <FoodCostPill key={`${currencyCode}-food-cost`} value={row.food_cost_percent} />, n(row.profit_per_item).toFixed(2)] : ["-", "-", "-", "-", "-", "-"]; }),
      ],
    }))}
  />
  );
};

const CategorySalesTable = ({ rows, currencyCodes = [], compact = false }) => {
  const categories = pivotByCurrency(rows, (row) => row.category_id || row.category_name);
  return (
  <ReportTable
    minWidth={Math.max(compact ? 900 : 1120, 380 + currencyCodes.length * 560)}
    columns={[
      { label: "Category", className: "min-w-[240px]" },
      { label: "Menu Items", right: true },
      { label: "Qty Sold", right: true },
      ...currencyCodes.flatMap((currencyCode) => ["Net Sales", "COGS (Recipe)", "Gross Profit", "Food Cost %", "Margin %"].map((label) => ({ label: `${currencyCode} ${label}`, right: true }))),
    ]}
    rows={categories.map((entry, index) => ({
      key: safeKey(entry.first.category_id, entry.first.category_name, index),
      cells: [
        <NameCell name={entry.first.category_name || "Uncategorised"} sub={`${entry.rows.reduce((sum, row) => sum + n(row.menu_count), 0)} menu items`} />,
        qty(entry.rows.reduce((sum, row) => sum + n(row.menu_count), 0), 0),
        qty(entry.rows.reduce((sum, row) => sum + n(row.quantity_sold), 0)),
        ...currencyCodes.flatMap((currencyCode) => { const row = entry.byCurrency[currencyCode]; return row ? [n(row.net_sales).toFixed(2), <Value key={`${currencyCode}-cost`} tone="warn">{n(row.ingredient_cost).toFixed(2)}</Value>, <Value key={`${currencyCode}-profit`} tone={n(row.gross_profit) >= 0 ? "good" : "bad"}>{n(row.gross_profit).toFixed(2)}</Value>, <FoodCostPill key={`${currencyCode}-food-cost`} value={row.food_cost_percent} />, pct(row.gross_margin_percent)] : ["-", "-", "-", "-", "-"]; }),
      ],
    }))}
  />
  );
};

const IngredientCostTable = ({ rows, currency, currencyCodes = [], compact = false }) => {
  const useBaseCurrency = currencyCodes.length !== 1;
  const totalCost = rows.reduce((sum, row) => sum + n(useBaseCurrency ? row.base_consumed_cost : row.consumed_cost), 0);
  const ingredients = pivotByCurrency(rows, (row) => `${row.sale_date}|${row.ingredient_product_id || row.ingredient_name}`);

  return (
    <div className="space-y-2">
      {!compact && (
        <div className="grid gap-2 md:grid-cols-3">
          <Kpi label="Total Ingredient Cost" value={money(totalCost, currency)} tone="amber" />
          <Kpi label="Consumed Quantity" value={qty(rows.reduce((sum, row) => sum + n(row.consumed_quantity), 0))} />
          <Kpi label="Ingredient Lines" value={qty(rows.length, 0)} />
        </div>
      )}
      <ReportTable
        minWidth={Math.max(compact ? 900 : 1160, 520 + currencyCodes.length * 300)}
        columns={[
          { label: "Date" },
          { label: "Ingredient", className: "min-w-[260px]" },
          { label: "Consumed Qty", right: true },
          { label: "UOM" },
          ...currencyCodes.flatMap((currencyCode) => [{ label: `${currencyCode} Unit Cost`, right: true }, { label: `${currencyCode} Total Cost`, right: true }]),
        ]}
        rows={ingredients.map((entry, index) => ({
          key: safeKey(entry.first.sale_date, entry.first.ingredient_product_id, index),
          cells: [
            entry.first.sale_date || "-",
            <NameCell name={entry.first.ingredient_name || "Ingredient"} sub={entry.first.ingredient_code || "-"} />,
            qty(entry.rows.reduce((sum, row) => sum + n(row.consumed_quantity), 0)),
            entry.first.uom_code || entry.first.uom || "-",
            ...currencyCodes.flatMap((currencyCode) => { const row = entry.byCurrency[currencyCode]; return row ? [n(row.average_unit_cost || row.unit_cost).toFixed(2), <Value key={`${currencyCode}-cost`} tone="warn">{n(row.consumed_cost).toFixed(2)}</Value>] : ["-", "-"]; }),
          ],
        }))}
      />
    </div>
  );
};

const HourlySalesTable = ({ rows, currency, currencyCodes = [] }) => {
  const total = rows.reduce((sum, row) => sum + n(row.net_sales), 0);
  const max = Math.max(...rows.map((row) => n(row.net_sales)), 1);
  const groups = HOUR_GROUPS.map((group) => {
    const matched = rows.filter((row) => hourInRange(row.hour, group.range));
    return {
      ...group,
      receipts: matched.reduce((sum, row) => sum + n(row.receipt_count), 0),
      net_sales: matched.reduce((sum, row) => sum + n(row.net_sales), 0),
      byCurrency: Object.fromEntries(currencyCodes.map((currencyCode) => [currencyCode, matched.reduce((sum, row) => sum + n(row.byCurrency?.[currencyCode]?.net_sales), 0)])),
      ingredient_cost: matched.reduce((sum, row) => sum + n(row.ingredient_cost), 0),
      gross_profit: matched.reduce((sum, row) => sum + n(row.gross_profit), 0),
    };
  });

  return (
    <div className="grid gap-2 xl:grid-cols-[1fr_340px]">
      <Section title="Hourly Sales Curve" subtitle="Clean trading curve and exact hour-by-hour values.">
        <div className="p-3">
          <div className="border border-[#C6CFCF] bg-[#F7F9FB] p-3">
            <div className="grid h-[230px] items-end gap-[4px]" style={{ gridTemplateColumns: "repeat(24, minmax(0, 1fr))" }}>
              {rows.map((row) => {
                const height = row.net_sales <= 0 ? 2 : Math.max(8, (row.net_sales / max) * 100);
                return (
                  <div key={row.hour} className="flex h-full flex-col justify-end text-center">
                    <div className="border border-white bg-[#057B7C]" title={`${hourLabel(row.hour)} · ${money(row.net_sales, currency)}`} style={{ height: `${height}%`, opacity: row.net_sales > 0 ? 1 : 0.16 }} />
                    <div className="mt-1 text-[7px] text-[#627474]">{row.hour}</div>
                  </div>
                );
              })}
            </div>
          </div>
          <div className="mt-2">
            <ReportTable
              minWidth={820}
              columns={[
                { label: "Hour" },
                { label: "Receipts", right: true },
                ...currencyCodes.map((currencyCode) => ({ label: `${currencyCode} Sales`, right: true })),
                { label: "Sales Share", right: true },
                { label: "Trading Load" },
              ]}
              rows={rows.map((row) => ({
                key: row.hour,
                cells: [
                  hourLabel(row.hour),
                  qty(row.receipt_count, 0),
                  ...currencyCodes.map((currencyCode) => row.byCurrency?.[currencyCode] ? n(row.byCurrency[currencyCode].net_sales).toFixed(2) : "-"),
                  pct(total > 0 ? (row.net_sales / total) * 100 : 0),
                  <LoadBar value={row.net_sales} max={max} />,
                ],
              }))}
            />
          </div>
        </div>
      </Section>

      <Section title="Daypart Summary" subtitle="Morning, lunch, dinner and late trading.">
        <ReportTable
          minWidth={320}
          columns={[
            { label: "Daypart" },
            { label: "Receipts", right: true },
            ...currencyCodes.map((currencyCode) => ({ label: `${currencyCode} Sales`, right: true })),
            { label: "Share", right: true },
          ]}
          rows={groups.map((row) => ({
            key: row.key,
            cells: [row.label, qty(row.receipts, 0), ...currencyCodes.map((currencyCode) => n(row.byCurrency?.[currencyCode]).toFixed(2)), pct(total > 0 ? (row.net_sales / total) * 100 : 0)],
          }))}
        />
      </Section>
    </div>
  );
};

const OperationsTables = ({ paymentRows, cashierRows, receiptRows, currencyCodes = [] }) => {
  const payments = pivotByCurrency(paymentRows, (row) => `${row.payment_name}|${row.money_code || ""}`);
  const cashiers = pivotByCurrency(cashierRows, (row) => row.cashier_id || row.cashier_name);
  return <div className="grid gap-2 2xl:grid-cols-2">
    <Section title="Payment Tender Summary" subtitle="Payments by method and currency.">
      <ReportTable
        minWidth={780}
        columns={[
          { label: "Payment" },
          { label: "Money Code" },
          { label: "Count", right: true },
          ...currencyCodes.flatMap((currencyCode) => [{ label: `${currencyCode} Amount`, right: true }, { label: `${currencyCode} Tendered`, right: true }, { label: `${currencyCode} Change`, right: true }]),
        ]}
        rows={payments.map((entry, index) => ({
          key: safeKey(entry.first.payment_name, index),
          cells: [entry.first.payment_name || "-", entry.first.money_code || "-", qty(entry.rows.reduce((sum, row) => sum + n(row.payment_count), 0), 0), ...currencyCodes.flatMap((currencyCode) => [amountColumn(entry, currencyCode, "amount"), amountColumn(entry, currencyCode, "amount_tendered"), amountColumn(entry, currencyCode, "change_amount")])],
        }))}
      />
    </Section>

    <Section title="Cashier Sales Performance" subtitle="Cashier turnover and receipt count.">
      <ReportTable
        minWidth={640}
        columns={[
          { label: "Cashier" },
          { label: "Receipts", right: true },
          ...currencyCodes.map((currencyCode) => ({ label: `${currencyCode} Net Sales`, right: true })),
        ]}
        rows={cashiers.map((entry, index) => ({
          key: safeKey(entry.first.cashier_id, index),
          cells: [entry.first.cashier_name || "Unknown", qty(entry.rows.reduce((sum, row) => sum + n(row.receipt_count || row.transaction_count), 0), 0), ...currencyCodes.map((currencyCode) => amountColumn(entry, currencyCode, "net_sales"))],
        }))}
      />
    </Section>

    <div className="2xl:col-span-2">
      <Section title="Receipt Detail" subtitle="Receipt-level turnover, recipe cost of sales and profit.">
        <ReportTable
          minWidth={1080}
          columns={[
            { label: "Receipt" },
            { label: "Date" },
            { label: "Lines", right: true },
            ...currencyCodes.map((currencyCode) => ({ label: `${currencyCode} Total`, right: true })),
          ]}
          rows={receiptRows.slice(0, 120).map((row, index) => ({
            key: safeKey(row.id, row.invoice_number, index),
            cells: [row.invoice_number || row.receipt_number || "-", row.transaction_date || row.sale_date || "-", receiptLineCount(row), ...currencyCodes.map((currencyCode) => rowCurrencyAmount(row, currencyCode, "total_amount"))],
          }))}
        />
      </Section>
    </div>
  </div>;
};

const ReportTable = ({ columns, rows, minWidth = 900 }) => (
  <div className="overflow-auto">
    <table className="sx-enterprise-table w-full border-collapse text-[11px]" style={{ minWidth }}>
      <thead>
        <tr className="border-b border-[#AEB9B9] bg-[#EEF2F2]">
          {columns.map((column) => (
            <th key={column.label} className={`whitespace-nowrap border-r border-[#D5DDDD] px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#324444] last:border-r-0 ${column.right ? "text-right" : "text-left"} ${column.className || ""}`}>
              {column.label}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 ? (
          <tr>
            <td colSpan={columns.length} className="px-3 py-10 text-center text-[12px] font-normal text-[#627474]">
              No records found.
            </td>
          </tr>
        ) : (
          rows.map((row) => (
            <tr key={row.key} className="border-b border-[#E3E9E9] odd:bg-white even:bg-[#FAFBFC] hover:bg-[#F0F7F5]">
              {row.cells.map((cell, index) => (
                <td key={index} className={`whitespace-nowrap border-r border-[#EEF2F2] px-2 py-2 align-top text-[#324443] last:border-r-0 ${columns[index]?.right ? "text-right tabular-nums" : "text-left"}`}>
                  {cell ?? "-"}
                </td>
              ))}
            </tr>
          ))
        )}
      </tbody>
    </table>
  </div>
);

const Table = ({ columns, rows }) => (
  <ReportTable
    minWidth={760}
    columns={columns.map(([label, key]) => ({ label, right: ["number", "money", "percent"].includes(key) }))}
    rows={rows.map((row, index) => {
      const moneyValues = Array.isArray(row.money) ? row.money : [row.money];
      const percentValues = Array.isArray(row.percent) ? row.percent : [row.percent];
      let moneyIndex = 0;
      let percentIndex = 0;
      return {
        key: index,
        cells: columns.map(([, key]) => {
          if (key === "money") return moneyValues[moneyIndex++] || money(0, row.currency);
          if (key === "percent") return pct(percentValues[percentIndex++] || 0);
          if (key === "number") return qty(row.number, 0);
          return row[key] ?? "-";
        }),
      };
    })}
  />
);

const NameCell = ({ name, sub }) => (
  <div className="min-w-0">
    <div className="max-w-[320px] truncate text-[11px] font-semibold text-[#122424]">{name || "-"}</div>
    {sub && <div className="mt-0.5 max-w-[320px] truncate text-[9px] font-normal text-[#627474]">{sub}</div>}
  </div>
);

const Value = ({ children, tone }) => {
  const classes = {
    good: "text-[#15803D]",
    bad: "text-[#B42318]",
    warn: "text-[#B45309]",
  };
  return <span className={`font-semibold ${classes[tone] || "text-[#122424]"}`}>{children}</span>;
};

const FoodCostPill = ({ value }) => {
  const clean = n(value);
  const className = clean > 45
    ? "border-[#FECACA] bg-[#FEF2F2] text-[#B42318]"
    : clean > 35
    ? "border-[#FED7AA] bg-[#FFFBEB] text-[#B45309]"
    : "border-[#BBF7D0] bg-[#F0FDF4] text-[#15803D]";

  return <span className={`inline-flex min-w-[58px] justify-center border px-2 py-0.5 text-[10px] font-semibold ${className}`}>{pct(clean)}</span>;
};

const LoadBar = ({ value, max }) => (
  <div className="h-2 w-full min-w-[120px] border border-[#D5DDDD] bg-white">
    <div className="h-full bg-[#057B7C]" style={{ width: `${Math.min(100, max > 0 ? (n(value) / max) * 100 : 0)}%` }} />
  </div>
);

export default FoodIndustrySalesReportModal;
