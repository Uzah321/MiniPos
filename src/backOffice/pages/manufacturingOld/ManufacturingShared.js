import React from "react";
import { PackageSearch, RefreshCw, Plus, Trash2, Search, X } from "lucide-react";

export const numberValue = (value) => Number(value || 0);
export const moneyNumber = (value) => Number(value || 0).toFixed(2);
export const qtyNumber = (value) => Number(value || 0).toFixed(4);
export const today = () => new Date().toISOString().slice(0, 10);
export const todayPlusDays = (days = 7) =>
  new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);

export const normalizeRows = (response) => {
  const payload = response?.data ?? response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.rows)) return payload.rows;
  return [];
};

export const getPayloadData = (response) =>
  response?.data?.data || response?.data || response || null;

export const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;
  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }
  return data?.message || data?.error || error?.message || fallback;
};

export const getBranchId = (currentUser) =>
  currentUser?.branch_id || currentUser?.branch?.id || currentUser?.data?.branch_id || "";

export const getProductName = (product) =>
  product?.name || product?.product_name || product?.description || "Product";
export const getProductCode = (product) =>
  product?.code || product?.sku || product?.barcode || "";
export const getProductUnit = (product) =>
  product?.uom?.code || product?.uom?.name || "EACH";
export const getProductStock = (product) => {
  if (!Array.isArray(product?.stocks)) return 0;
  return product.stocks.reduce(
    (sum, stock) => sum + Number(stock.available_quantity ?? stock.quantity_on_hand ?? 0),
    0
  );
};
export const getProductCost = (product) => {
  if (Array.isArray(product?.costs) && product.costs.length) {
    const current = product.costs.find((item) => item?.is_current);
    const cost = current || product.costs[0];
    return Number(cost?.average_cost || cost?.last_cost || 0);
  }
  return Number(product?.average_cost || product?.last_cost || product?.cost_price || product?.unit_cost || 0);
};

export const statusTone = (status) => {
  const clean = String(status || "").toLowerCase();
  if (["active", "approved", "materials_reserved", "reserved"].includes(clean)) {
    return "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]";
  }
  if (["materials_issued", "in_progress", "purchase_required"].includes(clean)) {
    return "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]";
  }
  if (["completed", "converted_to_orders"].includes(clean)) {
    return "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]";
  }
  if (["cancelled", "inactive", "failed"].includes(clean)) {
    return "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]";
  }
  return "border-[#E4E8E8] bg-[#F9FAFB] text-[#324443]";
};

export function useNotice() {
  const [notice, setNotice] = React.useState("");
  const showNotice = React.useCallback((message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 4500);
  }, []);
  return { notice, showNotice };
}

export function Notice({ notice }) {
  if (!notice) return null;
  return (
    <div className="absolute left-1/2 top-3 z-[200] max-w-[760px] -translate-x-1/2 whitespace-pre-line border border-[#004343] bg-[#004344] px-4 py-2 text-center text-[12px] font-semibold text-white shadow-xl">
      {notice}
    </div>
  );
}

export function LoadingPill({ loading }) {
  if (!loading) return null;
  return (
    <div className="absolute right-3 top-3 z-[200] border border-[#A4E5E4] bg-white px-3 py-2 text-[11px] font-semibold text-[#057B7B] shadow-xl">
      Processing...
    </div>
  );
}

export function PageShell({ title, subtitle, children, actions }) {
  return (
    <div className="relative h-[calc(100vh-86px)] overflow-hidden bg-[#D8DFDF] p-3 text-[#1C2C2B]">
      <div className="flex h-full min-h-0 flex-col border border-[#AFB9B9] bg-white">
        <div className="flex shrink-0 items-center justify-between border-b border-[#AFB9B9] bg-[#EEF2F2] px-3 py-2">
          <div>
            <div className="text-[14px] font-bold text-[#0C1B1B]">{title}</div>
            <div className="text-[11px] text-[#627474]">{subtitle}</div>
          </div>
          <div className="flex flex-wrap items-center gap-2">{actions}</div>
        </div>
        <div className="min-h-0 flex-1 overflow-auto p-3">{children}</div>
      </div>
    </div>
  );
}

export function Panel({ title, children, actions }) {
  return (
    <div className="mb-3 border border-[#AFB9B9] bg-white">
      <div className="flex items-center justify-between border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-2">
        <div className="text-[12px] font-bold text-[#324443]">{title}</div>
        <div>{actions}</div>
      </div>
      <div className="p-2">{children}</div>
    </div>
  );
}

export function MetricCard({ title, value, small = false }) {
  return (
    <div className="border border-[#AFB9B9] bg-white p-3">
      <div className="text-[10px] font-semibold uppercase text-[#627474]">{title}</div>
      <div className={`mt-2 font-black text-[#004343] ${small ? "truncate text-[15px]" : "text-[24px]"}`}>
        {value}
      </div>
    </div>
  );
}

export function SummaryBar({ items }) {
  return (
    <div className="grid gap-2 md:grid-cols-4">
      {items.map(([label, value]) => <MetricCard key={label} title={label} value={value} />)}
    </div>
  );
}

export function DataTable({ columns, children, emptyText }) {
  const rows = React.Children.toArray(children).filter(Boolean);
  return (
    <div className="overflow-auto border border-[#D8DFDF]">
      <table className="w-full min-w-[860px] border-collapse text-[12px]">
        <thead>
          <tr className="border-b border-[#D8DFDF] bg-[#F4F6F8] text-left text-[10px] uppercase text-[#627474]">
            {columns.map((column) => <th key={column} className="px-2 py-2 font-bold">{column}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.length ? rows : (
            <tr>
              <td colSpan={columns.length} className="py-10 text-center text-[12px] text-[#627474]">
                <PackageSearch className="mx-auto mb-2 h-7 w-7" />
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
    <td className={`px-2 py-2 text-[12px] ${right ? "text-right" : ""} ${strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}>
      {children}
    </td>
  );
}

export function StatusBadge({ status }) {
  return (
    <span className={`inline-flex border px-2 py-1 text-[9px] font-bold uppercase ${statusTone(status)}`}>
      {status || "draft"}
    </span>
  );
}

export function ActionButton({ label, onClick, icon: Icon, primary = false, danger = false, disabled = false }) {
  let cls = "border-[#B9C3C3] bg-white text-[#324443]";
  if (primary) cls = "border-[#004343] bg-[#004344] text-white";
  if (danger) cls = "border-[#B42318] bg-[#B42318] text-white";
  return (
    <button type="button" disabled={disabled} onClick={onClick} className={`inline-flex h-8 items-center gap-1 border px-3 text-[11px] font-bold disabled:opacity-60 ${cls}`}>
      {Icon && <Icon className="h-3.5 w-3.5" />}
      {label}
    </button>
  );
}

export function SmallButton({ children, onClick, disabled = false }) {
  return (
    <button type="button" disabled={disabled} onClick={onClick} className="h-7 border border-[#004343] bg-[#004344] px-2 text-[10px] font-bold text-white disabled:opacity-60">
      {children}
    </button>
  );
}

export function IconButton({ onClick, icon: Icon, danger = false }) {
  return (
    <button type="button" onClick={onClick} className={`inline-flex h-7 w-7 items-center justify-center border ${danger ? "border-[#FECDCA] bg-white text-[#B42318]" : "border-[#B9C3C3] bg-white text-[#324443]"}`}>
      <Icon className="h-3.5 w-3.5" />
    </button>
  );
}

export function Field({ label, value, onChange, type = "text", placeholder = "", disabled = false }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-bold uppercase text-[#627474]">{label}</div>
      <input type={type} value={value ?? ""} placeholder={placeholder} disabled={disabled} onChange={(e) => onChange?.(e.target.value)} className="h-9 w-full border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none focus:border-[#004343] disabled:bg-[#EEF2F2]" />
    </label>
  );
}

export function SelectField({ label, value, onChange, options = [] }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-bold uppercase text-[#627474]">{label}</div>
      <select value={value ?? ""} onChange={(e) => onChange?.(e.target.value)} className="h-9 w-full border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none focus:border-[#004343]">
        {options.map(([key, labelText]) => <option key={key} value={key}>{labelText}</option>)}
      </select>
    </label>
  );
}

export function MiniInput({ value, onChange, type = "text" }) {
  return <input type={type} value={value ?? ""} onChange={(e) => onChange?.(e.target.value)} className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]" />;
}

export function EmptyState({ text }) {
  return <div className="flex h-[260px] flex-col items-center justify-center text-[12px] text-[#627474]"><PackageSearch className="mb-2 h-8 w-8" />{text}</div>;
}

export { RefreshCw, Plus, Trash2, Search, X };
