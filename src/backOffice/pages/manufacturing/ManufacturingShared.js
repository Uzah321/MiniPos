// src/backOffice/pages/manufacturing/ManufacturingShared.js

import React, { useState } from "react";

/*
|--------------------------------------------------------------------------
| Product Type Constants
|--------------------------------------------------------------------------
*/

export const MANUFACTURING_MATERIAL_TYPES = [
  "raw_material",
  "semi_finished",
  "packaging",
  "consumable",
];

export const FINISHED_PRODUCT_TYPES = ["finished_good", "semi_finished"];

/*
|--------------------------------------------------------------------------
| Cost / Overhead Constants
|--------------------------------------------------------------------------
*/

export const OVERHEAD_TYPES = [
  ["labour", "Direct Labour"],
  ["salary", "Salary"],
  ["electricity", "Electricity"],
  ["fuel", "Fuel"],
  ["water", "Water"],
  ["rent", "Rent"],
  ["machine_maintenance", "Machine Maintenance"],
  ["depreciation", "Depreciation"],
  ["other", "Other"],
];

export const DIRECT_OVERHEAD_TYPES = ["labour"];

export const INDIRECT_OVERHEAD_TYPES = [
  "salary",
  "electricity",
  "fuel",
  "water",
  "rent",
  "machine_maintenance",
  "depreciation",
  "other",
];

/*
|--------------------------------------------------------------------------
| Date Helpers
|--------------------------------------------------------------------------
*/

export const today = () => new Date().toISOString().slice(0, 10);

export const todayPlusDays = (days = 1) => {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return date.toISOString().slice(0, 10);
};

export const safeDate = (value) => {
  if (!value) return "-";
  return String(value).slice(0, 10);
};

/*
|--------------------------------------------------------------------------
| Text Helpers
|--------------------------------------------------------------------------
*/

export const titleCase = (value = "") => {
  return String(value || "")
    .replaceAll("_", " ")
    .replaceAll("-", " ")
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());
};

export const formatProductType = (value) => titleCase(value || "-");

export const statusLabel = (status = "draft") => {
  return String(status || "draft").replaceAll("_", " ").toUpperCase();
};

/*
|--------------------------------------------------------------------------
| Number / Money Helpers
|--------------------------------------------------------------------------
*/

export const numberValue = (value) => Number(value || 0);

export const qtyNumber = (value) => Number(value || 0).toFixed(4);

export const moneyNumber = (value) => Number(value || 0).toFixed(2);

export const moneyWithCurrency = (currency = "USD", value = 0, decimals = 2) => {
  return `${currency || "USD"} ${Number(value || 0).toFixed(decimals)}`;
};

export const qtyWithUnit = (value = 0, unit = "") => {
  return `${qtyNumber(value)}${unit ? ` ${unit}` : ""}`;
};

/*
|--------------------------------------------------------------------------
| Response Helpers
|--------------------------------------------------------------------------
*/

export const normalizeRows = (response) => {
  const payload = response?.data;

  if (Array.isArray(response)) return response;
  if (Array.isArray(payload)) return payload;

  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(response?.data?.data)) return response.data.data;
  if (Array.isArray(response?.data?.data?.data)) return response.data.data.data;

  if (Array.isArray(response?.data?.items)) return response.data.items;
  if (Array.isArray(response?.items)) return response.items;

  return [];
};

export const getPayloadData = (response) =>
  response?.data?.data || response?.data || response || null;

export const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;

  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }

  if (data?.error) return data.error;
  if (data?.message) return data.message;
  if (error?.message) return error.message;

  return fallback;
};

/*
|--------------------------------------------------------------------------
| User / Branch Helpers
|--------------------------------------------------------------------------
*/

export const getBranchId = (currentUser) =>
  currentUser?.branch?.id ||
  currentUser?.branch_id ||
  currentUser?.branchId ||
  "";

/*
|--------------------------------------------------------------------------
| Product Helpers
|--------------------------------------------------------------------------
*/

export const getProductName = (product) =>
  product?.name ||
  product?.product_name ||
  product?.finished_product_name ||
  product?.metadata?.product_name ||
  "-";

export const getProductCode = (product) =>
  product?.code ||
  product?.sku ||
  product?.barcode ||
  product?.metadata?.product_code ||
  "-";

export const getProductUnit = (product) =>
  product?.uom?.code ||
  product?.uom?.name ||
  product?.base_uom?.code ||
  product?.baseUom?.code ||
  product?.unit ||
  product?.metadata?.unit ||
  "-";

export const getProductStock = (product) => {
  const stocks = Array.isArray(product?.stocks) ? product.stocks : [];

  if (stocks.length > 0) {
    return stocks.reduce(
      (sum, item) => sum + numberValue(item.available_quantity),
      0
    );
  }

  return numberValue(
    product?.available_quantity ||
      product?.stock_available ||
      product?.stock ||
      product?.metadata?.available_stock
  );
};

export const getProductCost = (product) => {
  const costs = Array.isArray(product?.costs) ? product.costs : [];
  const first = costs[0];

  return numberValue(
    first?.average_cost ||
      product?.cost?.average_cost ||
      first?.last_cost ||
      product?.average_cost ||
      product?.cost ||
      product?.unit_cost ||
      product?.metadata?.unit_cost
  );
};

export const getProductCurrency = (product, fallback = "") => {
  const costs = Array.isArray(product?.costs) ? product.costs : [];

  return (
    costs[0]?.currency ||
    product?.cost?.currency ||
    product?.currency ||
    product?.metadata?.currency ||
    fallback ||
    ""
  );
};

export const getProductAverageCostLabel = (product, fallbackCurrency = "USD") => {
  const currency = getProductCurrency(product, fallbackCurrency);
  const cost = getProductCost(product);

  return moneyWithCurrency(currency, cost, 4);
};

/*
|--------------------------------------------------------------------------
| Notice Hook
|--------------------------------------------------------------------------
*/

export const useNotice = () => {
  const [notice, setNotice] = useState(null);

  const showNotice = (message, type = "info", title = "Notice") => {
    setNotice({
      message,
      type,
      title,
      key: Date.now(),
    });
  };

  const closeNotice = () => setNotice(null);

  return {
    notice,
    showNotice,
    closeNotice,
  };
};

/*
|--------------------------------------------------------------------------
| Feedback Components
|--------------------------------------------------------------------------
*/

export function Notice({ notice, onClose }) {
  if (!notice) return null;

  const isError = notice.type === "error";

  return (
    <div className="fixed right-4 top-4 z-[200000] w-[420px] max-w-[calc(100vw-2rem)] border border-[#AFB9B9] bg-white shadow-2xl">
      <div
        className={`border-b px-3 py-2 text-[12px] font-semibold uppercase ${
          isError
            ? "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"
            : "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]"
        }`}
      >
        {notice.title || (isError ? "Error" : "Notice")}
      </div>

      <div className="whitespace-pre-line px-3 py-3 text-[12px] font-medium text-[#324443]">
        {notice.message}
      </div>

      <div className="flex justify-end border-t border-[#E9EDED] bg-[#F8FAFC] px-3 py-2">
        <button
          type="button"
          onClick={onClose}
          className="h-7 border border-[#AFB9B9] bg-white px-3 text-[11px] font-semibold text-[#324443]"
        >
          Close
        </button>
      </div>
    </div>
  );
}

export function LoadingPill({ loading }) {
  if (!loading) return null;

  return (
    <div className="fixed left-1/2 top-4 z-[200000] -translate-x-1/2 border border-[#00BCBC] bg-[#E8F7F5] px-4 py-2 text-[11px] font-semibold uppercase text-[#005A5A] shadow-lg">
      Processing...
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Buttons
|--------------------------------------------------------------------------
*/

export function ActionButton({
  label,
  children,
  icon: Icon,
  onClick,
  disabled = false,
  primary = false,
  danger = false,
}) {
  let cls = "border-[#AFB9B9] bg-white text-[#324443] hover:bg-[#EEF2F2]";

  if (primary) {
    cls = "border-[#027A48] bg-[#027A48] text-white hover:bg-[#05603A]";
  }

  if (danger) {
    cls = "border-[#B42318] bg-[#B42318] text-white hover:bg-[#912018]";
  }

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`inline-flex h-7 items-center gap-1 border px-3 text-[11px] font-semibold uppercase disabled:opacity-50 ${cls}`}
    >
      {Icon && <Icon className="h-3.5 w-3.5" />}
      {label || children}
    </button>
  );
}

export function SmallButton({
  children,
  onClick,
  disabled = false,
  danger = false,
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`h-7 border px-2 text-[10px] font-semibold uppercase disabled:opacity-50 ${
        danger
          ? "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"
          : "border-[#AFB9B9] bg-white text-[#324443] hover:bg-[#EEF2F2]"
      }`}
    >
      {children}
    </button>
  );
}

export function IconButton({
  icon: Icon,
  onClick,
  disabled = false,
  danger = false,
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`inline-flex h-7 w-7 items-center justify-center border disabled:opacity-50 ${
        danger
          ? "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"
          : "border-[#AFB9B9] bg-white text-[#324443]"
      }`}
    >
      <Icon className="h-3.5 w-3.5" />
    </button>
  );
}

/*
|--------------------------------------------------------------------------
| Badges
|--------------------------------------------------------------------------
*/

export function StatusBadge({ status }) {
  const clean = String(status || "draft");

  const good = [
    "active",
    "approved",
    "available",
    "materials_reserved",
    "materials_issued",
    "in_progress",
    "completed",
    "reserved",
    "converted_to_orders",
  ].includes(clean);

  const bad = [
    "inactive",
    "cancelled",
    "purchase_required",
    "failed",
    "shortage",
  ].includes(clean);

  return (
    <span
      className={`inline-flex border px-2 py-1 text-[10px] font-semibold uppercase ${
        bad
          ? "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"
          : good
            ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
            : "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]"
      }`}
    >
      {clean.replaceAll("_", " ")}
    </span>
  );
}

/*
|--------------------------------------------------------------------------
| Inputs
|--------------------------------------------------------------------------
*/

export function MiniInput({
  value,
  onChange,
  type = "text",
  disabled = false,
  readOnly = false,
  step = "0.0001",
  right = false,
  placeholder = "",
}) {
  return (
    <input
      type={type}
      step={type === "number" ? step : undefined}
      value={value ?? ""}
      disabled={disabled}
      readOnly={readOnly}
      placeholder={placeholder}
      onChange={(event) => {
        if (!readOnly && onChange) onChange(event.target.value);
      }}
      className={`h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2] ${
        readOnly
          ? "cursor-not-allowed bg-[#EEF2F2] font-semibold text-[#004343]"
          : ""
      } ${right ? "text-right" : ""}`}
    />
  );
}

export function Field({
  label,
  value,
  onChange,
  type = "text",
  disabled = false,
  readOnly = false,
  placeholder = "",
  step = "0.0001",
  right = false,
}) {
  return (
    <label className="block">
      {label && (
        <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
          {label}
        </div>
      )}

      <input
        type={type}
        step={type === "number" ? step : undefined}
        value={value ?? ""}
        disabled={disabled}
        readOnly={readOnly}
        placeholder={placeholder}
        onChange={(event) => {
          if (!readOnly && onChange) onChange(event.target.value);
        }}
        className={`h-8 w-full border border-[#AFB9B9] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2] ${
          readOnly
            ? "cursor-not-allowed bg-[#EEF2F2] font-semibold text-[#004343]"
            : ""
        } ${right ? "text-right" : ""}`}
      />
    </label>
  );
}

export const FormInput = Field;

export function SelectField({
  label,
  value,
  onChange,
  options = [],
  disabled = false,
}) {
  return (
    <label className="block">
      {label && (
        <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
          {label}
        </div>
      )}

      <select
        value={value ?? ""}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        className="h-8 w-full border border-[#AFB9B9] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
      >
        {options.map(([key, text]) => (
          <option key={key} value={key}>
            {text}
          </option>
        ))}
      </select>
    </label>
  );
}

export const FormSelect = SelectField;

/*
|--------------------------------------------------------------------------
| Layout Components
|--------------------------------------------------------------------------
*/

export function SectionTitle({ title, subtitle, actions }) {
  return (
    <div className="flex min-h-[32px] items-center justify-between border-b border-[#AAB5B5] bg-[#EEF2F2] px-2">
      <div>
        <div className="text-[11px] font-semibold uppercase text-[#324443]">
          {title}
        </div>

        {subtitle && (
          <div className="text-[9px] font-medium uppercase text-[#627474]">
            {subtitle}
          </div>
        )}
      </div>

      {actions && <div className="flex items-center gap-1">{actions}</div>}
    </div>
  );
}

export function Panel({ title, subtitle, children, actions }) {
  return (
    <section className="border border-[#AFB9B9] bg-white">
      <div className="flex min-h-[38px] items-center justify-between border-b border-[#D8DFDF] bg-[#EEF2F2] px-3 py-2">
        <div>
          <div className="text-[12px] font-semibold uppercase text-[#324443]">
            {title}
          </div>

          {subtitle && (
            <div className="text-[10px] font-medium text-[#627474]">
              {subtitle}
            </div>
          )}
        </div>

        {actions}
      </div>

      <div className="p-2">{children}</div>
    </section>
  );
}

export function PageShell({ title, subtitle, actions, children }) {
  return (
    <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
      <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
        <div className="flex min-h-[42px] items-center justify-between border-b border-[#AAB5B5] bg-[#F5F7FA] px-3">
          <div>
            <div className="text-[13px] font-semibold uppercase text-[#0C1B1B]">
              {title}
            </div>

            {subtitle && (
              <div className="text-[10px] font-medium uppercase text-[#627474]">
                {subtitle}
              </div>
            )}
          </div>

          {actions}
        </div>

        <div className="min-h-0 flex-1 overflow-auto p-2">{children}</div>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Data Table Components
|--------------------------------------------------------------------------
*/

export function DataTable({ columns = [], children, emptyText = "No records." }) {
  const hasChildren = React.Children.count(children) > 0;

  return (
    <div className="overflow-auto border border-[#CFD9D8] bg-white">
      <table className="w-full min-w-[900px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            {columns.map((column) => (
              <th
                key={column}
                className="border-r border-[#465857] px-2 py-1.5 font-semibold"
              >
                {column}
              </th>
            ))}
          </tr>
        </thead>

        <tbody>
          {hasChildren ? (
            children
          ) : (
            <tr>
              <td
                colSpan={columns.length || 1}
                className="px-3 py-10 text-center text-[#627474]"
              >
                {emptyText}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

export function Td({ children, right = false, strong = false }) {
  return (
    <td
      className={`border-r border-[#CFD9D8] px-2 py-1.5 ${
        right ? "text-right" : "text-left"
      } ${strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}
    >
      {children}
    </td>
  );
}

export function GridTh({ children, align = "left" }) {
  return (
    <th
      className={`border-r border-[#465857] px-2 py-1.5 font-semibold ${
        align === "right" ? "text-right" : "text-left"
      }`}
    >
      {children}
    </th>
  );
}

export function GridTd({ children, right = false, strong = false }) {
  return (
    <td
      className={`border-r border-[#CFD9D8] px-2 py-1.5 ${
        right ? "text-right" : "text-left"
      } ${strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}
    >
      {children}
    </td>
  );
}

/*
|--------------------------------------------------------------------------
| Metrics / Visuals
|--------------------------------------------------------------------------
*/

export function MetricCard({ title, value, icon: Icon, danger = false }) {
  return (
    <div className="border border-[#AFB9B9] bg-white p-3">
      <div className="flex items-center gap-1 text-[10px] font-semibold uppercase text-[#627474]">
        {Icon && <Icon className="h-3.5 w-3.5" />}
        {title}
      </div>

      <div
        className={`mt-2 truncate text-[22px] font-semibold ${
          danger ? "text-[#B42318]" : "text-[#004343]"
        }`}
      >
        {value}
      </div>
    </div>
  );
}

export function SmallMetric({ label, value, danger = false }) {
  return (
    <div className="border border-[#D8DFDF] bg-[#F8FAFC] p-2">
      <div className="text-[9px] font-semibold uppercase text-[#627474]">
        {label}
      </div>

      <div
        className={`mt-1 truncate text-[13px] font-semibold ${
          danger ? "text-[#B42318]" : "text-[#004343]"
        }`}
      >
        {value}
      </div>
    </div>
  );
}

export function EmptyBlock({ text }) {
  return (
    <div className="flex h-full items-center justify-center text-[12px] font-semibold text-[#627474]">
      {text}
    </div>
  );
}

export function ReadinessLine({ label, ok, value }) {
  return (
    <div className="flex items-center justify-between border-b border-[#EFF2F2] py-2 text-[11px] last:border-b-0">
      <span className="font-semibold text-[#324443]">{label}</span>

      <span
        className={
          ok ? "font-semibold text-[#067647]" : "font-semibold text-[#B42318]"
        }
      >
        {value}
      </span>
    </div>
  );
}

export function CostBar({ label, value, total, currency = "" }) {
  const percent = total > 0 ? (numberValue(value) / total) * 100 : 0;

  return (
    <div className="mb-2">
      <div className="mb-1 flex justify-between text-[11px] font-semibold text-[#324443]">
        <span>{label}</span>

        <span>
          {currency ? moneyWithCurrency(currency, value) : moneyNumber(value)} (
          {percent.toFixed(1)}%)
        </span>
      </div>

      <div className="h-4 border border-[#B9C3C3] bg-[#F2F4F7]">
        <div
          className="h-full bg-[#004344]"
          style={{ width: `${Math.min(100, percent)}%` }}
        />
      </div>
    </div>
  );
}

export function MiniBar({ value, max }) {
  const percent =
    max > 0 ? Math.min(100, (numberValue(value) / max) * 100) : 0;

  return (
    <div className="h-3 w-[120px] border border-[#B9C3C3] bg-[#F2F4F7]">
      <div className="h-full bg-[#004344]" style={{ width: `${percent}%` }} />
    </div>
  );
}