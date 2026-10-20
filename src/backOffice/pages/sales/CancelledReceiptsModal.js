// src/backOffice/pages/sales/CancelledReceiptsModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import { getCancelledReceipts } from "../../../services/ReceiptActionService";

const today = () => new Date().toISOString().slice(0, 10);

const money = (value, currency = "") => {
  const amount = Number(value || 0).toFixed(2);
  return currency ? `${currency} ${amount}` : amount;
};

const dateTime = (value) => {
  if (!value) return "-";

  try {
    return new Intl.DateTimeFormat("en-ZW", {
      year: "numeric",
      month: "short",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(value));
  } catch {
    return value;
  }
};

const safeJson = (value) => {
  if (!value) return {};
  if (typeof value === "object") return value;

  try {
    return JSON.parse(value);
  } catch {
    return {};
  }
};

const rowsFrom = (response) => {
  if (Array.isArray(response?.data?.data)) return response.data.data;
  if (Array.isArray(response?.data)) return response.data;
  return [];
};

const metaFrom = (response) => {
  const data = response?.data || {};

  return {
    current_page: Number(data.current_page || 1),
    last_page: Number(data.last_page || 1),
    total: Number(data.total || 0),
  };
};

const Button = ({ children, variant = "default", ...props }) => {
  const styles = {
    default: "border-[#ACB8B8] bg-white text-[#003636] hover:bg-[#F6F8FA]",
    primary: "border-[#003636] bg-[#003637] text-white hover:bg-[#004444]",
  };

  return (
    <button
      type="button"
      {...props}
      className={`h-8 border px-3 text-[12px] font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${styles[variant]}`}
    >
      {children}
    </button>
  );
};

const Field = ({ label, children }) => (
  <label className="flex flex-col gap-1">
    <span className="text-[11px] font-semibold uppercase tracking-wide text-[#637877]">
      {label}
    </span>
    {children}
  </label>
);

export default function CancelledReceiptsModal({ open = true, onClose }) {
  const [filters, setFilters] = useState({
    date_from: today(),
    date_to: today(),
    q: "",
    page: 1,
    per_page: 25,
  });
  const [response, setResponse] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const rows = useMemo(() => rowsFrom(response), [response]);
  const meta = useMemo(() => metaFrom(response), [response]);

  const load = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setError("");

      const res = await getCancelledReceipts(nextFilters);
      setResponse(res);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load cancelled receipts.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      load(filters);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[80] bg-white">
      <div className="flex h-screen flex-col bg-[#F6F8FA] text-[#003636]">
        <div className="flex h-14 items-center justify-between border-b-2 border-[#00BCBC] bg-[#004142] px-4 text-white">
          <div>
            <div className="text-[16px] font-black uppercase tracking-wide">
              Cancelled Receipts
            </div>
            <div className="text-[11px] font-semibold text-white/70">
              Review cancellation receipts, original receipts, reasons and cancellation users.
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="h-8 border border-white/30 px-3 text-[12px] font-bold hover:bg-white/10"
          >
            Close
          </button>
        </div>

        {error && (
          <div className="border-b border-[#FDA29B] bg-[#FEF3F2] px-4 py-2 text-[12px] font-semibold text-[#B42318]">
            {error}
          </div>
        )}

        <div className="min-h-0 flex-1 overflow-auto p-4">
          <div className="mb-3 grid gap-2 border border-[#D7DFDF] bg-white p-3 md:grid-cols-[160px_160px_1fr_auto]">
            <Field label="Date From">
              <input
                type="date"
                value={filters.date_from}
                onChange={(event) => setFilters({ ...filters, date_from: event.target.value, page: 1 })}
                className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
              />
            </Field>

            <Field label="Date To">
              <input
                type="date"
                value={filters.date_to}
                onChange={(event) => setFilters({ ...filters, date_to: event.target.value, page: 1 })}
                className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
              />
            </Field>

            <Field label="Search">
              <input
                value={filters.q}
                onChange={(event) => setFilters({ ...filters, q: event.target.value, page: 1 })}
                placeholder="Invoice, customer, reason"
                className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
              />
            </Field>

            <div className="flex items-end">
              <Button onClick={() => load({ ...filters, page: 1 })} disabled={loading} variant="primary">
                {loading ? "Loading..." : "Apply"}
              </Button>
            </div>
          </div>

          <div className="border border-[#D7DFDF] bg-white">
            <div className="border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2 text-[12px] font-bold uppercase tracking-wide">
              Cancelled Receipt List
            </div>

            <div className="overflow-auto">
              <table className="min-w-[1280px] w-full border-collapse text-left text-[12px]">
                <thead className="bg-[#003637] text-white">
                  <tr>
                    <th className="px-3 py-2 font-semibold">Cancel Receipt</th>
                    <th className="px-3 py-2 font-semibold">Original Receipt</th>
                    <th className="px-3 py-2 font-semibold">Date</th>
                    <th className="px-3 py-2 font-semibold">Branch</th>
                    <th className="px-3 py-2 font-semibold">Customer</th>
                    <th className="px-3 py-2 text-right font-semibold">Amount</th>
                    <th className="px-3 py-2 font-semibold">Reason</th>
                    <th className="px-3 py-2 font-semibold">Cancelled By</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => {
                    const metadata = safeJson(row.metadata);

                    return (
                      <tr key={row.id} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                        <td className="px-3 py-2 text-[#003636]">{row.invoice_number}</td>
                        <td className="px-3 py-2">{metadata.cancelled_original_invoice_number || "-"}</td>
                        <td className="px-3 py-2">{dateTime(row.transaction_date)}</td>
                        <td className="px-3 py-2">{row.branch?.branch_name || row.branch?.branch_code || row.branch_id || "-"}</td>
                        <td className="px-3 py-2">{row.customer_name || "-"}</td>
                        <td className="px-3 py-2 text-right">{money(row.total_amount, row.currency_code)}</td>
                        <td className="px-3 py-2">{metadata.cancel_reason || row.notes || "-"}</td>
                        <td className="px-3 py-2">{metadata.cancelled_by_name || "-"}</td>
                      </tr>
                    );
                  })}

                  {rows.length === 0 && (
                    <tr>
                      <td colSpan={8} className="px-3 py-10 text-center text-[#637877]">
                        No cancelled receipts found.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          <div className="mt-3 flex items-center justify-between text-[12px] font-semibold text-[#637877]">
            <span>
              Page {meta.current_page} of {meta.last_page} · {meta.total} records
            </span>

            <div className="flex gap-2">
              <Button
                disabled={meta.current_page <= 1 || loading}
                onClick={() => {
                  const next = { ...filters, page: meta.current_page - 1 };
                  setFilters(next);
                  load(next);
                }}
              >
                Previous
              </Button>

              <Button
                disabled={meta.current_page >= meta.last_page || loading}
                onClick={() => {
                  const next = { ...filters, page: meta.current_page + 1 };
                  setFilters(next);
                  load(next);
                }}
              >
                Next
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
