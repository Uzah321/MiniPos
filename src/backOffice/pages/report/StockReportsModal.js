// src/backOffice/pages/report/StockReportsModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import { List, ListItemButton, ListItemText, Typography } from "@mui/material";
import { Download, RefreshCw, Search, X } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel from "../../../components/HospitalityReportScopePanel";
import { LEGACY_STOCK_ADJUSTMENT_REPORT_KEY } from "../../../components/InventoryReportNavigator";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getInventoryWarehouses } from "../../../services/EnterpriseInventoryService";
import {
  getProducts,
  getProductCategories,
  getSuppliers
} from "../../../services/ProductService";

import {
  getStockDashboard,
  getCompanyStockBalance,
  getBranchStockBalance,
  getStockBalance,
  getStockAsOfBalance,
  getStockMovements,
  getProductStockLedger,
  getStockReceivedReport,
  getStockAdjustmentsReport,
  getStockValuationReport,
  getLowStockReport,
  getOutOfStockReport,
  getNegativeStockReport,
  getStockCategorySummary,
  getStockSupplierSummary,
  downloadStockReport,
} from "../../../services/StockReportService";

const StockReportBusinessModeContext = React.createContext("Hospitality");

const REPORTS = [
  ["dashboard", "Stock Dashboard", "Summary, alerts, average-cost stock value and recent movements."],
  ["company-balance", "Company Balance", "Company stock valued at product average cost."],
  ["branch-balance", "Branch Balance", "Branch stock valued at product average cost."],
  ["current-balance", "Current Balance", "Current stock quantity and average-cost value."],
  ["as-of", "As-Of Balance", "Historical quantity and average-cost value."],
  ["movements", "Stock Movements", "Complete inventory movement ledger."],
  ["received", "Stock Received", "Goods received and stock-in tracking."],
  ["adjustments", "Stock Adjustments", "Average-cost impact of adjustments, stock take and wastage."],
  ["valuation", "Stock Valuation", "Inventory valued at each product's average cost."],
  ["low-stock", "Low Stock", "Products below reorder level."],
  ["out-of-stock", "Out Of Stock", "Products at zero or below stock."],
  ["negative-stock", "Negative Stock", "Products with negative stock."],
  ["category-summary", "Category Summary", "Average-cost inventory grouped by category."],
  ["supplier-summary", "Supplier Summary", "Average-cost inventory grouped by supplier."],
].map(([key, title, description]) => ({ key, title, description }));

const PAGINATED_REPORT_KEYS = {
  "Company Stock Balance": "company-balance",
  "Branch Stock Balance": "branch-balance",
  "Current Stock Balance": "current-balance",
  "Stock Balance As-Of": "as-of",
  "Stock Received": "received",
  "Stock Valuation": "valuation",
  "Low Stock": "low-stock",
  "Out Of Stock": "out-of-stock",
  "Negative Stock": "negative-stock",
  "Category Stock Summary": "category-summary",
  "Supplier Stock Summary": "supplier-summary",
};

const MOVEMENT_TYPES = [
  "",
  "opening",
  "receive",
  "sale",
  "sale_void",
  "return",
  "adjustment_in",
  "adjustment_out",
  "break_parent_out",
  "break_child_in",
  "wastage",
  "stock_take_gain",
  "stock_take_loss",
];

const today = () => new Date().toISOString().slice(0, 10);
const defaultFilters = () => ({
  date_from: today(),
  date_to: today(),
  as_of: today(),
  branch_id: "",
  warehouse_id: "",
  category_id: "",
  supplier_id: "",
  product_id: "",
  movement_type: "",
  q: "",
  per_page: 500,
  page: 1,
});

const snapshotFilters = (source = {}) => ({ ...source });

const currentBalanceFilters = () => {
  const filters = defaultFilters();
  delete filters.date_from;
  delete filters.date_to;
  delete filters.as_of;
  return filters;
};

const number = (value, decimals = 2) => Number(value || 0).toFixed(decimals);
const qty = (value) => Number(value || 0).toFixed(4);
const money = (value, currency = "") => {
  const amount = Number(value || 0).toFixed(2);
  return currency ? `${currency} ${amount}` : amount;
};

const toNum = (value) => Number(value || 0);

const currencyCodeOf = (item = {}) =>
  item?.cost_currency_code || item?.currency_code || item?.currency || "";

const currencyTotalsOf = (summary = null) => {
  const source = summary?.currency_totals ?? summary?.by_currency;

  if (Array.isArray(source)) return source;

  if (source && typeof source === "object") {
    return Object.entries(source).map(([currencyCode, value]) =>
      value && typeof value === "object"
        ? { ...value, cost_currency_code: currencyCodeOf(value) || currencyCode }
        : { cost_currency_code: currencyCode, stock_value: value, cost_value: value }
    );
  }

  return [];
};

const firstDefined = (source = {}, keys = []) => {
  for (const key of keys) {
    if (source?.[key] !== undefined && source?.[key] !== null) return source[key];
  }
  return null;
};

const currencyValueItems = (summary, keys, label) => {
  const buckets = currencyTotalsOf(summary);

  if (buckets.length > 0) {
    return buckets
      .map((bucket) => {
        const value = firstDefined(bucket, keys);
        const currencyCode = currencyCodeOf(bucket);
        if (value === null || !currencyCode) return null;
        return [`${label} (${currencyCode})`, money(value, currencyCode)];
      })
      .filter(Boolean);
  }

  const value = firstDefined(summary, keys);
  const currencyCode = currencyCodeOf(summary);
  if (value === null || !currencyCode) return [];

  return [[`${label} (${currencyCode})`, money(value, currencyCode)]];
};

const adjustmentDirection = (row = {}) => {
  const increased = toNum(row.increased_quantity ?? row.quantity_in);
  const decreased = toNum(row.decreased_quantity ?? row.quantity_out);

  if (increased > 0 && increased >= decreased) return "increase";
  if (decreased > 0) return "decrease";

  const type = String(row.movement_type || row.adjustment_type || "").toLowerCase();
  if (type.includes("gain") || type.includes("_in") || type.includes("increase")) return "increase";
  if (type.includes("loss") || type.includes("_out") || type.includes("wastage") || type.includes("decrease")) return "decrease";

  return "neutral";
};

const computeAdjustmentInsight = (rows = [], summary = null) => {
  const totals = {
    adjustment_count: toNum(summary?.adjustment_count ?? rows.length),
    increased_quantity: toNum(summary?.increased_quantity),
    decreased_quantity: toNum(summary?.decreased_quantity),
    currency_totals: [],
  };

  if (!summary) {
    rows.forEach((row) => {
      totals.increased_quantity += toNum(row.increased_quantity ?? row.quantity_in);
      totals.decreased_quantity += toNum(row.decreased_quantity ?? row.quantity_out);
    });
  }

  const backendBuckets = currencyTotalsOf(summary);
  const scalarSummaryCurrency = currencyCodeOf(summary);
  const scalarSummaryHasCost = [
    "increased_cost_value",
    "decreased_cost_value",
    "cost_value",
  ].some((key) => summary?.[key] !== undefined && summary?.[key] !== null);
  const sourceBuckets = backendBuckets.length > 0
    ? backendBuckets
    : scalarSummaryCurrency && scalarSummaryHasCost
    ? [summary]
    : [];

  if (sourceBuckets.length > 0) {
    totals.currency_totals = sourceBuckets
      .map((bucket) => {
        const currencyCode = currencyCodeOf(bucket);
        if (!currencyCode) return null;
        const increasedCostValue = toNum(bucket?.increased_cost_value);
        const decreasedCostValue = toNum(bucket?.decreased_cost_value);
        return {
          cost_currency_code: currencyCode,
          increased_cost_value: increasedCostValue,
          decreased_cost_value: decreasedCostValue,
          net_cost_impact: increasedCostValue - decreasedCostValue,
          overall_cost_value: toNum(bucket?.cost_value),
        };
      })
      .filter(Boolean);
  } else {
    const grouped = new Map();
    rows.forEach((row) => {
      const currencyCode = row?.cost_currency_code || "";
      if (!currencyCode || row?.cost_value === undefined || row?.cost_value === null) return;
      const bucket = grouped.get(currencyCode) || {
        cost_currency_code: currencyCode,
        increased_cost_value: 0,
        decreased_cost_value: 0,
        overall_cost_value: 0,
      };
      const costValue = toNum(row.cost_value);
      const direction = adjustmentDirection(row);
      bucket.overall_cost_value += costValue;
      if (direction === "increase") bucket.increased_cost_value += costValue;
      if (direction === "decrease") bucket.decreased_cost_value += costValue;
      grouped.set(currencyCode, bucket);
    });
    totals.currency_totals = Array.from(grouped.values()).map((bucket) => ({
      ...bucket,
      net_cost_impact: bucket.increased_cost_value - bucket.decreased_cost_value,
    }));
  }

  return totals;
};

const adjustmentAiComment = (insight) => {
  if (!insight) return "No adjustment data is available for analysis.";

  const decreasedQty = toNum(insight.decreased_quantity);
  const increasedQty = toNum(insight.increased_quantity);
  const costBreakdown = (key) => insight.currency_totals
    .map((bucket) => money(bucket[key], bucket.cost_currency_code))
    .join(" / ");

  if (decreasedQty > increasedQty) {
    const loss = costBreakdown("decreased_cost_value");
    return `AI comment: Stock reductions exceed gains${loss ? ` (${loss} at average cost)` : ""}. Review stock-take variances, wastage and high-cost products first.`;
  }

  if (increasedQty > decreasedQty) {
    const impact = costBreakdown("net_cost_impact");
    return `AI comment: Stock gains exceed reductions${impact ? `, producing a net average-cost adjustment of ${impact}` : ""}. Confirm these gains come from valid recounts or receiving corrections.`;
  }

  return "AI comment: Adjustments are broadly balanced at average cost. Continue monitoring repeated products, high-cost variances and users posting frequent corrections.";
};

const formatDateTime = (value) => {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return new Intl.DateTimeFormat("en-ZW", {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
};
const getPayload = (response) => response?.data || response || {};

const normalizeOptionRows = (response) => {
  const payload = response?.data || response;

  if (Array.isArray(response)) return response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.company?.branches)) return payload.company.branches;
  if (Array.isArray(payload?.branches)) return payload.branches;

  return [];
};
const getRows = (response) => {
  const payload = getPayload(response);
  if (Array.isArray(response)) return response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
};
const getPaginatedRows = (response) => {
  const payload = getPayload(response);
  const data = payload?.data || payload;
  if (Array.isArray(data?.data)) return data.data;
  if (Array.isArray(data)) return data;
  return [];
};
const getResponseSummary = (response) => {
  return response?.summary || response?.data?.summary || null;
};

const getPaginationMeta = (response) => {
  const data =
    response?.data?.current_page !== undefined
      ? response.data
      : response?.data?.data?.current_page !== undefined
      ? response.data.data
      : response?.current_page !== undefined
      ? response
      : response?.data || response || {};

  return {
    current_page: Number(data?.current_page || 1),
    last_page: Number(data?.last_page || 1),
    total: Number(data?.total ?? (Array.isArray(data?.data) ? data.data.length : 0)),
    per_page: Number(data?.per_page || 50),
  };
};
const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  if (data?.errors && typeof data.errors === "object") return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};
const getBranchName = (branch) => branch?.branch_name || branch?.name || branch?.branch_code || branch?.code || "Unnamed Branch";
const getReportMeta = (key) => REPORTS.find((report) => report.key === key) || REPORTS[0];

const useStockLookups = (open) => {
  const [branches, setBranches] = useState([]);
  const [defaultBranchId, setDefaultBranchId] = useState("");
  const [warehouses, setWarehouses] = useState([]);
  const [categories, setCategories] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [products, setProducts] = useState([]);
  const [loadingLookups, setLoadingLookups] = useState(false);

  const loadLookups = async () => {
    try {
      setLoadingLookups(true);

      const [companyResult, warehouseResult, categoryResult, supplierResult, productResult] =
        await Promise.allSettled([
          getAuthorisedCompany(),
          getInventoryWarehouses(),
          getProductCategories({
            per_page: 500,
            is_active: true,
          }),
          getSuppliers({
            per_page: 500,
            is_active: true,
          }),
          getProducts({
            per_page: 1000,
            is_active: true,
          }),
        ]);

      const resultValue = (result) => result?.status === "fulfilled" ? result.value : null;
      const companyRes = resultValue(companyResult);
      const warehouseRes = resultValue(warehouseResult);
      const categoryRes = resultValue(categoryResult);
      const supplierRes = resultValue(supplierResult);
      const productRes = resultValue(productResult);

      const companyPayload = companyRes?.data || companyRes;
      const currentUser =
        companyPayload?.user ||
        companyPayload?.data?.user ||
        companyPayload?.pos_user ||
        companyPayload?.data?.pos_user ||
        companyPayload?.auth_user ||
        {};

      const nextBranches =
        companyPayload?.company?.branches ||
        companyPayload?.data?.company?.branches ||
        companyPayload?.branches ||
        [];

      setBranches(nextBranches);
      setWarehouses(
        normalizeOptionRows(warehouseRes).filter(
          (warehouse) => warehouse?.is_active !== false && Number(warehouse?.is_active ?? 1) !== 0
        )
      );
      setDefaultBranchId(
        currentUser?.branch_id ||
          localStorage.getItem("salexa_till_branch_id") ||
          nextBranches?.[0]?.id ||
          ""
      );

      setCategories(normalizeOptionRows(categoryRes));
      setSuppliers(normalizeOptionRows(supplierRes));
      setProducts(normalizeOptionRows(productRes));
    } catch {
      setBranches([]);
      setWarehouses([]);
      setCategories([]);
      setSuppliers([]);
      setProducts([]);
    } finally {
      setLoadingLookups(false);
    }
  };

  useEffect(() => {
    if (open) loadLookups();
  }, [open]);

  const branchById = useMemo(
    () => Object.fromEntries(branches.map((item) => [item.id, item])),
    [branches]
  );

  const categoryById = useMemo(
    () => Object.fromEntries(categories.map((item) => [item.id, item])),
    [categories]
  );

  const supplierById = useMemo(
    () => Object.fromEntries(suppliers.map((item) => [item.id, item])),
    [suppliers]
  );

  const productById = useMemo(
    () => Object.fromEntries(products.map((item) => [item.id, item])),
    [products]
  );

  return {
    branches,
    defaultBranchId,
    warehouses,
    categories,
    suppliers,
    products,
    branchById,
    categoryById,
    supplierById,
    productById,
    loadingLookups,
    loadLookups,
  };
};

const useReportLoader = (loader, initialFilters = defaultFilters()) => {
  const [filters, setFilters] = useState(initialFilters);
  const [response, setResponse] = useState(null);
  const [payload, setPayload] = useState(null);
  const [rows, setRows] = useState([]);
  const [paginatedRows, setPaginatedRows] = useState([]);
  const [meta, setMeta] = useState(getPaginationMeta(null));
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const load = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setError("");
      setResponse(null);
      setPayload(null);
      setRows([]);
      setPaginatedRows([]);
      setMeta(getPaginationMeta(null));
      setSummary(null);
      const res = await loader(nextFilters);
      setResponse(res);
      setPayload(getPayload(res));
      setRows(getRows(res));
      setPaginatedRows(getPaginatedRows(res));
      setMeta(getPaginationMeta(res));
      setSummary(getResponseSummary(res));
      return true;
    } catch (err) {
      setResponse(null);
      setPayload(null);
      setRows([]);
      setPaginatedRows([]);
      setMeta(getPaginationMeta(null));
      setSummary(null);
      setError(getErrorMessage(err));
      return false;
    } finally {
      setLoading(false);
    }
  };

  return { filters, setFilters, response, payload, rows, paginatedRows, meta, summary, loading, error, load };
};

const StockReportsModal = ({ open, onClose, initialReport = "dashboard", businessModeLabel = "Hospitality", allowedReports = null, onNavigateReport }) => {
  const visibleReports = useMemo(() => {
    return !Array.isArray(allowedReports) || allowedReports.length === 0
      ? REPORTS
      : REPORTS.filter((report) => new Set(allowedReports).has(report.key));
  }, [allowedReports]);
  const firstReport = visibleReports[0]?.key || "dashboard";
  const safeInitialReport = visibleReports.some((report) => report.key === initialReport) ? initialReport : firstReport;
  const [activeReport, setActiveReport] = useState(safeInitialReport);
  const selectedReport = visibleReports.some((report) => report.key === activeReport) ? activeReport : safeInitialReport;
  const lookups = useStockLookups(open);
  const meta = getReportMeta(selectedReport);

  useEffect(() => {
    if (open) setActiveReport(safeInitialReport);
  }, [open, safeInitialReport]);

  const openReport = (key) => {
    if (key === LEGACY_STOCK_ADJUSTMENT_REPORT_KEY) {
      onNavigateReport?.(key);
      return;
    }
    if (visibleReports.some((report) => report.key === key)) setActiveReport(key);
  };

  return (
    <StockReportBusinessModeContext.Provider value={businessModeLabel}>
    <FullScreenModal open={open} onClose={onClose} title="STOCK REPORTS" subtitle={meta.title}>
      <div className="flex h-full min-h-0 overflow-hidden bg-white text-[#293232]">
        <aside className="w-[350px] shrink-0 border-r border-[#D8DCDC] bg-white"><div className="flex h-14 items-center border-b border-[#D8DCDC] px-5 text-[15px] font-medium">Report list</div><List disablePadding sx={{ height: "calc(100% - 56px)", overflowY: "auto" }}><Typography component="div" sx={{ height: 44, display: "flex", alignItems: "center", px: 2.5, bgcolor: "#f7f8f9", fontSize: 13, fontWeight: 500, color: "#485353" }}>Inventory reports</Typography>{visibleReports.map((report) => <ListItemButton key={report.key} selected={selectedReport === report.key} onClick={() => openReport(report.key)} sx={{ minHeight: 48, px: 3, borderLeft: "2px solid", borderLeftColor: selectedReport === report.key ? "primary.main" : "transparent", "&.Mui-selected": { bgcolor: "primary.light", color: "primary.dark" } }}><ListItemText primary={report.title} secondary={report.description} primaryTypographyProps={{ fontSize: 13 }} secondaryTypographyProps={{ fontSize: 11, noWrap: true }} /></ListItemButton>)}</List></aside>
        <section className="flex min-w-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 overflow-y-auto bg-white">
            {selectedReport === "dashboard" && <StockDashboardReport lookups={lookups} />}
            {selectedReport === "company-balance" && <CompanyBalanceReport lookups={lookups} />}
            {selectedReport === "branch-balance" && <BranchBalanceReport lookups={lookups} />}
            {selectedReport === "current-balance" && <CurrentBalanceReport lookups={lookups} />}
            {selectedReport === "as-of" && <AsOfBalanceReport lookups={lookups} />}
            {selectedReport === "movements" && <StockMovementsReport lookups={lookups} />}
            {selectedReport === "received" && <StockReceivedReport lookups={lookups} />}
            {selectedReport === "adjustments" && <StockAdjustmentsReport lookups={lookups} />}
            {selectedReport === "valuation" && <StockValuationReport lookups={lookups} />}
            {selectedReport === "low-stock" && <LowStockReport lookups={lookups} />}
            {selectedReport === "out-of-stock" && <OutOfStockReport lookups={lookups} />}
            {selectedReport === "negative-stock" && <NegativeStockReport lookups={lookups} />}
            {selectedReport === "category-summary" && <CategorySummaryReport lookups={lookups} />}
            {selectedReport === "supplier-summary" && <SupplierSummaryReport lookups={lookups} />}
          </div>
        </section>
      </div>
    </FullScreenModal>
    </StockReportBusinessModeContext.Provider>
  );
};

const EnterpriseReportPanel = ({ title, description, reportKey, filters, setFilters, onApply, loading, error, children, lookups, showAsOf = false, showMovementType = false, showDateRange = true, defaultToLoggedBranch = true }) => {
  const [viewing, setViewing] = useState(false);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserSource, setChooserSource] = useState("configuration");
  const [outputFilters, setOutputFilters] = useState(null);
  const [appliedFilters, setAppliedFilters] = useState(null);
  const [exporting, setExporting] = useState(false);
  const [actionError, setActionError] = useState("");
  const businessModeLabel = React.useContext(StockReportBusinessModeContext);

  const openOutputChooser = (source = "configuration") => {
    const nextFilters = source === "result" && appliedFilters
      ? snapshotFilters(appliedFilters)
      : snapshotFilters(filters);
    setChooserSource(source);
    setOutputFilters(nextFilters);
    setActionError("");
    setChooserOpen(true);
  };

  const viewReport = async () => {
    const nextFilters = snapshotFilters(outputFilters || filters);
    setChooserOpen(false);
    setViewing(false);
    setActionError("");
    const loaded = await onApply(nextFilters);
    if (loaded !== false) {
      setAppliedFilters(nextFilters);
      setViewing(true);
    }
  };

  const refreshReport = async () => {
    setActionError("");
    await onApply(snapshotFilters(appliedFilters || filters));
  };

  const downloadReport = async (format) => {
    try {
      setExporting(true);
      setActionError("");
      if (!reportKey) throw new Error(`No backend download is configured for ${title || "this report"}.`);
      await downloadStockReport(
        reportKey,
        format === "excel" ? "xlsx" : "pdf",
        snapshotFilters(outputFilters || appliedFilters || filters),
      );
      setChooserOpen(false);
    } catch (downloadError) {
      setChooserOpen(false);
      setActionError(getErrorMessage(downloadError, "The backend could not generate this report."));
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="h-full min-h-full bg-white text-[#293232] print:bg-white">
      {viewing ? (
        <ReportViewShell
          title={title}
          subtitle={description}
          backLabel="Configuration / Back"
          onBackToFilters={() => setViewing(false)}
          busy={loading}
          actions={<>
            <button type="button" onClick={refreshReport} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#ACB8B8] bg-white px-3 text-[12px]"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
            <button type="button" onClick={() => openOutputChooser("result")} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#069495] bg-[#E8F7F5] px-3 text-[12px] font-medium text-[#006869] disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
          </>}
        >
          <ReportStatus loading={loading} error={error || actionError} />
          <div className="space-y-2 p-3">{children}</div>
        </ReportViewShell>
      ) : (
        <div className="min-h-full border-b border-[#D8DCDC] bg-white">
          <ReportStatus loading={loading} error={error || actionError} />
          <StockReportFilters filters={filters} setFilters={setFilters} onApply={() => openOutputChooser("configuration")} loading={loading} lookups={lookups} showAsOf={showAsOf} showMovementType={showMovementType} showDateRange={showDateRange} defaultToLoggedBranch={defaultToLoggedBranch} businessModeLabel={businessModeLabel} />
        </div>
      )}
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle={title}
        busy={exporting}
        busyLabel="Preparing report download…"
        onClose={() => setChooserOpen(false)}
        onView={viewReport}
        onDownloadPdf={() => downloadReport("pdf")}
        onDownloadExcel={() => downloadReport("excel")}
        outputs={chooserSource === "result" ? { view: { disabled: true, reason: "This report is already open." } } : undefined}
      />
    </div>
  );
};

const StockReportFilters = ({ filters, setFilters, onApply, loading, lookups, showAsOf = false, showMovementType = false, showDateRange = true, defaultToLoggedBranch = true, businessModeLabel = "Hospitality" }) => {
  const update = (key, value) => setFilters((prev) => ({ ...prev, [key]: value, page: key === "page" ? value : 1 }));
  const effectiveBranchId = filters.branch_id || (defaultToLoggedBranch ? lookups?.defaultBranchId : "") || "";
  const visibleWarehouses = useMemo(
    () => (lookups?.warehouses || []).filter(
      (warehouse) =>
        !effectiveBranchId ||
        !warehouse?.branch_id ||
        String(warehouse.branch_id) === String(effectiveBranchId)
    ),
    [effectiveBranchId, lookups?.warehouses]
  );

  const setScopeFilters = (next) => {
    setFilters((current) => {
      const nextBranchId = next.branch_id || "";
      return {
        ...current,
        ...(showDateRange
          ? {
              date_from: next.date_from ?? current.date_from,
              date_to: next.date_to ?? current.date_to,
            }
          : {}),
        branch_id: nextBranchId,
        warehouse_id: String(nextBranchId) === String(current.branch_id || "") ? current.warehouse_id : "",
        page: 1,
      };
    });
  };

  return (
    <div className="flex min-h-[calc(100vh-170px)] flex-col bg-white">
      <HospitalityReportScopePanel
        filters={filters}
        setFilters={setScopeFilters}
        lookups={{ ...lookups, loadLookups: undefined }}
        onApply={onApply}
        loading={loading}
        businessModeLabel={businessModeLabel}
        supported={{
          branch_id: true,
          date_from: showDateRange,
          date_to: showDateRange,
          till_id: false,
          cashier_id: false,
          shift_session_id: false,
          currency_codes: false,
        }}
      >
      <div className="grid grid-cols-1 gap-px border border-[#D8DCDC] bg-[#D8DCDC] md:grid-cols-2">
        {showAsOf && <FilterField label="As Of"><input type="date" value={filters.as_of} onChange={(event) => update("as_of", event.target.value)} className="report-input" /></FilterField>}
        <FilterField label="Warehouse">
          <select
            value={filters.warehouse_id || ""}
            onChange={(event) => update("warehouse_id", event.target.value)}
            className="report-input"
            disabled={lookups?.loadingLookups}
          >
            <option value="">
              {lookups?.loadingLookups ? "Loading Warehouses..." : "All Warehouses"}
            </option>
            {!lookups?.loadingLookups && visibleWarehouses.length === 0 && (
              <option value="" disabled>No warehouses available for this branch</option>
            )}
            {visibleWarehouses.map((warehouse) => (
              <option key={warehouse.id} value={warehouse.id}>
                {`${warehouse.branch_name || (warehouse.branch_id ? "Branch Warehouse" : "Company-wide")} — ${warehouse.name || warehouse.code || warehouse.id}${warehouse.code && warehouse.name ? ` (${warehouse.code})` : ""}`}
              </option>
            ))}
          </select>
        </FilterField>
        {showMovementType && <FilterField label="Movement"><select value={filters.movement_type} onChange={(event) => update("movement_type", event.target.value)} className="report-input">{MOVEMENT_TYPES.map((type) => <option key={type || "all"} value={type}>{type || "All Movements"}</option>)}</select></FilterField>}
        <FilterField label="Category">
          <select
            value={filters.category_id}
            onChange={(event) => update("category_id", event.target.value)}
            className="report-input"
          >
            <option value="">All Categories</option>
            {lookups?.categories?.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name || category.code || category.id}
              </option>
            ))}
          </select>
        </FilterField>

        <FilterField label="Supplier">
          <select
            value={filters.supplier_id}
            onChange={(event) => update("supplier_id", event.target.value)}
            className="report-input"
          >
            <option value="">All Suppliers</option>
            {lookups?.suppliers?.map((supplier) => (
              <option key={supplier.id} value={supplier.id}>
                {supplier.name || supplier.supplier_name || supplier.code || supplier.id}
              </option>
            ))}
          </select>
        </FilterField>

        <FilterField label="Product">
          <select
            value={filters.product_id}
            onChange={(event) => update("product_id", event.target.value)}
            className="report-input"
          >
            <option value="">All Products</option>
            {lookups?.products?.map((product) => (
              <option key={product.id} value={product.id}>
                {product.name || product.code || product.sku || product.id}
              </option>
            ))}
          </select>
        </FilterField>
        <FilterField label="Search"><input value={filters.q} onChange={(event) => update("q", event.target.value)} placeholder="Product / SKU / code" className="report-input" /></FilterField>
      </div>
      </HospitalityReportScopePanel>
      <style>{`.report-input{height:34px;width:100%;border:1px solid #ACB8B8;background:white;padding:0 8px;font-size:12px;font-weight:400;color:#122424;outline:none}.report-input:focus{border-color:#069495}@media print{button,.no-print{display:none!important}}`}</style>
    </div>
  );
};

const getMaxValue = (rows, key) => {
  const max = Math.max(...(rows || []).map((row) => Number(row?.[key] || 0)));
  return max > 0 ? max : 1;
};

const SimpleBarChart = ({ title, rows, labelKey, valueKey, valueFormatter }) => {
  const max = getMaxValue(rows, valueKey);
  return (
    <ReportCard title={title}>
      {!rows || rows.length === 0 ? <div className="flex min-h-[160px] items-center justify-center text-[12px] text-[#637877]">No chart data available.</div> : (
        <div className="space-y-2 p-3">
          {rows.slice(0, 12).map((row, index) => {
            const value = Number(row?.[valueKey] || 0);
            const percent = Math.max((value / max) * 100, 2);
            const label = row?.[labelKey] || row?.product_name || row?.branch_name || row?.category_name || row?.supplier_name || "-";
            return <div key={`${label}-${index}`} className="grid grid-cols-[180px_1fr_120px] items-center gap-2 text-[12px]"><div className="truncate text-[#324444]">{label}</div><div className="h-5 border border-[#CAD5D5] bg-[#F8FAFC]"><div className="h-full bg-[#069495]" style={{ width: `${percent}%` }} /></div><div className="text-right text-[#003636]">{valueFormatter ? valueFormatter(value, row) : number(value)}</div></div>;
          })}
        </div>
      )}
    </ReportCard>
  );
};

const currencySeriesOf = (rows = []) => {
  const grouped = new Map();

  rows.forEach((row) => {
    const currencyCode = currencyCodeOf(row) || "Currency unavailable";
    grouped.set(currencyCode, [...(grouped.get(currencyCode) || []), row]);
  });

  return Array.from(grouped, ([currencyCode, currencyRows]) => ({
    currencyCode,
    rows: currencyRows,
  }));
};

const StockDashboardReport = ({ lookups }) => {
  const report = useReportLoader(getStockDashboard);
  const payload = report.payload || {};
  const summary = payload.summary || {};
  const stockByBranch = payload.stock_by_branch || [];
  const stockByCategory = payload.stock_by_category || [];
  const lowStock = payload.low_stock || [];
  const negativeStock = payload.negative_stock || [];
  const recentMovements = payload.recent_movements || [];
  const branchCurrencySeries = currencySeriesOf(stockByBranch);
  const categoryCurrencySeries = currencySeriesOf(stockByCategory);
  const stockValueItems = currencyValueItems(
    summary,
    ["stock_value", "cost_value"],
    "Stock Value at Average Cost",
  );

  return (
    <EnterpriseReportPanel title="Stock Dashboard" description="Enterprise summary of current inventory, valuation, alerts and movement activity." reportKey="dashboard" filters={report.filters} setFilters={report.setFilters} onApply={(nextFilters) => report.load(nextFilters)} loading={report.loading} error={report.error} lookups={lookups} showMovementType defaultToLoggedBranch={false}>
      <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Products" value={summary.product_count || 0} />
        <StatCard label="Qty On Hand" value={qty(summary.quantity_on_hand)} positive />
        <StatCard label="Available Qty" value={qty(summary.available_quantity)} positive />
        <StatCard label="Reserved Qty" value={qty(summary.reserved_quantity)} />
        {stockValueItems.map(([label, value]) => (
          <StatCard key={label} label={label} value={value} />
        ))}
        <StatCard label="Movements" value={summary.movement_count || 0} />
        <StatCard label="Received" value={summary.received_count || 0} positive />
        <StatCard label="Adjustments" value={summary.adjustment_count || 0} danger />
      </div>
      <div className="grid gap-2 xl:grid-cols-2">
        {branchCurrencySeries.map(({ currencyCode, rows }) => (
          <SimpleBarChart
            key={`branch-${currencyCode}`}
            title={`Average-Cost Stock Value by Branch — ${currencyCode}`}
            rows={rows}
            labelKey="branch_name"
            valueKey="stock_value"
            valueFormatter={(value) => money(value, currencyCode === "Currency unavailable" ? "" : currencyCode)}
          />
        ))}
        {categoryCurrencySeries.map(({ currencyCode, rows }) => (
          <SimpleBarChart
            key={`category-${currencyCode}`}
            title={`Average-Cost Stock Value by Category — ${currencyCode}`}
            rows={rows}
            labelKey="category_name"
            valueKey="stock_value"
            valueFormatter={(value) => money(value, currencyCode === "Currency unavailable" ? "" : currencyCode)}
          />
        ))}
      </div>
      <ReportCard title="Recent Stock Movements"><DataTable columns={["Date", "Branch", "Warehouse", "Product", "Movement", "In", "Out", "Balance", "Unit Cost", "Total Cost", "Currency", "User"]} rows={recentMovements.map((row) => [formatDateTime(row.movement_date || row.created_at), row.branch_name, row.warehouse_name || "Unassigned", row.product_name, <MovementPill key="movement" value={row.movement_type} />, qty(row.quantity_in), qty(row.quantity_out), row.balance_after !== undefined && row.balance_after !== null ? qty(row.balance_after) : "-", money(row.unit_cost, row.currency_code || row.currency), money(row.total_cost, row.currency_code || row.currency), row.currency_code || row.currency || "-", row.user_name || row.created_by_name || "-"])} /></ReportCard>
      <div className="grid gap-2 xl:grid-cols-2"><ReportCard title="Low Stock Alerts"><DataTable columns={["Branch", "Product", "SKU", "Category", "Qty", "Reorder"]} rows={lowStock.map((row) => [row.branch_name, row.product_name, row.sku || row.code, row.category_name, qty(row.quantity_on_hand), qty(row.reorder_level)])} /></ReportCard><ReportCard title="Negative Stock Alerts"><DataTable columns={["Branch", "Product", "SKU", "Category", "Qty"]} rows={negativeStock.map((row) => [row.branch_name, row.product_name, row.sku || row.code, row.category_name, qty(row.quantity_on_hand)])} /></ReportCard></div>
    </EnterpriseReportPanel>
  );
};

const CompanyBalanceReport = ({ lookups }) => <PaginatedStockTable title="Company Stock Balance" description="Consolidated stock balance valued at each product's average cost." loader={getCompanyStockBalance} lookups={lookups} filename="company-stock-balance.csv" columns={["Product", "SKU", "Code", "Category", "Supplier", "On Hand", "Reserved", "Available", "Average Cost", "Average Cost Value", "Currency"]} rowMapper={(row) => [row.product_name, row.sku, row.code, row.category_name, row.supplier_name, qty(row.quantity_on_hand), qty(row.reserved_quantity), qty(row.available_quantity), money(row.average_cost, row.currency_code || row.currency), money(row.stock_value, row.currency_code || row.currency), row.currency_code || row.currency || "-"]} />;
const BranchBalanceReport = ({ lookups }) => <PaginatedStockTable title="Branch Stock Balance" description="Branch stock valued at each product's average cost." loader={getBranchStockBalance} lookups={lookups} filename="branch-stock-balance.csv" columns={["Branch", "Product", "SKU", "Code", "Category", "On Hand", "Reserved", "Available", "Average Cost", "Average Cost Value", "Currency"]} rowMapper={(row) => [row.branch_name || getBranchName(lookups.branchById?.[row.branch_id]), row.product_name, row.sku, row.code, row.category_name, qty(row.quantity_on_hand), qty(row.reserved_quantity), qty(row.available_quantity), money(row.average_cost, row.currency_code || row.currency), money(row.stock_value, row.currency_code || row.currency), row.currency_code || row.currency || "-"]} />;
const CurrentBalanceReport = ({ lookups }) => <PaginatedStockTable title="Current Stock Balance" description="Current stock snapshot valued at each product's average cost; it is not limited by a movement date range." loader={getStockBalance} initialFilters={currentBalanceFilters()} showDateRange={false} lookups={lookups} filename="current-stock-balance.csv" columns={["Branch", "Product", "SKU", "Code", "Category", "Total In", "Total Out", "On Hand", "Average Cost", "Average Cost Value", "Last Movement", "Currency"]} rowMapper={(row) => [row.branch_name || getBranchName(lookups.branchById?.[row.branch_id]), row.product_name, row.sku, row.code, row.category_name, qty(row.total_in), qty(row.total_out), qty(row.quantity_on_hand), money(row.average_cost, row.currency_code || row.currency), money(row.stock_value, row.currency_code || row.currency), formatDateTime(row.last_movement_at), row.currency_code || row.currency || "-"]} />;
const StockReceivedReport = ({ lookups }) => <PaginatedStockTable title="Stock Received" description="Received quantities and their recorded acquisition cost." loader={getStockReceivedReport} lookups={lookups} filename="stock-received.csv" summaryValueLabel="Recorded Cost Value" columns={["Date", "Branch", "Warehouse", "Product", "SKU", "Supplier", "Movement", "Qty In", "Unit Cost", "Cost Value", "Currency", "Reference"]} rowMapper={(row) => [formatDateTime(row.movement_date || row.received_date), row.branch_name, row.warehouse_name || "Unassigned", row.product_name, row.sku || row.code, row.supplier_name, row.movement_type || "receive", qty(row.quantity_in || row.increased_quantity || row.quantity), money(row.unit_cost, row.currency_code || row.currency), money(row.cost_value ?? row.total_cost ?? row.line_total, row.currency_code || row.currency), row.currency_code || row.currency || "-", row.reference_type || row.receipt_number || "-"]} />;
const StockAdjustmentsReport = ({ lookups }) => {
  const report = useReportLoader(getStockAdjustmentsReport, {
    ...defaultFilters(),
    movement_type: "",
  });

  const columns = [
    "Date",
    "Branch",
    "Warehouse",
    "Product",
    "SKU",
    "Movement",
    "Increased Qty",
    "Decreased Qty",
    "Average Cost",
    "Average Cost Value",
    "Cost Currency",
    "User",
    "Notes",
  ];

  const rowMapper = (row) => {
    const costCurrency = row.cost_currency_code || "";

    return [
      formatDateTime(row.movement_date || row.adjustment_date || row.created_at),
      row.branch_name || getBranchName(lookups.branchById?.[row.branch_id]),
      row.warehouse_name || "Unassigned",
      row.product_name,
      row.sku || row.code,
      row.movement_type || row.adjustment_type || "-",
      qty(row.increased_quantity ?? row.quantity_in),
      qty(row.decreased_quantity ?? row.quantity_out),
      row.average_cost === undefined || row.average_cost === null
        ? "-"
        : money(row.average_cost, costCurrency),
      row.cost_value === undefined || row.cost_value === null
        ? "-"
        : money(row.cost_value, costCurrency),
      costCurrency || "-",
      row.created_by_name || row.adjusted_by_name || row.user_name || "-",
      row.notes || row.reason || "-",
    ];
  };

  const visibleRows = report.paginatedRows.map(rowMapper);

  return (
    <EnterpriseReportPanel
      title="Stock Adjustment Tracking"
      description="Manual adjustments, stock take, wastage, gains and losses."
      filters={report.filters}
      setFilters={report.setFilters}
      onApply={(nextFilters) => report.load(nextFilters)}
      loading={report.loading}
      error={report.error}
      lookups={lookups}
      showMovementType
      reportKey="adjustments"
    >
      <AdjustmentInsightPanel
        rows={report.paginatedRows}
        summary={report.summary}
      />

      <ReportCard title={`Stock Adjustment Tracking (${report.meta.total})`}>
        <DataTable columns={columns} rows={visibleRows} />
        <Pagination
          meta={report.meta}
          onPage={(page) => {
            const next = { ...report.filters, page };
            report.setFilters(next);
            report.load(next);
          }}
        />
      </ReportCard>
    </EnterpriseReportPanel>
  );
};

const StockValuationReport = ({ lookups }) => <PaginatedStockTable title="Stock Valuation" description="Inventory quantity multiplied by each product's average cost." loader={getStockValuationReport} lookups={lookups} filename="stock-valuation.csv" columns={["Branch", "Product", "SKU", "Code", "Category", "On Hand", "Average Cost", "Average Cost Value", "Currency"]} rowMapper={(row) => [row.branch_name || getBranchName(lookups.branchById?.[row.branch_id]), row.product_name, row.sku, row.code, row.category_name, qty(row.quantity_on_hand), money(row.average_cost, row.currency_code || row.currency), money(row.stock_value ?? row.estimated_stock_value, row.currency_code || row.currency), row.currency_code || row.currency || "-"]} />;
const LowStockReport = ({ lookups }) => <PaginatedStockTable title="Low Stock" description="Products below reorder level." loader={getLowStockReport} lookups={lookups} filename="low-stock.csv" columns={["Branch", "Product", "SKU", "Code", "Category", "Supplier", "On Hand", "Reorder Level"]} rowMapper={(row) => [row.branch_name, row.product_name, row.sku, row.code, row.category_name, row.supplier_name, qty(row.quantity_on_hand), qty(row.reorder_level)]} />;
const OutOfStockReport = ({ lookups }) => <PaginatedStockTable title="Out Of Stock" description="Products with zero or below stock." loader={getOutOfStockReport} lookups={lookups} filename="out-of-stock.csv" columns={["Branch", "Product", "SKU", "Code", "Category", "Supplier", "On Hand"]} rowMapper={(row) => [row.branch_name, row.product_name, row.sku, row.code, row.category_name, row.supplier_name, qty(row.quantity_on_hand)]} />;
const NegativeStockReport = ({ lookups }) => <PaginatedStockTable title="Negative Stock" description="Products whose inventory balance is below zero." loader={getNegativeStockReport} lookups={lookups} filename="negative-stock.csv" columns={["Branch", "Product", "SKU", "Code", "Category", "Supplier", "On Hand"]} rowMapper={(row) => [row.branch_name, row.product_name, row.sku, row.code, row.category_name, row.supplier_name, qty(row.quantity_on_hand)]} />;
const CategorySummaryReport = ({ lookups }) => <PaginatedStockTable title="Category Stock Summary" description="Inventory grouped by category and valued at average cost." loader={getStockCategorySummary} lookups={lookups} filename="stock-category-summary.csv" columns={["Category", "Products", "On Hand", "Available", "Average Cost Value", "Currency"]} rowMapper={(row) => [row.category_name, row.product_count, qty(row.quantity_on_hand), qty(row.available_quantity), money(row.stock_value, row.currency_code || row.currency), row.currency_code || row.currency || "-"]} />;
const SupplierSummaryReport = ({ lookups }) => <PaginatedStockTable title="Supplier Stock Summary" description="Inventory grouped by supplier and valued at average cost." loader={getStockSupplierSummary} lookups={lookups} filename="stock-supplier-summary.csv" columns={["Supplier", "Products", "On Hand", "Available", "Average Cost Value", "Currency"]} rowMapper={(row) => [row.supplier_name, row.product_count, qty(row.quantity_on_hand), qty(row.available_quantity), money(row.stock_value, row.currency_code || row.currency), row.currency_code || row.currency || "-"]} />;

const AsOfBalanceReport = ({ lookups }) => {
  const report = useReportLoader(getStockAsOfBalance, { ...defaultFilters(), as_of: today() });
  const load = (nextFilters = report.filters) => report.load({ ...nextFilters, date_to: nextFilters.as_of });
  return <PaginatedStockTable title="Stock Balance As-Of" description="Historical quantity valued using each product's average cost." externalReport={{ ...report, load }} lookups={lookups} filename="stock-balance-as-of.csv" showAsOf columns={["As Of", "Branch", "Product", "SKU", "Code", "Category", "Quantity As Of", "Average Cost", "Average Cost Value", "Last Movement", "Currency"]} rowMapper={(row) => [row.as_of_date || report.filters.as_of, row.branch_name || getBranchName(lookups.branchById?.[row.branch_id]), row.product_name, row.sku, row.code, row.category_name, qty(row.quantity_as_of), money(row.average_cost, row.currency_code || row.currency), money(row.stock_value, row.currency_code || row.currency), formatDateTime(row.last_movement_at), row.currency_code || row.currency || "-"]} />;
};

const StockMovementsReport = ({ lookups }) => {
  const report = useReportLoader(getStockMovements, { ...defaultFilters(), movement_type: "" });
  const [ledgerProduct, setLedgerProduct] = useState(null);
  const [ledgerRows, setLedgerRows] = useState([]);
  const [loadingLedger, setLoadingLedger] = useState(false);
  const openLedger = async (row) => {
    try {
      setLoadingLedger(true);
      setLedgerProduct(row);
      const res = await getProductStockLedger(row.product_id, report.filters);
      setLedgerRows(getPaginatedRows(res));
    } catch {
      setLedgerRows([]);
    } finally {
      setLoadingLedger(false);
    }
  };
  return (
    <EnterpriseReportPanel title="Stock Movements" description="Full inventory movement ledger." reportKey="movements" filters={report.filters} setFilters={report.setFilters} onApply={(nextFilters) => report.load(nextFilters)} loading={report.loading} error={report.error} lookups={lookups} showMovementType defaultToLoggedBranch={false}>
      <ReportCard title={`Stock Movements (${report.meta.total})`}><DataTable columns={["Date", "Branch", "Warehouse", "Product", "SKU", "Movement", "In", "Out", "Balance", "Unit Cost", "Total Cost", "Currency", "Reference", "User"]} rows={report.paginatedRows.map((row) => [formatDateTime(row.movement_date), row.branch_name, row.warehouse_name || "Unassigned", <button key="ledger" type="button" onClick={() => openLedger(row)} className="font-semibold text-[#008585] underline-offset-2 hover:underline">{row.product_name}</button>, row.sku || row.code, <MovementPill key="movement" value={row.movement_type} />, qty(row.quantity_in), qty(row.quantity_out), row.balance_after !== undefined && row.balance_after !== null ? qty(row.balance_after) : "-", money(row.unit_cost, row.currency_code || row.currency), money(row.total_cost, row.currency_code || row.currency), row.currency_code || row.currency || "-", row.reference_type || "-", row.created_by_name || row.user_name || "-"])} /><Pagination meta={report.meta} onPage={(page) => { const next = { ...report.filters, page }; report.setFilters(next); report.load(next); }} /></ReportCard>
      {ledgerProduct && <DrilldownPanel title={`Product Ledger - ${ledgerProduct.product_name}`} onClose={() => setLedgerProduct(null)} loading={loadingLedger}><DataTable columns={["Date", "Branch", "Warehouse", "Movement", "In", "Out", "Balance", "Unit Cost", "Total Cost", "Currency", "Reference", "Notes"]} rows={ledgerRows.map((row) => [formatDateTime(row.movement_date), row.branch_name, row.warehouse_name || "Unassigned", <MovementPill key="movement" value={row.movement_type} />, qty(row.quantity_in), qty(row.quantity_out), row.balance_after !== undefined && row.balance_after !== null ? qty(row.balance_after) : "-", money(row.unit_cost, row.currency_code || row.currency), money(row.total_cost, row.currency_code || row.currency), row.currency_code || row.currency || "-", row.reference_type || "-", row.notes || "-"])} /></DrilldownPanel>}
    </EnterpriseReportPanel>
  );
};


const SummaryStrip = ({ summary, monetaryLabel = "Average Cost Value" }) => {
  if (!summary) return null;

  const items = [
    ["Products", summary.product_count],
    ["On Hand", summary.quantity_on_hand !== undefined ? qty(summary.quantity_on_hand) : null],
    ["Available", summary.available_quantity !== undefined ? qty(summary.available_quantity) : null],
    ["Adjustments", summary.adjustment_count],
    ["Increased Qty", summary.increased_quantity !== undefined ? qty(summary.increased_quantity) : null],
    ["Decreased Qty", summary.decreased_quantity !== undefined ? qty(summary.decreased_quantity) : null],
    ...currencyValueItems(summary, ["stock_value", "cost_value"], monetaryLabel),
  ].filter(([, value]) => value !== null && value !== undefined && value !== "");

  if (items.length === 0) return null;

  return (
    <div className="grid gap-2 md:grid-cols-3 xl:grid-cols-6">
      {items.map(([label, value]) => (
        <StatCard key={label} label={label} value={value} />
      ))}
    </div>
  );
};

const AdjustmentInsightPanel = ({ rows = [], summary = null }) => {
  const insight = computeAdjustmentInsight(rows, summary);

  const detailedSummary = [
    ["Adjustment Count", insight.adjustment_count, "Total adjustment transactions in this report."],
    ["Increased Quantity", qty(insight.increased_quantity), "Total quantity added back into stock."],
    ["Decreased Quantity", qty(insight.decreased_quantity), "Total quantity removed from stock."],
    ...insight.currency_totals.flatMap((bucket) => {
      const currencyCode = bucket.cost_currency_code;
      return [
        [`Increased Average-Cost Value (${currencyCode})`, money(bucket.increased_cost_value, currencyCode), "Average-cost value of positive stock adjustments."],
        [`Decreased Average-Cost Value (${currencyCode})`, money(bucket.decreased_cost_value, currencyCode), "Average-cost value removed through stock reductions."],
        [`Net Average-Cost Impact (${currencyCode})`, money(bucket.net_cost_impact, currencyCode), "Increased average-cost value minus decreased average-cost value."],
        [`Total Adjustment Cost (${currencyCode})`, money(bucket.overall_cost_value, currencyCode), "Total average-cost value represented by these adjustment records."],
      ];
    }),
  ];

  return (
    <div className="border border-[#ACB8B8] bg-white">
      <div className="border-b border-[#ACB8B8] bg-[#F6F8FA] px-3 py-2">
        <div className="text-[13px] font-semibold uppercase tracking-wide text-[#003636]">
          Summary
        </div>
        <div className="mt-0.5 text-[11px] text-[#637877]">
          Accounting view of stock increases and decreases using product average cost only.
        </div>
      </div>

      <div className="grid gap-2 p-3 md:grid-cols-2 xl:grid-cols-4">
        {detailedSummary.map(([label, value, note]) => {
          const rawValue = String(value || "");
          const isLoss =
            label.includes("Decreased") ||
            (label.includes("Net") && rawValue.includes("-"));

          const isPositive =
            label.includes("Increased") ||
            (label.includes("Net") && !rawValue.includes("-"));

          return (
            <div key={label} className="border border-[#D7DFDF] bg-white p-3">
              <div className="text-[11px] font-medium text-[#637877]">
                {label}
              </div>
              <div
                className={`mt-1 truncate text-[19px] font-semibold ${
                  isLoss
                    ? "text-[#D92D20]"
                    : isPositive
                    ? "text-[#057B7C]"
                    : "text-[#003636]"
                }`}
              >
                {value}
              </div>
              <div className="mt-1 min-h-[28px] text-[11px] leading-4 text-[#637877]">
                {note}
              </div>
            </div>
          );
        })}
      </div>

      <div className="border-t border-[#D7DFDF] bg-[#FFFCF5] px-3 py-3">
        <div className="text-[12px] font-semibold text-[#003636]">
          AI Comment
        </div>
        <div className="mt-1 text-[12px] leading-5 text-[#324443]">
          {adjustmentAiComment(insight)}
        </div>
      </div>
    </div>
  );
};

const PaginatedStockTable = ({ title, description, reportKey, loader, externalReport, lookups, columns, rowMapper, showAsOf = false, showMovementType = false, showDateRange = true, initialFilters, summaryValueLabel = "Average Cost Value" }) => {
  const internalReport = useReportLoader(
    loader || (() => Promise.resolve({ data: [] })),
    initialFilters || defaultFilters(),
  );
  const report = externalReport || internalReport;
  const visibleRows = report.paginatedRows.map(rowMapper);

  return (
    <EnterpriseReportPanel
      title={title}
      description={description}
      reportKey={reportKey || PAGINATED_REPORT_KEYS[title]}
      filters={report.filters}
      setFilters={report.setFilters}
      onApply={(nextFilters) => report.load(nextFilters)}
      loading={report.loading}
      error={report.error}
      lookups={lookups}
      showAsOf={showAsOf}
      showMovementType={showMovementType}
      showDateRange={showDateRange}
    >
      <SummaryStrip summary={report.summary} monetaryLabel={summaryValueLabel} />

      <ReportCard title={`${title} (${report.meta.total})`}>
        <DataTable columns={columns} rows={visibleRows} />
        <Pagination
          meta={report.meta}
          onPage={(page) => {
            const next = { ...report.filters, page };
            report.setFilters(next);
            report.load(next);
          }}
        />
      </ReportCard>
    </EnterpriseReportPanel>
  );
};

const FilterField = ({ label, children }) => <label className="block min-h-[98px] bg-white p-4"><div className="mb-2 text-[13px] font-medium text-[#323B3A]">{label}</div>{children}</label>;
const ReportStatus = ({ loading, error }) => loading ? <div className="mb-2 flex h-12 items-center justify-center border border-[#ACB8B8] bg-white text-[12px] font-medium text-[#069495]"><RefreshCw className="mr-2 h-3.5 w-3.5 animate-spin" />Loading report...</div> : error ? <div className="mb-2 whitespace-pre-line border border-[#D92D20] bg-[#FFF4F3] p-3 text-[12px] font-medium text-[#B42318]">{error}</div> : null;
const StatCard = ({ label, value, subValue, positive = false, danger = false }) => <div className="border border-[#ACB8B8] bg-white p-3"><div className="text-[11px] font-medium text-[#637877]">{label}</div><div className={`mt-1 truncate text-[20px] font-medium ${danger ? "text-[#D92D20]" : positive ? "text-[#057B7C]" : "text-[#003636]"}`}>{value}</div>{subValue && <div className="mt-1 text-[11px] text-[#637877]">{subValue}</div>}</div>;
const ReportCard = ({ title, children }) => <div className="overflow-hidden border border-[#ACB8B8] bg-white"><div className="flex min-h-9 items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2"><div className="text-[13px] font-medium text-[#003636]">{title}</div></div><div className="overflow-x-auto">{children}</div></div>;
const DataTable = ({ columns, rows }) => !rows || rows.length === 0 ? <div className="flex min-h-[120px] items-center justify-center p-4 text-[12px] text-[#637877]"><Search className="mr-2 h-3.5 w-3.5" />No records found.</div> : <table className="w-full min-w-[1180px] border-collapse text-[12px]"><thead><tr className="border-b border-[#ACB8B8] bg-[#F3F6F8]">{columns.map((column) => <th key={column} className="whitespace-nowrap px-3 py-2 text-left text-[11px] font-medium text-[#324444]">{column}</th>)}</tr></thead><tbody>{rows.map((row, rowIndex) => <tr key={rowIndex} className="border-b border-[#E3E9E9] hover:bg-[#F6F8FA]">{row.map((cell, cellIndex) => <td key={cellIndex} className="whitespace-nowrap px-3 py-2 text-[12px] text-[#324443]">{cell ?? "-"}</td>)}</tr>)}</tbody></table>;
const Pagination = ({ meta, onPage }) => {
  const safeMeta = meta || {
    current_page: 1,
    last_page: 1,
    total: 0,
  };

  return (
    <div className="flex items-center justify-between border-t border-[#ACB8B8] bg-[#F6F8FA] px-3 py-2">
      <div className="text-[11px] text-[#637877]">
        Page {safeMeta.current_page} of {safeMeta.last_page} · {safeMeta.total} records
      </div>

      <div className="flex gap-1">
        <button
          type="button"
          disabled={safeMeta.current_page <= 1}
          onClick={() => onPage(safeMeta.current_page - 1)}
          className="border border-[#ACB8B8] bg-white px-3 py-1 text-[11px] font-medium text-[#003636] disabled:opacity-50"
        >
          Previous
        </button>

        <button
          type="button"
          disabled={safeMeta.current_page >= safeMeta.last_page}
          onClick={() => onPage(safeMeta.current_page + 1)}
          className="border border-[#ACB8B8] bg-white px-3 py-1 text-[11px] font-medium text-[#003636] disabled:opacity-50"
        >
          Next
        </button>
      </div>
    </div>
  );
};
const MovementPill = ({ value }) => { const type = String(value || "").toLowerCase(); const className = type.includes("receive") || type.includes("_in") || type.includes("opening") ? "bg-[#E8F7F5] text-[#057B7C] border-[#C3DADA]" : type.includes("sale") || type.includes("_out") || type.includes("wastage") ? "bg-[#FFF4F3] text-[#B42318] border-[#FDA29B]" : "bg-[#F6F8FA] text-[#637877] border-[#ACB8B8]"; return <span className={`inline-flex border px-2 py-0.5 text-[11px] font-medium ${className}`}>{value || "-"}</span>; };
const DrilldownPanel = ({ title, onClose, loading, children }) => <div className="fixed inset-0 z-[90] bg-black/45 p-3"><div className="mx-auto flex h-full max-w-[1500px] flex-col overflow-hidden border border-[#ACB8B8] bg-white shadow-2xl"><div className="flex items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-4 py-3"><div><div className="text-[15px] font-medium text-[#003636]">{title}</div><div className="text-[11px] text-[#637877]">Detailed stock ledger</div></div><button type="button" onClick={onClose} className="border border-[#ACB8B8] bg-white p-1.5 text-[#637877] hover:bg-[#F6F8FA]"><X className="h-4 w-4" /></button></div><div className="min-h-0 flex-1 overflow-y-auto bg-[#DFE5E5] p-2">{loading ? <div className="flex h-32 items-center justify-center text-[12px] text-[#637877]"><RefreshCw className="mr-2 h-3.5 w-3.5 animate-spin" />Loading details...</div> : children}</div></div></div>;

export default StockReportsModal;
