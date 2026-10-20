import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Search } from "lucide-react";

import { useOptionalWorkspace } from "../context/WorkspaceContext";
import { getPosUsers } from "../services/AuthenticationService";
import { getCurrencies } from "../services/CurrencyService";
import { getShiftSessions } from "../services/ShiftService";
import { getTills } from "../services/TillService";

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

export const reportCurrencyCode = (currency) => String(currency?.code || currency?.currency_code || "").toUpperCase();
export const isReportBaseCurrency = (currency) => currency?.is_base_currency === true || currency?.is_base_currency === 1 || currency?.is_base_currency === "1" || String(currency?.is_base_currency).toLowerCase() === "true";

const lookupBranchIds = (row) => [
  row?.branch_id,
  row?.branch?.id,
  ...(Array.isArray(row?.branches) ? row.branches.map((branch) => branch?.id || branch?.branch_id) : []),
]
  .filter((branchId) => branchId != null && String(branchId).trim() !== "")
  .map((branchId) => String(branchId));

export const filterReportLookupsByBranchIds = (rows = [], branchIds = []) => {
  const allowed = new Set((branchIds || []).map((branchId) => String(branchId)));
  if (!allowed.size) return [];

  return (rows || []).filter((row) => lookupBranchIds(row).some((branchId) => allowed.has(branchId)));
};

const branchLabel = (branch) => branch?.branch_name || branch?.name || branch?.display_name || branch?.code || "Unnamed Branch";
const tillLabel = (till) => {
  const code = till?.till_code || till?.code || "";
  const name = till?.till_name || till?.name || "";
  return code && name ? `${code} - ${name}` : code || name || "Unnamed Till";
};
const cashierLabel = (cashier) => cashier?.name || cashier?.full_name || cashier?.username || cashier?.email || "Unknown Cashier";
const currencyLabel = (currency) => {
  const code = reportCurrencyCode(currency);
  const name = currency?.name || currency?.currency_name || "";
  return code && name ? `${code} - ${name}` : code || name;
};
const shiftLabel = (shift) => {
  const name = shift?.shift_name || shift?.name || "Shift";
  if (!shift?.opened_at) return name;
  const date = new Date(shift.opened_at);
  return Number.isNaN(date.getTime()) ? name : `${name} / ${date.toLocaleString("en-ZW")}`;
};

export const useHospitalityReportLookups = (open, filters = {}) => {
  const workspace = useOptionalWorkspace();
  const workspaceBranches = useMemo(
    () => workspace?.data?.available_branches || [],
    [workspace?.data?.available_branches]
  );
  const [state, setState] = useState({
    branches: [], tills: [], cashiers: [], currencies: [], shiftSessions: [], baseCurrencyCode: "", loadingLookups: false,
  });

  const loadLookups = useCallback(async (nextFilters = {}) => {
    setState((current) => ({ ...current, loadingLookups: true }));
    const [tills, currencies, cashiers, shifts] = await Promise.allSettled([
      getTills({ per_page: 300, is_active: true, ...(nextFilters.branch_id ? { branch_id: nextFilters.branch_id } : {}) }),
      getCurrencies({ per_page: 300, is_active: true }),
      getPosUsers({ per_page: 300, is_active: true, ...(nextFilters.branch_id ? { branch_id: nextFilters.branch_id } : {}) }),
      getShiftSessions({
        per_page: 300,
        ...(nextFilters.branch_id ? { branch_id: nextFilters.branch_id } : {}),
        ...(nextFilters.date_from ? { date_from: nextFilters.date_from } : {}),
        ...(nextFilters.date_to ? { date_to: nextFilters.date_to } : {}),
      }),
    ]);
    const allowedBranchIds = workspaceBranches.map((branch) => branch?.id).filter(Boolean);
    const currencyRows = currencies.status === "fulfilled" ? normalizeList(currencies.value) : [];
    const baseCurrency = currencyRows.find(isReportBaseCurrency) || currencyRows[0] || null;
    setState({
      branches: workspaceBranches,
      tills: tills.status === "fulfilled" ? filterReportLookupsByBranchIds(normalizeList(tills.value), allowedBranchIds) : [],
      cashiers: cashiers.status === "fulfilled" ? filterReportLookupsByBranchIds(normalizeList(cashiers.value), allowedBranchIds) : [],
      currencies: currencyRows,
      shiftSessions: shifts.status === "fulfilled" ? filterReportLookupsByBranchIds(normalizeList(shifts.value), allowedBranchIds) : [],
      baseCurrencyCode: reportCurrencyCode(baseCurrency),
      loadingLookups: false,
    });
  }, [workspaceBranches]);

  useEffect(() => {
    if (open) loadLookups(filters);
    // Branch and period changes explicitly reload the dependent lookups in the panel.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, loadLookups]);

  const maps = useMemo(() => ({
    branchById: Object.fromEntries(state.branches.map((item) => [item.id, item])),
    tillById: Object.fromEntries(state.tills.map((item) => [item.id, item])),
    cashierById: Object.fromEntries(state.cashiers.map((item) => [item.id, item])),
    shiftById: Object.fromEntries(state.shiftSessions.map((item) => [item.id, item])),
  }), [state.branches, state.cashiers, state.shiftSessions, state.tills]);

  return { ...state, ...maps, loadLookups };
};

const ScopeCell = ({ label, children, className = "" }) => (
  <div className={`min-h-[70px] bg-white p-3 ${className}`}>
    <div className="mb-1 text-[11px] font-semibold text-[#637877]">{label}</div>
    {children}
  </div>
);

const CurrencyChecklist = ({ currencies = [], selected = [], onChange, disabled = false }) => {
  const activeCurrencies = currencies.filter((currency) => ![false, 0, "0"].includes(currency?.is_active));
  const codes = activeCurrencies.map(reportCurrencyCode).filter(Boolean);
  const requestedCodes = (selected || []).map((value) => String(value).toUpperCase()).filter((code) => codes.includes(code));
  const allSelected = requestedCodes.length === 0 || requestedCodes.length === codes.length;
  const selectedSet = new Set(allSelected ? codes : requestedCodes);
  const selectAll = () => {
    if (disabled || allSelected) return;
    onChange([]);
  };
  const toggle = (currencyCode) => {
    if (disabled) return;
    const next = new Set(selectedSet);
    if (next.has(currencyCode)) next.delete(currencyCode); else next.add(currencyCode);
    if (!next.size) return;
    const nextCodes = codes.filter((code) => next.has(code));
    onChange(nextCodes.length === codes.length ? [] : nextCodes);
  };

  if (!codes.length) return <div className="flex min-h-[38px] items-center border border-[#B9C0C0] bg-[#F7F8F9] px-3 text-[11px] text-[#7C8685]">No active currency</div>;
  return (
    <div className={`flex min-h-[38px] flex-wrap items-center gap-x-3 gap-y-1 border border-[#B9C0C0] px-2 py-1 ${disabled ? "bg-[#F7F8F9] opacity-70" : "bg-white"}`}>
      <label className="flex cursor-pointer items-center gap-1 text-[11px] font-semibold text-[#293232]"><input type="checkbox" disabled={disabled} checked={allSelected} onChange={selectAll} /><span>All currencies</span></label>
      {activeCurrencies.map((currency) => {
        const code = reportCurrencyCode(currency);
        if (!code) return null;
        return <label key={currency.id || code} className="flex cursor-pointer items-center gap-1 text-[11px] text-[#293232]"><input type="checkbox" disabled={disabled} checked={selectedSet.has(code)} onChange={() => toggle(code)} /><span>{currencyLabel(currency)}{isReportBaseCurrency(currency) ? " · Base" : ""}</span></label>;
      })}
    </div>
  );
};

export default function HospitalityReportScopePanel({
  filters,
  setFilters,
  lookups,
  onApply,
  loading = false,
  businessModeLabel = "Hospitality",
  singleTradingDate = false,
  periodLabel = null,
  actions = null,
  onFilterChange,
  supported = {},
  children = null,
  extraCells = [],
  generateLabel = "Generate",
}) {
  const enabled = (key) => supported[key] !== false;
  const update = async (key, value) => {
    if (!enabled(key)) return;
    const next = { ...filters, [key]: value, page: 1 };
    if (key === "business_date") {
      next.date_from = value;
      next.date_to = value;
    }
    if (key === "branch_id") {
      next.till_id = "";
      next.cashier_id = "";
      next.shift_session_id = "";
    }
    if (key === "currency_codes") next.currency_code = value.length === 1 ? value[0] : "";
    setFilters(next);
    if (["branch_id", "date_from", "date_to", "business_date"].includes(key)) await lookups?.loadLookups?.(next);
    await onFilterChange?.(next, key, value);
  };

  const disabledClass = (key) => enabled(key) ? "" : "bg-[#F7F8F9] text-[#7C8685]";
  return (
    <div className="border-b border-[#ACB8B8] bg-[#F5F7FA] p-3 no-print">
      <div className="grid max-w-[980px] grid-cols-1 gap-px border border-[#D8DCDC] bg-[#D8DCDC] md:grid-cols-2">
        <ScopeCell label="Branch"><select disabled={!enabled("branch_id")} value={filters.branch_id || ""} onChange={(event) => update("branch_id", event.target.value)} className={`hospitality-report-input ${disabledClass("branch_id")}`}><option value="">All branches</option>{lookups?.branches?.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></ScopeCell>
        <ScopeCell label={periodLabel || (singleTradingDate ? "Trading Date" : "Period")}>{singleTradingDate ? <input disabled={!enabled("business_date")} aria-label="Trading date" type="date" value={filters.business_date || filters.date_from || ""} onChange={(event) => update("business_date", event.target.value)} className={`hospitality-report-input ${disabledClass("business_date")}`} /> : <div className="grid grid-cols-2 gap-2"><input disabled={!enabled("date_from")} aria-label="Date from" type="date" value={filters.date_from || ""} onChange={(event) => update("date_from", event.target.value)} className={`hospitality-report-input ${disabledClass("date_from")}`} /><input disabled={!enabled("date_to")} aria-label="Date to" type="date" value={filters.date_to || ""} onChange={(event) => update("date_to", event.target.value)} className={`hospitality-report-input ${disabledClass("date_to")}`} /></div>}</ScopeCell>
        <ScopeCell label="Till"><select disabled={!enabled("till_id")} value={filters.till_id || ""} onChange={(event) => update("till_id", event.target.value)} className={`hospitality-report-input ${disabledClass("till_id")}`}><option value="">All tills</option>{lookups?.tills?.map((till) => <option key={till.id} value={till.id}>{tillLabel(till)}</option>)}</select></ScopeCell>
        <ScopeCell label="Cashier"><select disabled={!enabled("cashier_id")} value={filters.cashier_id || ""} onChange={(event) => update("cashier_id", event.target.value)} className={`hospitality-report-input ${disabledClass("cashier_id")}`}><option value="">All cashiers</option>{lookups?.cashiers?.map((cashier) => <option key={cashier.id} value={cashier.id}>{cashierLabel(cashier)}</option>)}</select></ScopeCell>
        <ScopeCell label="Shift"><select disabled={!enabled("shift_session_id")} value={filters.shift_session_id || ""} onChange={(event) => update("shift_session_id", event.target.value)} className={`hospitality-report-input ${disabledClass("shift_session_id")}`}><option value="">All shifts</option>{lookups?.shiftSessions?.map((shift) => <option key={shift.id} value={shift.id}>{shiftLabel(shift)}</option>)}</select></ScopeCell>
        <ScopeCell label="Currencies"><CurrencyChecklist disabled={!enabled("currency_codes")} currencies={lookups?.currencies || []} selected={filters.currency_codes || (filters.currency_code ? [filters.currency_code] : [])} onChange={(value) => update("currency_codes", value)} /></ScopeCell>
        {(Array.isArray(extraCells) ? extraCells : []).map((cell, index) => (
          <ScopeCell
            key={cell?.key || `${cell?.label || "report-filter"}-${index}`}
            label={cell?.label || "Report Filter"}
            className={cell?.className || ""}
          >
            {cell?.content}
          </ScopeCell>
        ))}
        <ScopeCell label="Business Mode"><div className="flex h-[38px] items-center border border-[#D8DCDC] bg-[#F7F8F9] px-3 text-[12px] font-semibold text-[#4D5756]">{businessModeLabel}</div></ScopeCell>
        <ScopeCell label="Actions"><div className="flex min-h-[38px] flex-wrap items-center justify-end gap-2">{actions}<button type="button" disabled={loading} onClick={onApply} className="inline-flex h-9 items-center gap-1 border border-[#057B7C] bg-[#069495] px-4 text-[12px] font-semibold text-white disabled:opacity-60"><Search className="h-3.5 w-3.5" />{loading ? "Generating" : generateLabel}</button></div></ScopeCell>
      </div>
      {children && <div className="mt-2 max-w-[980px]">{children}</div>}
      <style>{`.hospitality-report-input { height: 38px; width: 100%; border: 1px solid #B9C0C0; background-color: white; padding: 0 10px; font-size: 12px; color: #293232; outline: none; } .hospitality-report-input:focus { border-color: #069495; }`}</style>
    </div>
  );
}
