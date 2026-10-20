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
import {
  downloadFoodIndustryDailySalesExcel,
  downloadFoodIndustryDailySalesPdf,
  getFoodIndustryDailySales,
  getFoodIndustrySalesCurrencies,
  getFoodIndustrySalesBusinessDates,
} from "../../../services/FoodIndustrySalesReportService";

const today = () => new Date().toISOString().slice(0, 10);
const n = (value) => Number(value || 0);
const money = (value, currency = "") => `${currency ? `${currency} ` : ""}${n(value).toFixed(2)}`;
const qty = (value, decimals = 3) => n(value).toFixed(decimals);
const pct = (value) => `${n(value).toFixed(2)}%`;
const rowsOf = (value) => {
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  if (Array.isArray(value?.data?.data)) return value.data.data;
  return [];
};
const codeOf = (row) => row?.code || row?.currency_code || row?.base_currency_code || "";
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
const amountColumn = (entry, currencyCode, field) => entry?.byCurrency?.[currencyCode] ? n(entry.byCurrency[currencyCode][field]).toFixed(2) : "-";
const rowCurrencyAmount = (row, currencyCode, field) => String(row?.currency_code || "").toUpperCase() === currencyCode ? n(row?.[field]).toFixed(2) : "-";
const businessDateOf = (row) => row?.business_date || row?.trading_date || row?.date || "";
const formatBusinessDate = (value) => {
  if (!value) return "";
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
};
const snapshotFilters = (value = {}) => ({ ...value, currency_codes: [...(value.currency_codes || [])] });
const toApiFilters = (value = {}) => ({ ...snapshotFilters(value), date_from: value.business_date, date_to: value.business_date });

const DailyFoodIndustrySalesReportModal = ({ open, onClose, currentUser, onNavigateReport }) => {
  const activeBranchId =
    currentUser?.selectedBranch?.id ||
    currentUser?.selectedBranchId ||
    currentUser?.branch?.id ||
    currentUser?.branch_id ||
    "";
  const [filters, setFilters] = useState({
    business_date: today(),
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
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [search, setSearch] = useState("");
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
      const data = await getFoodIndustrySalesBusinessDates({ branch_id: baseFilters.branch_id });
      const rows = rowsOf(data?.business_dates || data?.dates || data);
      const selected = data?.current_business_date || data?.current_open_business_date || businessDateOf(rows[0]);

      if (selected && selected !== baseFilters.business_date) {
        const next = { ...baseFilters, business_date: selected, date_from: selected, date_to: selected };
        setFilters(next);
        return next;
      }
    } catch {
      // Keep the standard trading-date field available if day lookup fails.
    }

    return baseFilters;
  };

  const loadCurrencies = async (nextFilters) => {
    const currencyData = await getFoodIndustrySalesCurrencies({
      ...nextFilters,
      date_from: nextFilters.business_date,
      date_to: nextFilters.business_date,
    });
    const currencyRows = rowsOf(currencyData?.currencies || currencyData);
    const currencyCodes = currencyRows.map(codeOf).filter(Boolean);
    const previousCodes = Array.isArray(nextFilters.currency_codes) ? nextFilters.currency_codes : [];
    const retainedCodes = previousCodes.filter((code) => currencyCodes.includes(code));
    const selectedCodes = retainedCodes.length ? retainedCodes : currencyCodes;
    const resolved = { ...nextFilters, currency_codes: selectedCodes, currency_code: selectedCodes.length === 1 ? selectedCodes[0] : "" };
    setCurrencies(currencyRows);
    setFilters(resolved);
    return resolved;
  };

  const loadReport = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setReport(null);
      const data = await getFoodIndustryDailySales(toApiFilters(nextFilters));
      setReport(data);
      return true;
    } catch (error) {
      setReport(null);
      showNotice(error?.response?.data?.message || error?.message || "Failed to load daily sales report.");
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

  useEffect(() => {
    if (!open) return;

    (async () => {
      const nextFilters = await loadBusinessDates();
      try {
        await loadCurrencies(nextFilters);
      } catch {
        setCurrencies([]);
      } finally {
        setReport(null);
        setViewing(false);
        setAppliedFilters(null);
        setOutputFilters(null);
        setChooserOpen(false);
        setLoading(false);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const handleScopeChange = async (nextFilters, key) => {
    let resolved = nextFilters;
    if (key === "branch_id") resolved = await loadBusinessDates(nextFilters);
    if (["branch_id", "business_date"].includes(key)) await loadCurrencies(resolved);
  };

  const summary = report?.summary || {};
  const menuRowsRaw = rowsOf(report?.menu_profitability);
  const categoryRows = rowsOf(report?.category_profit || report?.category_sales);
  const paymentRows = rowsOf(report?.payments);
  const cashierRows = rowsOf(report?.cashiers);
  const ingredientRows = rowsOf(report?.ingredient_consumption);
  const hourlyRows = rowsOf(report?.hourly_sales);
  const receiptRows = rowsOf(report?.receipt_detail);
  const currencyRows = rowsOf(report?.currency_summary);

  const menuRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return menuRowsRaw;
    return menuRowsRaw.filter((row) =>
      `${row.menu_item_name || ""} ${row.menu_item_code || ""} ${row.category_name || ""} ${row.currency_code || ""}`
        .toLowerCase()
        .includes(q)
    );
  }, [menuRowsRaw, search]);

  const useBaseCurrency = (activeFilters.currency_codes || []).length !== 1;
  const reportCurrency = summary.currency_code || (useBaseCurrency ? currencyRows?.[0]?.base_currency_code : activeFilters.currency_codes?.[0]) || currencyRows?.[0]?.currency_code || menuRows?.[0]?.currency_code || "";
  const selectedCurrencyCodes = activeFilters.currency_codes || [];
  const currencySummaryByCode = Object.fromEntries(currencyRows.map((row) => [String(row.currency_code || "").toUpperCase(), row]));
  const menuCurrencyRows = pivotByCurrency(menuRows, (row) => row.menu_product_id || row.menu_item_name);
  const categoryCurrencyRows = pivotByCurrency(categoryRows, (row) => row.category_id || row.category_name);
  const ingredientCurrencyRows = pivotByCurrency(ingredientRows, (row) => `${row.sale_date}|${row.ingredient_product_id || row.ingredient_name}`);
  const paymentCurrencyRows = pivotByCurrency(paymentRows, (row) => `${row.payment_name}|${row.money_code || ""}`);
  const cashierCurrencyRows = pivotByCurrency(cashierRows, (row) => row.cashier_id || row.cashier_name);

  const downloadReport = async (format) => {
    try {
      setExporting(true);
      const nextFilters = snapshotFilters(outputFilters || appliedFilters || filters);
      const nextApiFilters = toApiFilters(nextFilters);
      if (format === "excel") await downloadFoodIndustryDailySalesExcel(nextApiFilters, `daily-sales-report-${nextFilters.business_date}.xlsx`);
      else await downloadFoodIndustryDailySalesPdf(nextApiFilters, `daily-sales-report-${nextFilters.business_date}.pdf`);
      setChooserOpen(false);
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Report download failed.");
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
      title="Daily Sales Report"
      subtitle="Food industry daily report: menu turnover, recipe cost of sales, gross profit, payments and receipts"
    >
      <div className="relative flex h-full min-h-0 overflow-hidden bg-[#DFE5E5] text-[#122424] text-[12px]">
        {notice && (
          <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 whitespace-pre-line border border-[#B42318] bg-white px-4 py-2 text-[12px] font-semibold text-[#B42318] shadow-xl">
            {notice}
          </div>
        )}

        <HospitalityReportNavigator
          section="sales"
          activeKey={HOSPITALITY_REPORT_KEYS.DAILY_SALES}
          onNavigate={onNavigateReport}
        />
        <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        {!viewing && <div className="shrink-0 bg-white">
          <HospitalityReportScopePanel
            filters={filters}
            setFilters={setFilters}
            lookups={scopeLookups}
            loading={loading}
            singleTradingDate
            onApply={() => openChooser("configuration")}
            onFilterChange={handleScopeChange}
          />
        </div>}

        {!viewing ? <div className="min-h-0 flex-1 overflow-y-auto p-2">
            <ReportGenerationPrompt
              title="Configure Daily Sales Report"
              message="Choose the trading date, branch and currencies, then select Generate."
            />
        </div> : <ReportViewShell
          title="Daily Sales Report"
          subtitle="Menu turnover, recipe cost of sales, gross profit, payments and receipts"
          backLabel="Configuration / Back"
          onBackToFilters={() => setViewing(false)}
          busy={loading}
          actions={<>
            <button type="button" onClick={refreshReport} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#ACB9B9] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
            <button type="button" onClick={() => openChooser("result")} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#069494] bg-white px-3 text-[11px] font-semibold text-[#008585] disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
          </>}
        >
          <div className="p-2">
            <div className="space-y-2">
              <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-6">
                <Stat label="Business Trading Date" value={formatBusinessDate(activeFilters.business_date) || activeFilters.business_date} />
                <Stat label="Receipts" value={summary.receipt_count || receiptRows.length || 0} />
                <Stat label="Menu Qty Sold" value={qty(summary.quantity_sold, 3)} />
                <Stat label="Sales Value" value={money(summary.net_sales, reportCurrency)} positive />
                <Stat label="Cost of Sales (Recipe)" value={money(summary.ingredient_cost, reportCurrency)} warning />
                <Stat label="Gross Profit" value={money(summary.gross_profit, reportCurrency)} positive={n(summary.gross_profit) >= 0} danger={n(summary.gross_profit) < 0} />
                <Stat label="Food Cost %" value={pct(summary.food_cost_percent)} warning={n(summary.food_cost_percent) > 35} />
                <Stat label="Gross Margin" value={pct(summary.gross_margin_percent)} positive />
                <Stat label="Average Receipt" value={money(summary.average_receipt, reportCurrency)} />
                <Stat label="Menu Items" value={summary.menu_count || menuRows.length || 0} />
                <Stat label="Ingredient Lines" value={ingredientRows.length} />
                <Stat label="Currencies" value={currencyRows.length || currencies.length || 0} />
              </div>

              <div className="grid gap-2 xl:grid-cols-2">
                <BarPanel title="Hourly Trading Curve" rows={hourlyRows} label={(r) => `${String(r.hour).padStart(2, "0")}:00`} valueKey={useBaseCurrency ? "base_net_sales" : "net_sales"} currencyKey={useBaseCurrency ? "base_currency_code" : "currency_code"} fallbackCurrency={reportCurrency} />
                <BarPanel title="Category Sales" rows={categoryRows} label={(r) => r.category_name || "Uncategorised"} valueKey={useBaseCurrency ? "base_net_sales" : "net_sales"} currencyKey={useBaseCurrency ? "base_currency_code" : "currency_code"} fallbackCurrency={reportCurrency} />
              </div>

              <div className="flex items-center justify-between border border-[#ACB8B8] bg-white p-2">
                <div>
                  <div className="text-[13px] font-semibold text-[#003636]">Detailed Daily Tables</div>
                  <div className="text-[11px] text-[#637877]">Tables use full modal width and horizontal scrolling only when the dataset is wider than the screen.</div>
                </div>
                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search menu item, code, category" className="h-8 w-[360px] max-w-[50vw] border border-[#ACB8B8] bg-white px-2 text-[12px] outline-none focus:border-[#069495]" />
              </div>

              <TableCard title="Currency Summary">
                <DataTable columns={["Metric", ...selectedCurrencyCodes]} rows={[
                  ["Menu Items", ...selectedCurrencyCodes.map((currencyCode) => currencySummaryByCode[currencyCode]?.menu_count || 0)],
                  ["Qty Sold", ...selectedCurrencyCodes.map((currencyCode) => qty(currencySummaryByCode[currencyCode]?.quantity_sold))],
                  ["Sales Value", ...selectedCurrencyCodes.map((currencyCode) => n(currencySummaryByCode[currencyCode]?.net_sales).toFixed(2))],
                  ["Cost of Sales (Recipe)", ...selectedCurrencyCodes.map((currencyCode) => n(currencySummaryByCode[currencyCode]?.ingredient_cost).toFixed(2))],
                  ["Gross Profit", ...selectedCurrencyCodes.map((currencyCode) => n(currencySummaryByCode[currencyCode]?.gross_profit).toFixed(2))],
                  ["Food Cost %", ...selectedCurrencyCodes.map((currencyCode) => pct(currencySummaryByCode[currencyCode]?.food_cost_percent))],
                  ["Gross Margin %", ...selectedCurrencyCodes.map((currencyCode) => pct(currencySummaryByCode[currencyCode]?.gross_margin_percent))],
                ]} />
              </TableCard>

              <TableCard title="Menu Item Profitability">
                <DataTable columns={["Menu Item", "Code", "Category", "Receipts", "Qty Sold", ...selectedCurrencyCodes.flatMap((currencyCode) => [`${currencyCode} Avg Selling`, `${currencyCode} Sales`, `${currencyCode} COGS (Recipe)`, `${currencyCode} Gross Profit`, `${currencyCode} Food Cost %`, `${currencyCode} Profit / Item`])]} rows={menuCurrencyRows.map((entry) => [entry.first.menu_item_name, entry.first.menu_item_code, entry.first.category_name, entry.rows.reduce((sum, row) => sum + n(row.receipt_count), 0), qty(entry.rows.reduce((sum, row) => sum + n(row.quantity_sold), 0)), ...selectedCurrencyCodes.flatMap((currencyCode) => { const row = entry.byCurrency[currencyCode]; return row ? [n(row.average_selling_price).toFixed(2), n(row.net_sales).toFixed(2), n(row.ingredient_cost).toFixed(2), n(row.gross_profit).toFixed(2), pct(row.food_cost_percent), n(row.profit_per_item).toFixed(2)] : ["-", "-", "-", "-", "-", "-"]; })])} />
              </TableCard>

              <TableCard title="Category Sales">
                <DataTable columns={["Category", "Menu Items", "Qty Sold", ...selectedCurrencyCodes.flatMap((currencyCode) => [`${currencyCode} Sales`, `${currencyCode} COGS (Recipe)`, `${currencyCode} Gross Profit`, `${currencyCode} Food Cost %`, `${currencyCode} Margin %`])]} rows={categoryCurrencyRows.map((entry) => [entry.first.category_name, entry.rows.reduce((sum, row) => sum + n(row.menu_count), 0), qty(entry.rows.reduce((sum, row) => sum + n(row.quantity_sold), 0)), ...selectedCurrencyCodes.flatMap((currencyCode) => { const row = entry.byCurrency[currencyCode]; return row ? [n(row.net_sales).toFixed(2), n(row.ingredient_cost).toFixed(2), n(row.gross_profit).toFixed(2), pct(row.food_cost_percent), pct(row.gross_margin_percent)] : ["-", "-", "-", "-", "-"]; })])} />
              </TableCard>

              <TableCard title="Ingredient Cost Detail">
                <DataTable columns={["Date", "Ingredient", "Code", "UOM", "Receipts", "Consumed Qty", ...selectedCurrencyCodes.flatMap((currencyCode) => [`${currencyCode} Avg Unit Cost`, `${currencyCode} Total Food Cost`])]} rows={ingredientCurrencyRows.map((entry) => [entry.first.sale_date, entry.first.ingredient_name, entry.first.ingredient_code, entry.first.uom_code, entry.rows.reduce((sum, row) => sum + n(row.receipt_count), 0), qty(entry.rows.reduce((sum, row) => sum + n(row.consumed_quantity), 0), 4), ...selectedCurrencyCodes.flatMap((currencyCode) => { const row = entry.byCurrency[currencyCode]; return row ? [n(row.average_unit_cost).toFixed(2), n(row.consumed_cost).toFixed(2)] : ["-", "-"]; })])} />
              </TableCard>

              <TableCard title="Payment Summary">
                <DataTable columns={["Payment", "Money Code", "Count", ...selectedCurrencyCodes.flatMap((currencyCode) => [`${currencyCode} Amount`, `${currencyCode} Change`])]} rows={paymentCurrencyRows.map((entry) => [entry.first.payment_name, entry.first.money_code, entry.rows.reduce((sum, row) => sum + n(row.payment_count), 0), ...selectedCurrencyCodes.flatMap((currencyCode) => [amountColumn(entry, currencyCode, "amount"), amountColumn(entry, currencyCode, "change_amount")])])} />
              </TableCard>

              <TableCard title="Cashier Summary">
                <DataTable columns={["Cashier", "Receipts", ...selectedCurrencyCodes.map((currencyCode) => `${currencyCode} Sales`)]} rows={cashierCurrencyRows.map((entry) => [entry.first.cashier_name, entry.rows.reduce((sum, row) => sum + n(row.receipt_count), 0), ...selectedCurrencyCodes.map((currencyCode) => amountColumn(entry, currencyCode, "net_sales"))])} />
              </TableCard>

              <TableCard title="Receipt Detail">
                <DataTable columns={["Receipt", "Date", "Cashier", "Till", "Customer", "Payment", ...selectedCurrencyCodes.map((currencyCode) => `${currencyCode} Total`)]} rows={receiptRows.map((r) => [r.invoice_number, r.transaction_date, r.cashier_name, r.till_name, r.customer_name || "Walk-in", r.payment_method, ...selectedCurrencyCodes.map((currencyCode) => rowCurrencyAmount(r, currencyCode, "total_amount"))])} />
              </TableCard>
            </div>
          </div>
        </ReportViewShell>}
        </div>
      </div>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle="Daily Sales Report"
        busy={exporting}
        busyLabel="Preparing daily sales report…"
        onClose={() => setChooserOpen(false)}
        onView={viewReport}
        onDownloadPdf={() => downloadReport("pdf")}
        onDownloadExcel={() => downloadReport("excel")}
        outputs={chooserSource === "result" ? { view: { disabled: true, reason: "This report is already open." } } : undefined}
      />
    </FullScreenModal>
  );
};

const Stat = ({ label, value, positive = false, danger = false, warning = false }) => (
  <div className="border border-[#ACB8B8] bg-white p-3">
    <div className="text-[11px] font-semibold text-[#637877]">{label}</div>
    <div className={`mt-1 truncate text-[18px] font-semibold ${danger ? "text-[#B42318]" : positive ? "text-[#057B7C]" : warning ? "text-[#B54708]" : "text-[#003636]"}`}>{value}</div>
  </div>
);

const TableCard = ({ title, children }) => (
  <section className="overflow-hidden border border-[#ACB8B8] bg-white">
    <div className="border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2 text-[13px] font-semibold text-[#003636]">{title}</div>
    <div className="w-full overflow-x-auto">{children}</div>
  </section>
);

const DataTable = ({ columns, rows }) => {
  if (!rows || rows.length === 0) {
    return <div className="flex min-h-[96px] items-center justify-center text-[12px] text-[#637877]">No records found.</div>;
  }
  return (
    <table className="sx-enterprise-table w-full min-w-[980px] table-auto border-collapse text-[12px]">
      <thead>
        <tr className="border-b border-[#ACB8B8] bg-[#F3F6F8]">
          {columns.map((column) => <th key={column} className="whitespace-nowrap px-3 py-2 text-left text-[11px] font-semibold uppercase text-[#324444]">{column}</th>)}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, index) => (
          <tr key={index} className="border-b border-[#E3E9E9] hover:bg-[#F6F8FA]">
            {row.map((cell, cellIndex) => <td key={cellIndex} className="whitespace-nowrap px-3 py-2 text-[#324443]">{cell ?? "-"}</td>)}
          </tr>
        ))}
      </tbody>
    </table>
  );
};

const BarPanel = ({ title, rows, label, valueKey, currencyKey, fallbackCurrency = "" }) => {
  const max = Math.max(...(rows || []).map((row) => n(row?.[valueKey])), 1);
  return (
    <section className="border border-[#ACB8B8] bg-white">
      <div className="border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2 text-[13px] font-semibold text-[#003636]">{title}</div>
      <div className="space-y-2 p-3">
        {(rows || []).slice(0, 12).map((row, index) => {
          const value = n(row?.[valueKey]);
          const width = Math.max((value / max) * 100, 2);
          return (
            <div key={index} className="grid grid-cols-[130px_1fr_120px] items-center gap-2 text-[12px]">
              <div className="truncate text-[#324444]">{label(row)}</div>
              <div className="h-5 border border-[#CAD5D5] bg-[#F8FAFC]"><div className="h-full bg-[#069495]" style={{ width: `${width}%` }} /></div>
              <div className="text-right font-semibold text-[#003636]">{money(value, row?.[currencyKey] || fallbackCurrency)}</div>
            </div>
          );
        })}
        {(!rows || rows.length === 0) && <div className="py-8 text-center text-[#637877]">No chart data.</div>}
      </div>
    </section>
  );
};

export default DailyFoodIndustrySalesReportModal;
