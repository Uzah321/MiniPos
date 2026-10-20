import React, { useEffect, useMemo, useState } from "react";
import { AlertTriangle, CheckCircle2, RefreshCw, Save, Search, X } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import {
  getFrontStockClosingSnapshot,
  saveFrontStockDailyClosing,
} from "../../../services/FrontStockDailyClosingService";
import {
  getFoodIndustrySalesBusinessDates,
} from "../../../services/FoodIndustrySalesReportService";

const today = () => new Date().toISOString().slice(0, 10);

const numberValue = (value) => {
  const numeric = Number(value || 0);
  return Number.isNaN(numeric) ? 0 : numeric;
};

const qty = (value) => Number(value || 0).toFixed(4);
const money = (value, currency = "USD") => `${currency} ${Number(value || 0).toFixed(2)}`;

const rowsOf = (value) => {
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  if (Array.isArray(value?.data?.data)) return value.data.data;
  return [];
};

const businessDateOf = (row) =>
  row?.business_date ||
  row?.trading_date ||
  row?.date ||
  "";

const formatBusinessDate = (value) => {
  if (!value) return "";
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
};

const getProductName = (line) => line?.product_name || line?.name || "Product";
const getProductCode = (line) => line?.product_sku || line?.product_code || line?.barcode || "-";
const getLineCurrency = (line, fallback = "USD") => line?.currency_code || line?.currency || fallback || "USD";

const ALLOWED_FRONT_STOCK_PRODUCT_TYPES = new Set([
  "raw_material",
  "raw material",
  "semi_finished_good",
  "semi finished good",
  "semi-finished good",
  "consumable",
  "consumables",
  "packaging",
]);

const normalizeProductType = (value) =>
  String(value || "")
    .trim()
    .toLowerCase();

const isAllowedFrontStockProductType = (line) =>
  ALLOWED_FRONT_STOCK_PRODUCT_TYPES.has(normalizeProductType(line?.product_type || line?.productType));

const formatProductType = (value) =>
  String(value || "stock item")
    .replace(/[_-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();


const calculateLine = (line) => {
  const expected = numberValue(line.expected_quantity);
  const counted = line.counted_quantity === "" || line.counted_quantity === null
    ? 0
    : numberValue(line.counted_quantity);
  const variance = counted - expected;
  const unitCost = numberValue(line.unit_cost);

  return {
    ...line,
    expected_quantity: expected,
    counted_quantity: line.counted_quantity,
    variance_quantity: variance,
    variance_value: variance * unitCost,
    unit_cost: unitCost,
  };
};

const DailyFrontStockClosingModal = ({
  open,
  onClose,
  defaultCurrency = "USD",
  currentUser = null,
  onOpenIssueStock,
}) => {
  const [closingDate, setClosingDate] = useState(today());
  const [businessDates, setBusinessDates] = useState([]);
  const [stockArea] = useState("front");
  const [search, setSearch] = useState("");
  const [lines, setLines] = useState([]);
  const [existingClosing, setExistingClosing] = useState(null);
  const [notes, setNotes] = useState("");
  const [replaceExisting, setReplaceExisting] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");
  const [blockingFrontStockIssues, setBlockingFrontStockIssues] = useState([]);

  const allowedLines = useMemo(() => lines.filter(isAllowedFrontStockProductType), [lines]);

  const calculatedLines = useMemo(() => allowedLines.map(calculateLine), [allowedLines]);

  const filteredLines = useMemo(() => {
    const q = String(search || "").trim().toLowerCase();
    if (!q) return calculatedLines;

    return calculatedLines.filter((line) => {
      const haystack = [
        line.product_name,
        line.product_code,
        line.product_sku,
        line.product_type,
        line.uom_code,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();

      return haystack.includes(q);
    });
  }, [calculatedLines, search]);

  const totals = useMemo(() => {
    return calculatedLines.reduce(
      (acc, line) => {
        acc.expected += numberValue(line.expected_quantity);
        acc.counted += line.counted_quantity === "" ? 0 : numberValue(line.counted_quantity);
        acc.variance += numberValue(line.variance_quantity);
        acc.varianceValue += numberValue(line.variance_value);
        if (Math.abs(numberValue(line.variance_quantity)) > 0.000001) acc.varianceLines += 1;
        return acc;
      },
      {
        expected: 0,
        counted: 0,
        variance: 0,
        varianceValue: 0,
        varianceLines: 0,
      }
    );
  }, [calculatedLines]);

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 3200);
  };

  const loadBusinessDates = async () => {
    try {
      const data = await getFoodIndustrySalesBusinessDates({
        branch_id: currentUser?.branch_id || currentUser?.branch?.id || "",
      });

      const rows = rowsOf(data?.business_dates || data?.dates || data);
      const selected =
        data?.current_business_date ||
        data?.current_open_business_date ||
        businessDateOf(rows[0]);

      setBusinessDates(rows);

      if (selected) {
        setClosingDate(selected);
        return selected;
      }
    } catch {
      setBusinessDates([]);
    }

    return closingDate;
  };

  const loadSnapshot = async (businessDate = closingDate) => {
    if (!open) return;

    try {
      setLoading(true);
      const data = await getFrontStockClosingSnapshot({
        closing_date: businessDate,
        stock_area: stockArea,
      });

      setExistingClosing(data?.existing_closing || null);
      setBlockingFrontStockIssues(
        Array.isArray(data?.blocking_front_stock_issues)
          ? data.blocking_front_stock_issues
          : [],
      );
      setReplaceExisting(false);
      setLines(
        (data?.lines || []).map((line) => ({
          ...line,
          counted_quantity:
            line.counted_quantity === undefined || line.counted_quantity === null
              ? ""
              : String(line.counted_quantity),
          notes: line.notes || "",
        }))
      );
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Failed to load front stock snapshot.");
      setLines([]);
      setBlockingFrontStockIssues([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;

    (async () => {
      const businessDate = await loadBusinessDates();
      await loadSnapshot(businessDate);
    })();

    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const updateLine = (productId, updates) => {
    setLines((prev) =>
      prev.map((line) =>
        String(line.product_id) === String(productId)
          ? {
              ...line,
              ...updates,
            }
          : line
      )
    );
  };

  const fillExpectedAsCounted = () => {
    setLines((prev) =>
      prev.map((line) => ({
        ...line,
        counted_quantity: String(Number(line.expected_quantity || 0).toFixed(4)),
      }))
    );
  };

  const clearCounts = () => {
    setLines((prev) =>
      prev.map((line) => ({
        ...line,
        counted_quantity: "",
      }))
    );
  };

  const saveClosing = async () => {
    if (saving) return;
    if (blockingFrontStockIssues.length > 0) {
      showNotice(
        "Issue the listed negative recipe ingredients from BOH to FRONT before saving this reconciliation.",
      );
      return;
    }
    if (allowedLines.length === 0) {
      showNotice("No allowed front stock lines found to close. Only Raw Material, Semi Finished Good, Consumables, and Packaging are allowed.");
      return;
    }

    const uncounted = allowedLines.filter(
      (line) => line.counted_quantity === "" || line.counted_quantity === null || line.counted_quantity === undefined
    );

    if (uncounted.length > 0) {
      showNotice(`Please count all lines first. Uncounted lines: ${uncounted.length}`);
      return;
    }

    if (existingClosing && !replaceExisting) {
      showNotice("This day is already closed. Tick replace existing closing if you want to save corrections.");
      return;
    }

    try {
      setSaving(true);

      const payload = {
        closing_date: closingDate,
        stock_area: stockArea,
        replace_existing: replaceExisting,
        notes: notes || null,
        lines: allowedLines.map((line) => ({
          product_id: line.product_id,
          counted_quantity: numberValue(line.counted_quantity),
          notes: line.notes || null,
        })),
      };

      const response = await saveFrontStockDailyClosing(payload);
      showNotice(response?.message || "Daily front stock closing saved.");
      setExistingClosing(response?.data || null);
      setReplaceExisting(false);
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.response?.data?.error || error?.message || "Failed to save daily stock closing.");
    } finally {
      setSaving(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="FRONT STOCK DAILY COUNT & RECONCILIATION"
      subtitle="Daily Front-of-House physical count and variance adjustment • Back Stock uses the formal Month-End Stock Take"
    >
      <div className="relative flex h-[calc(100vh-86px)] flex-col overflow-hidden bg-[#F4F7F7] text-[#003636]">
        {notice && (
          <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 whitespace-pre-line border border-[#0B4444] bg-[#004444] px-4 py-2 text-[10.5px] font-semibold text-white shadow-xl">
            {notice}
          </div>
        )}

        <div className="shrink-0 border-b border-[#ACB8B8] bg-white p-2">
          <div className="grid gap-2 xl:grid-cols-[190px_190px_1fr_160px_160px]">
            <label>
              <div className="mb-1 text-[9px] font-semibold uppercase tracking-[0.04em] text-[#627474]">Business Trading Date</div>
              <select
                value={closingDate}
                onChange={(event) => {
                  const businessDate = event.target.value;
                  setClosingDate(businessDate);
                  setSearch("");
                  loadSnapshot(businessDate);
                }}
                className="h-9 w-full border border-[#ACB8B8] bg-white px-2 text-[10.5px] font-semibold outline-none focus:border-[#069495]"
              >
                {businessDates.length === 0 && (
                  <option value={closingDate}>
                    {formatBusinessDate(closingDate) || closingDate}
                  </option>
                )}

                {businessDates.map((row) => {
                  const value = businessDateOf(row);
                  if (!value) return null;

                  const label = `${formatBusinessDate(value)}${
                    row.status ? ` · ${String(row.status).toUpperCase()}` : ""
                  }${
                    row.receipt_count ? ` · ${row.receipt_count} receipts` : ""
                  }`;

                  return (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  );
                })}
              </select>
            </label>

            <label>
              <div className="mb-1 text-[9px] font-semibold uppercase tracking-[0.04em] text-[#627474]">Stock Area</div>
              <input
                type="text"
                value="FRONT"
                disabled
                className="h-9 w-full border border-[#ACB8B8] bg-[#E4E8E8] px-2 text-[10.5px] font-semibold uppercase text-[#324443]"
              />
            </label>

            <label>
              <div className="mb-1 text-[9px] font-semibold uppercase tracking-[0.04em] text-[#627474]">Search Product</div>
              <div className="flex h-9 border border-[#ACB8B8] bg-white focus-within:border-[#069495]">
                <div className="flex w-9 items-center justify-center border-r border-[#CFD9D8] text-[#627474]">
                  <Search className="h-4 w-4" />
                </div>
                <input
                  type="text"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Name, SKU, code, allowed type, UOM"
                  className="min-w-0 flex-1 px-2 text-[10.5px] font-semibold outline-none"
                />
              </div>
            </label>

            <button
              type="button"
              onClick={() => loadSnapshot(closingDate)}
              disabled={loading || saving}
              className="mt-[16px] flex h-9 items-center justify-center gap-2 border border-[#0B4444] bg-[#004444] text-[10.5px] font-semibold uppercase tracking-[0.04em] text-white disabled:opacity-50"
            >
              <RefreshCw className={`h-4 w-4 ${loading ? "animate-spin" : ""}`} />
              Refresh
            </button>

            <button
              type="button"
              onClick={saveClosing}
              disabled={
                loading ||
                saving ||
                allowedLines.length === 0 ||
                blockingFrontStockIssues.length > 0
              }
              className="mt-[16px] flex h-9 items-center justify-center gap-2 border border-[#00883F] bg-[#00A651] text-[10.5px] font-semibold uppercase tracking-[0.04em] text-white disabled:opacity-50"
            >
              <Save className="h-4 w-4" />
              {saving ? "Saving" : "Save Front Stock Closing"}
            </button>
          </div>

          <div className="mt-2 border border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1 text-[9.5px] font-semibold uppercase text-[#057B7B]">
            Front Stock only: closing stock is loaded and saved against the selected business trading date. Back Stock is counted separately through the formal Month-End Stock Take.
          </div>

          {blockingFrontStockIssues.length > 0 && (
            <div className="mt-2 border border-[#F04438] bg-[#FEF3F2] p-2 text-[#B42318]">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <div className="text-[10.5px] font-semibold uppercase">
                    Issue Stock to Front First
                  </div>
                  <div className="mt-1 text-[9.5px] font-medium">
                    {blockingFrontStockIssues.length} menu-item ingredient issue(s) are negative. Reconciliation is locked until BOH stock is issued to FRONT.
                  </div>
                </div>
                <button
                  type="button"
                  onClick={onOpenIssueStock}
                  disabled={!onOpenIssueStock || saving}
                  className="h-8 border border-[#B42318] bg-[#D92D20] px-3 text-[9.5px] font-semibold uppercase text-white disabled:opacity-50"
                >
                  Open Issue Stock
                </button>
              </div>
              <div className="mt-2 grid max-h-[120px] gap-1 overflow-auto md:grid-cols-2">
                {blockingFrontStockIssues.map((row, index) => (
                  <div
                    key={`${row.menu_product_id || "unmapped"}-${row.ingredient_product_id}-${index}`}
                    className="border border-[#FDA29B] bg-white px-2 py-1 text-[9.5px] text-[#324443]"
                  >
                    <strong>{row.menu_product_name || "Unmapped menu item"}</strong>
                    {" · "}{row.ingredient_name || "Ingredient"}
                    {" · Issue "}{qty(row.issue_quantity_required)}
                  </div>
                ))}
              </div>
            </div>
          )}

          {existingClosing && (
            <div className="mt-2 flex flex-wrap items-center justify-between gap-2 border border-[#FEDF89] bg-[#FFFAEB] px-2 py-1 text-[9.5px] font-semibold uppercase text-[#B54708]">
              <div className="flex items-center gap-2">
                <AlertTriangle className="h-4 w-4" />
                This date already has a saved daily front stock closing.
              </div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={replaceExisting}
                  onChange={(event) => setReplaceExisting(event.target.checked)}
                />
                Replace existing closing with this count
              </label>
            </div>
          )}
        </div>

        <div className="shrink-0 border-b border-[#ACB8B8] bg-[#F8FAFC] p-2">
          <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
            <SummaryCard label="Items" value={allowedLines.length} />
            <SummaryCard label="Expected Qty" value={qty(totals.expected)} />
            <SummaryCard label="Counted Qty" value={qty(totals.counted)} />
            <SummaryCard
              label="Variance Qty"
              value={qty(totals.variance)}
              danger={Math.abs(totals.variance) > 0.000001}
              good={Math.abs(totals.variance) <= 0.000001}
            />
            <SummaryCard
              label="Variance Value"
              value={money(totals.varianceValue, defaultCurrency)}
              danger={Math.abs(totals.varianceValue) > 0.000001}
              good={Math.abs(totals.varianceValue) <= 0.000001}
            />
          </div>

          <div className="mt-2 flex flex-wrap gap-2">
            <button
              type="button"
              onClick={fillExpectedAsCounted}
              disabled={loading || saving}
              className="h-8 border border-[#069495] bg-white px-3 text-[9.5px] font-semibold uppercase text-[#005A5A] disabled:opacity-50"
            >
              Fill Expected
            </button>
            <button
              type="button"
              onClick={clearCounts}
              disabled={loading || saving}
              className="h-8 border border-[#B42318] bg-white px-3 text-[9.5px] font-semibold uppercase text-[#B42318] disabled:opacity-50"
            >
              Clear
            </button>
            <div className="flex items-center gap-2 text-[9.5px] font-semibold uppercase tracking-[0.04em] text-[#627474]">
              <CheckCircle2 className="h-4 w-4 text-[#00A651]" />
              Allowed types only. Saving updates FRONT stock and feeds reconciliation.
            </div>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-auto p-2">
          <div className="min-h-full border border-[#ACB8B8] bg-white">
            <table className="sx-enterprise-table min-w-[1280px]">
              <thead>
                <tr>
                  <th className="w-12 text-center">#</th>
                  <th>Product</th>
                  <th>Code</th>
                  <th>Type</th>
                  <th>UOM</th>
                  <th className="text-right">Expected</th>
                  <th className="text-right">Counted</th>
                  <th className="text-right">Variance</th>
                  <th className="text-right">Variance Value</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan="10" className="py-10 text-center text-[#627474]">
                      <span className="inline-flex items-center gap-2 text-[10.5px] font-semibold uppercase"><RefreshCw className="h-4 w-4 animate-spin" />Loading front stock...</span>
                    </td>
                  </tr>
                ) : filteredLines.length === 0 ? (
                  <tr>
                    <td colSpan="10" className="py-10 text-center text-[#627474]">
                      <span className="inline-flex items-center gap-2 text-[10.5px] font-semibold uppercase"><X className="h-4 w-4" />No front stock items found.</span>
                    </td>
                  </tr>
                ) : filteredLines.map((line, index) => {
                  const variance = numberValue(line.variance_quantity);
                  const varianceValue = numberValue(line.variance_value);
                  const hasVariance = Math.abs(variance) > 0.000001;

                  return (
                    <tr key={line.product_id} className={hasVariance ? "bg-[#FFFAEB]" : ""}>
                      <td className="text-center text-[#627474]">{index + 1}</td>
                      <td><strong>{getProductName(line)}</strong></td>
                      <td>{getProductCode(line)}</td>
                      <td>{formatProductType(line.product_type)}</td>
                      <td>{line.uom_code || "EACH"}</td>
                      <td className="text-right tabular-nums">{qty(line.expected_quantity)}</td>
                      <td className="min-w-[130px]">
                        <input
                          type="number"
                          min="0"
                          step="0.0001"
                          value={line.counted_quantity ?? ""}
                          onChange={(event) => updateLine(line.product_id, { counted_quantity: event.target.value })}
                          className="h-8 w-full border border-[#ACB8B8] bg-white px-2 text-right text-[10.5px] font-semibold tabular-nums text-[#004141] outline-none focus:border-[#069495]"
                          placeholder="0.0000"
                        />
                      </td>
                      <td className={`text-right font-semibold tabular-nums ${variance < 0 ? "text-[#B42318]" : variance > 0 ? "text-[#007A3D]" : "text-[#324443]"}`}>{qty(variance)}</td>
                      <td className={`text-right font-semibold tabular-nums ${varianceValue < 0 ? "text-[#B42318]" : varianceValue > 0 ? "text-[#007A3D]" : "text-[#324443]"}`}>{money(varianceValue, getLineCurrency(line, defaultCurrency))}</td>
                      <td className="min-w-[220px]">
                        <input
                          type="text"
                          value={line.notes || ""}
                          onChange={(event) => updateLine(line.product_id, { notes: event.target.value })}
                          className="h-8 w-full border border-[#CFD9D8] px-2 text-[10.5px] outline-none focus:border-[#069495]"
                          placeholder="Optional"
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>

        <div className="shrink-0 border-t border-[#ACB8B8] bg-white p-2">
          <div className="grid gap-2 lg:grid-cols-[1fr_180px]">
            <input
              type="text"
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
              placeholder="Closing notes, variance reasons, supervisor comments..."
              className="h-9 border border-[#ACB8B8] px-3 text-[10.5px] font-semibold outline-none focus:border-[#069495]"
            />
            <button
              type="button"
              onClick={saveClosing}
              disabled={loading || saving || allowedLines.length === 0}
              className="h-9 border border-[#00883F] bg-[#00A651] text-[10.5px] font-semibold uppercase tracking-[0.04em] text-white disabled:opacity-50"
            >
              {saving ? "Saving..." : "Save Front Stock Closing"}
            </button>
          </div>
        </div>
      </div>
    </FullScreenModal>
  );
};

const SummaryCard = ({ label, value, danger = false, good = false }) => (
  <div className="min-w-0 border border-[#ACB8B8] bg-white p-2">
    <div className="truncate text-[9px] font-semibold uppercase tracking-[0.04em] text-[#627474]">{label}</div>
    <div
      className={`mt-1 truncate text-[14px] font-semibold tabular-nums ${
        danger ? "text-[#B42318]" : good ? "text-[#007A3D]" : "text-[#003636]"
      }`}
      title={String(value)}
    >
      {value}
    </div>
  </div>
);

export default DailyFrontStockClosingModal;
