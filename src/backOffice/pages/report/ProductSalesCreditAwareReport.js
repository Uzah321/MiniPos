// src/backOffice/pages/reports/ProductSalesCreditAwareReport.jsx

import React, { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw, Search } from "lucide-react";
import api from "../../../utils/apiBack";
import { getCurrencies } from "../../../services/CurrencyService";

const companyHeaders = () => ({ "X-Company-Domain": window.location.hostname });
const today = () => new Date().toISOString().slice(0, 10);

const money = (value, currency = "USD") => `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;
const qty = (value) => Number(value || 0).toFixed(4);
const isBaseCurrency = (row) => row?.is_base_currency === true || row?.is_base_currency === 1 || row?.is_base_currency === "1" || String(row?.is_base_currency).toLowerCase() === "true";
const currencyCodeOf = (row) => row?.code || row?.currency_code || "";

const normalizeRows = (payload) => {
  const data = payload?.data ?? payload;
  if (Array.isArray(data)) return data;
  if (Array.isArray(data?.data)) return data.data;
  if (Array.isArray(data?.rows)) return data.rows;
  return [];
};

export default function ProductSalesCreditAwareReport({ open = true, onClose }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [currencies, setCurrencies] = useState([]);
  const [filters, setFilters] = useState({
    date_from: today(),
    date_to: today(),
    currency_code: "",
    currency_codes: [],
    q: "",
  });

  const load = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setNotice("");
      const res = await api.get("/api/pos/reports/products", {
        params: {
          date_from: nextFilters.date_from,
          date_to: nextFilters.date_to,
          currency_codes: nextFilters.currency_codes,
          per_page: 500,
        },
        headers: companyHeaders(),
      });
      setRows(normalizeRows(res.data));
    } catch (error) {
      setNotice(error?.response?.data?.message || error?.response?.data?.error || error?.message || "Failed to load product sales report.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    (async () => {
      try {
        const response = await getCurrencies({ per_page: 300, is_active: true });
        const payload = response?.data || response || {};
        const source = payload?.data?.data || payload?.data || payload?.currencies || payload;
        const rows = Array.isArray(source) ? source : [];
        const allCurrencyCodes = rows.map(currencyCodeOf).filter(Boolean);
        const nextFilters = { ...filters, currency_code: "", currency_codes: allCurrencyCodes };
        setCurrencies(rows);
        setFilters(nextFilters);
        await load(nextFilters);
      } catch {
        await load(filters);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const filteredRows = useMemo(() => {
    const q = String(filters.q || "").trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((row) =>
      String(row.product_name || "").toLowerCase().includes(q) ||
      String(row.product_code || "").toLowerCase().includes(q) ||
      String(row.sku || "").toLowerCase().includes(q) ||
      String(row.category_name || "").toLowerCase().includes(q)
    );
  }, [rows, filters.q]);

  const pivotedRows = useMemo(() => {
    const grouped = new Map();
    filteredRows.forEach((row, index) => {
      const key = String(row.product_id || row.product_name || index);
      const currency = currencyCodeOf(row);
      if (!grouped.has(key)) grouped.set(key, { first: row, rows: [], byCurrency: {} });
      const entry = grouped.get(key);
      entry.rows.push(row);
      if (currency) entry.byCurrency[currency] = row;
    });
    return [...grouped.values()];
  }, [filteredRows]);

  const totals = useMemo(() => {
    const useBaseValues = (filters.currency_codes || []).length > 1;
    return filteredRows.reduce(
      (acc, row) => {
        acc.qty += Number(row.quantity_sold || 0);
        acc.invoiced += Number(useBaseValues ? row.base_invoiced_value : (row.invoiced_value ?? row.sales_value) || 0);
        acc.cash += Number(useBaseValues ? row.base_cash_sale_value : row.cash_sale_value || 0);
        acc.creditIssued += Number(useBaseValues ? row.base_credit_issued_value : row.credit_issued_value || 0);
        acc.creditPaid += Number(useBaseValues ? row.base_credit_paid_value : row.credit_paid_value || 0);
        acc.creditOutstanding += Number(useBaseValues ? row.base_credit_outstanding_value : row.credit_outstanding_value || 0);
        acc.moneyReceived += Number(useBaseValues ? row.base_money_received_value : row.money_received_value || 0);
        acc.cost += Number(useBaseValues ? row.base_cost_value : row.cost_value || 0);
        return acc;
      },
      { qty: 0, invoiced: 0, cash: 0, creditIssued: 0, creditPaid: 0, creditOutstanding: 0, moneyReceived: 0, cost: 0 }
    );
  }, [filteredRows, filters.currency_codes]);

  const baseCurrencyCode = currencyCodeOf(currencies.find(isBaseCurrency)) || "USD";
  const summaryCurrencyCode = filters.currency_codes.length === 1
    ? filters.currency_codes[0]
    : baseCurrencyCode;

  const exportFile = (format) => {
    const params = new URLSearchParams({
      date_from: filters.date_from,
      date_to: filters.date_to,
    });
    filters.currency_codes.forEach((currencyCode) => params.append("currency_codes[]", currencyCode));
    window.open(`/api/pos/reports/products/export/${format}?${params.toString()}`, "_blank", "noopener,noreferrer");
  };

  if (!open) return null;

  return (
    <div className="h-full min-h-0 bg-[#EEF1F1] p-2 text-[#1C2C2B]">
      <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
        <div className="shrink-0 border-b border-[#AAB5B5] bg-[#F5F7FA]">
          <div className="flex h-11 items-center justify-between border-b border-[#CFD9D8] px-3">
            <div>
              <div className="text-[13px] font-semibold uppercase text-[#0C1B1B]">Product Sales / Credit Collection</div>
              <div className="text-[10px] font-semibold uppercase text-[#627474]">
                Invoiced goods, cash sales, credit issued, credit paid and credit outstanding
              </div>
            </div>
            <div className="flex gap-1">
              <button type="button" onClick={() => load()} disabled={loading} className="h-8 border border-[#AAB5B5] bg-white px-3 text-[10px] font-semibold uppercase">
                <RefreshCw className="mr-1 inline h-3.5 w-3.5" /> {loading ? "Loading" : "Refresh"}
              </button>
              <button type="button" onClick={() => exportFile("excel")} className="h-8 border border-[#069495] bg-[#069495] px-3 text-[10px] font-semibold uppercase text-white">
                <Download className="mr-1 inline h-3.5 w-3.5" /> Excel
              </button>
              <button type="button" onClick={() => exportFile("pdf")} className="h-8 border border-[#004141] bg-[#004142] px-3 text-[10px] font-semibold uppercase text-white">
                <Download className="mr-1 inline h-3.5 w-3.5" /> PDF
              </button>
              {onClose && <button type="button" onClick={onClose} className="h-8 border border-[#AAB5B5] bg-white px-3 text-[10px] font-semibold uppercase">Close</button>}
            </div>
          </div>

          {notice && <div className="border-b border-[#FEDF89] bg-[#FFFAEB] px-3 py-2 text-[11px] font-semibold text-[#B54708]">{notice}</div>}

          <div className="grid grid-cols-[140px_140px_minmax(260px,auto)_1fr] gap-2 p-2">
            <Field label="Date From" type="date" value={filters.date_from} onChange={(value) => setFilters((p) => ({ ...p, date_from: value }))} />
            <Field label="Date To" type="date" value={filters.date_to} onChange={(value) => setFilters((p) => ({ ...p, date_to: value }))} />
            <label>
              <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Currencies</div>
              <div className="flex min-h-8 flex-wrap items-center gap-x-3 gap-y-1 border border-[#AAB5B5] bg-white px-2 py-1">
                {currencies.map((row) => {
                  const currencyCode = currencyCodeOf(row);
                  const checked = filters.currency_codes.includes(currencyCode);
                  return <label key={row.id || currencyCode} className="flex cursor-pointer items-center gap-1 text-[10px] font-semibold">
                    <input type="checkbox" checked={checked} onChange={() => setFilters((previous) => {
                      const selected = new Set(previous.currency_codes);
                      if (selected.has(currencyCode)) selected.delete(currencyCode); else selected.add(currencyCode);
                      if (!selected.size) return previous;
                      return { ...previous, currency_code: "", currency_codes: currencies.map(currencyCodeOf).filter((code) => selected.has(code)) };
                    })} />
                    {currencyCode}{isBaseCurrency(row) ? " · Base" : ""}
                  </label>;
                })}
              </div>
            </label>
            <label>
              <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Search Product</div>
              <div className="relative">
                <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                <input value={filters.q} onChange={(e) => setFilters((p) => ({ ...p, q: e.target.value }))} className="h-8 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none focus:border-[#069495]" placeholder="Name, SKU, code, category..." />
              </div>
            </label>
          </div>

          <div className="grid grid-cols-7 gap-2 border-t border-[#CFD9D8] p-2">
            <Metric label="Qty Taken" value={qty(totals.qty)} />
            <Metric label="Invoiced Goods" value={money(totals.invoiced, summaryCurrencyCode)} />
            <Metric label="Cash Sales" value={money(totals.cash, summaryCurrencyCode)} good />
            <Metric label="Credit Issued" value={money(totals.creditIssued, summaryCurrencyCode)} warning />
            <Metric label="Credit Paid" value={money(totals.creditPaid, summaryCurrencyCode)} good />
            <Metric label="Credit Outstanding" value={money(totals.creditOutstanding, summaryCurrencyCode)} danger={totals.creditOutstanding > 0} />
            <Metric label="Money Received" value={money(totals.moneyReceived, summaryCurrencyCode)} good />
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-auto">
          <table className="w-full border-collapse text-left text-[11px]" style={{ minWidth: Math.max(1100, 360 + filters.currency_codes.length * 820) }}>
            <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
              <tr>
                <Th>Product</Th>
                <Th>Category</Th>
                <Th right>Receipts</Th>
                <Th right>Qty Taken</Th>
                {filters.currency_codes.flatMap((currencyCode) => [
                  <Th key={`${currencyCode}-invoiced`} right>{currencyCode} Invoiced</Th>,
                  <Th key={`${currencyCode}-cash`} right>{currencyCode} Cash</Th>,
                  <Th key={`${currencyCode}-credit-issued`} right>{currencyCode} Credit Issued</Th>,
                  <Th key={`${currencyCode}-credit-paid`} right>{currencyCode} Credit Paid</Th>,
                  <Th key={`${currencyCode}-outstanding`} right>{currencyCode} Outstanding</Th>,
                  <Th key={`${currencyCode}-received`} right>{currencyCode} Received</Th>,
                  <Th key={`${currencyCode}-cost`} right>{currencyCode} Cost</Th>,
                  <Th key={`${currencyCode}-profit`} right>{currencyCode} Profit</Th>,
                  <Th key={`${currencyCode}-collection`} right>{currencyCode} Collection %</Th>,
                ])}
              </tr>
            </thead>
            <tbody>
              {pivotedRows.length === 0 ? (
                <tr><td colSpan={4 + filters.currency_codes.length * 9} className="px-3 py-12 text-center text-[12px] font-semibold text-[#627474]">{loading ? "Loading..." : "No product sales found."}</td></tr>
              ) : pivotedRows.map((entry, index) => {
                const row = entry.first;
                return (
                  <tr key={`${row.product_id || row.product_name}-${index}`} className="border-b border-[#CFD9D8] hover:bg-[#F8FAFC]">
                    <Td strong><div>{row.product_name || "Unknown Product"}</div><div className="text-[9px] font-normal text-[#627474]">{[row.product_code, row.sku].filter(Boolean).join(" / ") || "-"}</div></Td>
                    <Td>{row.category_name || "-"}</Td>
                    <Td right>{entry.rows.reduce((sum, item) => sum + Number(item.receipt_count || 0), 0)}</Td>
                    <Td right>{qty(entry.rows.reduce((sum, item) => sum + Number(item.quantity_sold || 0), 0))}</Td>
                    {filters.currency_codes.flatMap((currencyCode) => {
                      const currencyRow = entry.byCurrency[currencyCode];
                      if (!currencyRow) return Array.from({ length: 9 }, (_, cellIndex) => <Td key={`${currencyCode}-empty-${cellIndex}`} right>-</Td>);
                      return [
                        <Td key={`${currencyCode}-invoiced`} right strong>{Number((currencyRow.invoiced_value ?? currencyRow.sales_value) || 0).toFixed(2)}</Td>,
                        <Td key={`${currencyCode}-cash`} right>{Number(currencyRow.cash_sale_value || 0).toFixed(2)}</Td>,
                        <Td key={`${currencyCode}-issued`} right warning>{Number(currencyRow.credit_issued_value || 0).toFixed(2)}</Td>,
                        <Td key={`${currencyCode}-paid`} right good>{Number(currencyRow.credit_paid_value || 0).toFixed(2)}</Td>,
                        <Td key={`${currencyCode}-outstanding`} right danger={Number(currencyRow.credit_outstanding_value || 0) > 0}>{Number(currencyRow.credit_outstanding_value || 0).toFixed(2)}</Td>,
                        <Td key={`${currencyCode}-received`} right strong good>{Number(currencyRow.money_received_value || 0).toFixed(2)}</Td>,
                        <Td key={`${currencyCode}-cost`} right>{Number(currencyRow.cost_value || 0).toFixed(2)}</Td>,
                        <Td key={`${currencyCode}-profit`} right>{Number(currencyRow.gross_profit || 0).toFixed(2)}</Td>,
                        <Td key={`${currencyCode}-collection`} right>{Number(currencyRow.collection_percent ?? 100).toFixed(2)}%</Td>,
                      ];
                    })}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function Field({ label, value, onChange, type = "text", placeholder = "" }) {
  return <label><div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div><input type={type} value={value || ""} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]" /></label>;
}

function Metric({ label, value, good = false, warning = false, danger = false }) {
  const color = danger ? "text-[#B42318]" : good ? "text-[#067647]" : warning ? "text-[#B54708]" : "text-[#0C1B1B]";
  return <div className="border border-[#CFD9D8] bg-white px-2 py-2"><div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div><div className={`mt-1 text-[14px] font-bold ${color}`}>{value}</div></div>;
}

function Th({ children, right = false }) {
  return <th className={`border-r border-white/10 px-2 py-2 text-[10px] font-semibold uppercase ${right ? "text-right" : "text-left"}`}>{children}</th>;
}

function Td({ children, right = false, strong = false, good = false, warning = false, danger = false }) {
  const color = danger ? "text-[#B42318]" : good ? "text-[#067647]" : warning ? "text-[#B54708]" : "text-[#324443]";
  return <td className={`border-r border-[#CFD9D8] px-2 py-2 align-top ${right ? "text-right" : "text-left"} ${strong ? "font-semibold" : ""} ${color}`}>{children}</td>;
}
