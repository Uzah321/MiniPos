// src/backOffice/pages/sales/ReceiptRegisterModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import {
  getReceiptRegister,
  getReceiptRegisterSale,
  prepareReceiptPdf,
  fetchReceiptPdfFile,
} from "../../../services/ReceiptRegisterService";

const today = () => new Date().toISOString().slice(0, 10);

const money = (value, currency = "") => {
  const amount = Number(value || 0).toFixed(2);
  return currency ? `${currency} ${amount}` : amount;
};

const qty = (value) => Number(value || 0).toFixed(3);

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

const getPayload = (response) => {
  if (response?.data?.sale || response?.data?.receipt_url) return response.data;
  if (response?.data?.data?.sale || response?.data?.data?.receipt_url) return response.data.data;
  return response?.data || response || {};
};

const getAbsoluteUrl = (url) => {
  if (!url) return "";

  try {
    return new URL(url, window.location.origin).href;
  } catch {
    return url;
  }
};

const fetchPdfFile = async (sale, fileName = "receipt.pdf") => {
  if (!sale?.id) {
    throw new Error("Sale ID is required to fetch the receipt PDF.");
  }

  /*
   * Fetch the PDF from an authenticated API blob endpoint.
   * This avoids browser Network Error/CORS issues from fetching the public URL directly.
   */
  const blob = await fetchReceiptPdfFile(sale.id);

  const pdfBlob =
    blob?.type === "application/pdf"
      ? blob
      : new Blob([blob], { type: "application/pdf" });

  return new File([pdfBlob], fileName, {
    type: "application/pdf",
    lastModified: Date.now(),
  });
};

const downloadFile = (file) => {
  const blobUrl = URL.createObjectURL(file);
  const link = document.createElement("a");
  link.href = blobUrl;
  link.download = file.name;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
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

const receiptLines = (sale) => {
  if (!sale) return [];
  if (Array.isArray(sale.receipt_lines)) return sale.receipt_lines;
  if (Array.isArray(sale.receiptLines)) return sale.receiptLines;
  return [];
};

const Button = ({ children, variant = "default", ...props }) => {
  const styles = {
    default: "border-[#ACB8B8] bg-white text-[#003636] hover:bg-[#F6F8FA]",
    primary: "border-[#003636] bg-[#003637] text-white hover:bg-[#004444]",
    danger: "border-[#B42318] bg-[#B42318] text-white hover:bg-[#8F1D14]",
    soft: "border-[#069495] bg-[#E8F7F5] text-[#006869] hover:bg-[#D7F0EC]",
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

const RowActionMenu = ({ actions = [], disabled = false }) => (
  <details className="action-menu-wrap relative ml-auto w-fit">
    <summary
      aria-label="Open receipt actions"
      className={`flex h-8 w-8 cursor-pointer list-none items-center justify-center border text-xl font-black leading-none [&::-webkit-details-marker]:hidden ${disabled ? "cursor-not-allowed border-[#CFD6D6] bg-[#F2F4F7] text-[#96A5A4]" : "border-[#ACB8B8] bg-white text-[#003636] hover:bg-[#EFF4F4]"}`}
      onClick={(event) => {
        if (disabled) event.preventDefault();
      }}
    >
      <span aria-hidden="true">⋮</span>
    </summary>
    {!disabled && (
      <div className="absolute right-0 z-30 mt-1 min-w-[160px] border border-[#ACB8B8] bg-white py-1 text-left shadow-lg">
        {actions.map((action) => (
          <button
            key={action.label}
            type="button"
            disabled={action.disabled}
            onClick={(event) => {
              event.currentTarget.closest("details")?.removeAttribute("open");
              action.onClick();
            }}
            className="block w-full px-3 py-2 text-left text-[11px] font-bold text-[#003636] hover:bg-[#EFF4F4] disabled:cursor-not-allowed disabled:text-[#96A5A4]"
          >
            {action.label}
          </button>
        ))}
      </div>
    )}
  </details>
);

const Field = ({ label, children }) => (
  <label className="flex flex-col gap-1">
    <span className="text-[11px] font-semibold uppercase tracking-wide text-[#637877]">
      {label}
    </span>
    {children}
  </label>
);

const SectionTitle = ({ children }) => (
  <div className="border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2 text-[12px] font-bold uppercase tracking-wide text-[#003636]">
    {children}
  </div>
);

const StatusPill = ({ value }) => {
  const text = value || "-";
  const danger = text === "cancelled" || text === "voided";
  const ok = text === "completed";

  return (
    <span
      className={`inline-flex min-w-[82px] justify-center border px-2 py-1 text-[11px] font-semibold ${
        danger
          ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]"
          : ok
          ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
          : "border-[#D7DFDF] bg-white text-[#637877]"
      }`}
    >
      {text}
    </span>
  );
};

const ReceiptDetailModal = ({ open, sale, onClose, onDownload, onShare, loading }) => {
  if (!open || !sale) return null;

  const lines = receiptLines(sale);
  const metadata = safeJson(sale.metadata);

  return (
    <div className="fixed inset-0 z-[90] flex items-center justify-center bg-black/40 p-4">
      <div className="flex h-[92vh] w-full max-w-6xl flex-col border border-[#ACB8B8] bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b-2 border-[#00BCBC] bg-[#004142] px-4 py-3 text-white">
          <div>
            <div className="text-[16px] font-black uppercase tracking-wide">Receipt View</div>
            <div className="text-[11px] font-semibold text-white/70">
              Main receipt information and receipt line details.
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

        <div className="min-h-0 flex-1 overflow-auto bg-[#F6F8FA] p-4">
          <div className="border border-[#D7DFDF] bg-white">
            <SectionTitle>Main Receipt Information</SectionTitle>

            <div className="overflow-auto">
              <table className="min-w-[1000px] w-full border-collapse text-left text-[12px]">
                <tbody>
                  <tr className="border-b border-[#D7DFDF]">
                    <td className="w-[180px] bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Invoice Number</td>
                    <td className="px-3 py-2 font-semibold text-[#003636]">{sale.invoice_number}</td>
                    <td className="w-[180px] bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Status</td>
                    <td className="px-3 py-2"><StatusPill value={sale.status} /></td>
                  </tr>
                  <tr className="border-b border-[#D7DFDF]">
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Date</td>
                    <td className="px-3 py-2">{dateTime(sale.transaction_date)}</td>
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Cashier</td>
                    <td className="px-3 py-2">
                      {metadata.cashier_name || sale.cashier_name || sale.cashier?.name || sale.cashier_id || "-"}
                    </td>
                  </tr>
                  <tr className="border-b border-[#D7DFDF]">
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Branch</td>
                    <td className="px-3 py-2">{sale.branch?.branch_name || sale.branch?.branch_code || sale.branch_id || "-"}</td>
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Till</td>
                    <td className="px-3 py-2">{metadata.till_name || sale.till?.till_name || sale.till?.till_code || sale.till_id || "-"}</td>
                  </tr>
                  <tr className="border-b border-[#D7DFDF]">
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Customer</td>
                    <td className="px-3 py-2">{sale.customer_name || "Walk-in Customer"}</td>
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Payment</td>
                    <td className="px-3 py-2">{sale.payment_method || "-"}</td>
                  </tr>
                  <tr>
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Subtotal</td>
                    <td className="px-3 py-2">{money(sale.subtotal_amount, sale.currency_code)}</td>
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Total</td>
                    <td className="px-3 py-2 font-semibold text-[#003636]">{money(sale.total_amount, sale.currency_code)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div className="mt-3 border border-[#D7DFDF] bg-white">
            <SectionTitle>Receipt Lines</SectionTitle>

            <div className="overflow-auto">
              <table className="min-w-[1000px] w-full border-collapse text-left text-[12px]">
                <thead className="bg-[#003637] text-white">
                  <tr>
                    <th className="px-3 py-2 font-semibold">#</th>
                    <th className="px-3 py-2 font-semibold">Item</th>
                    <th className="px-3 py-2 font-semibold">Code</th>
                    <th className="px-3 py-2 font-semibold">Barcode</th>
                    <th className="px-3 py-2 text-right font-semibold">Qty</th>
                    <th className="px-3 py-2 text-right font-semibold">Price</th>
                    <th className="px-3 py-2 text-right font-semibold">Discount</th>
                    <th className="px-3 py-2 text-right font-semibold">Tax</th>
                    <th className="px-3 py-2 text-right font-semibold">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line, index) => (
                    <tr key={line.id || index} className="border-b border-[#D7DFDF]">
                      <td className="px-3 py-2">{line.line_no || index + 1}</td>
                      <td className="px-3 py-2 text-[#003636]">{line.line_name}</td>
                      <td className="px-3 py-2">{line.product_code || line.sku || "-"}</td>
                      <td className="px-3 py-2">{line.barcode || "-"}</td>
                      <td className="px-3 py-2 text-right">{qty(line.line_quantity)}</td>
                      <td className="px-3 py-2 text-right">{money(line.line_price, line.currency_code || sale.currency_code)}</td>
                      <td className="px-3 py-2 text-right">{money(line.line_discount, line.currency_code || sale.currency_code)}</td>
                      <td className="px-3 py-2 text-right">{money(line.line_tax, line.currency_code || sale.currency_code)}</td>
                      <td className="px-3 py-2 text-right text-[#003636]">{money(line.line_total, line.currency_code || sale.currency_code)}</td>
                    </tr>
                  ))}

                  {lines.length === 0 && (
                    <tr>
                      <td colSpan={9} className="px-3 py-8 text-center text-[#637877]">
                        No receipt lines found.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-[#D7DFDF] bg-white px-4 py-3">
          <Button onClick={() => onShare(sale)} disabled={loading} variant="soft">
            Share PDF
          </Button>
          <Button onClick={() => onDownload(sale)} disabled={loading} variant="primary">
            Download PDF
          </Button>
          <Button onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
};

export default function ReceiptRegisterModal({ open = true, onClose }) {
  const [filters, setFilters] = useState({
    date_from: today(),
    date_to: today(),
    q: "",
    status: "",
    page: 1,
    per_page: 25,
  });
  const [response, setResponse] = useState(null);
  const [selectedSale, setSelectedSale] = useState(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [pdfLoading, setPdfLoading] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const rows = useMemo(() => rowsFrom(response), [response]);
  const meta = useMemo(() => metaFrom(response), [response]);

  const load = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await getReceiptRegister(nextFilters);
      setResponse(res);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load receipts.");
    } finally {
      setLoading(false);
    }
  };

  const viewSale = async (sale) => {
    try {
      setPdfLoading(true);
      setError("");
      const res = await getReceiptRegisterSale(sale.id);
      const payload = getPayload(res);
      setSelectedSale(payload.sale || sale);
      setDetailOpen(true);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to open receipt.");
    } finally {
      setPdfLoading(false);
    }
  };

  const getPdfPayload = async (sale) => {
    const res = await prepareReceiptPdf(sale.id);
    const payload = getPayload(res);

    if (!payload?.receipt_url) {
      throw new Error("Receipt PDF URL was not returned.");
    }

    return payload;
  };

  const downloadPdf = async (sale) => {
    try {
      setPdfLoading(true);
      setError("");
      setMessage("");

      await getPdfPayload(sale);
      const file = await fetchPdfFile(
        sale,
        `${sale.invoice_number || "receipt"}.pdf`
      );

      downloadFile(file);
      setMessage("Receipt PDF downloaded.");
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          err?.message ||
          "Failed to fetch/download the receipt PDF. Check that the receipt file exists and the API asset route allows authenticated access."
      );
    } finally {
      setPdfLoading(false);
    }
  };

  const sharePdf = async (sale) => {
    try {
      setPdfLoading(true);
      setError("");
      setMessage("");

      await getPdfPayload(sale);
      const file = await fetchPdfFile(
        sale,
        `${sale.invoice_number || "receipt"}.pdf`
      );

      const shareData = {
        title: `Receipt ${sale.invoice_number}`,
        text: `Receipt ${sale.invoice_number} - ${money(
          sale.total_amount,
          sale.currency_code
        )}`,
        files: [file],
      };

      if (navigator.canShare && navigator.canShare({ files: [file] }) && navigator.share) {
        await navigator.share(shareData);
        setMessage("Receipt PDF shared as an attachment.");
        return;
      }

      downloadFile(file);
      setMessage(
        "This browser cannot attach PDFs directly to WhatsApp/share. The PDF was downloaded so you can attach it manually."
      );
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          err?.message ||
          "Failed to fetch/share the receipt PDF. Check that the receipt file exists and the API asset route allows authenticated access."
      );
    } finally {
      setPdfLoading(false);
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
            <div className="text-[16px] font-black uppercase tracking-wide">Receipt Register</div>
            <div className="text-[11px] font-semibold text-white/70">
              Receipts for the selected date with view, PDF sharing and PDF download actions.
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

        {(error || message) && (
          <div
            className={`border-b px-4 py-2 text-[12px] font-semibold ${
              error
                ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]"
                : "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
            }`}
          >
            {error || message}
          </div>
        )}

        <div className="min-h-0 flex-1 overflow-auto p-4">
          <div className="mb-3 grid gap-2 border border-[#D7DFDF] bg-white p-3 md:grid-cols-[160px_160px_1fr_140px_auto]">
            <Field label="Date From">
              <input
                type="date"
                value={filters.date_from}
                onChange={(event) =>
                  setFilters({ ...filters, date_from: event.target.value, page: 1 })
                }
                className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
              />
            </Field>

            <Field label="Date To">
              <input
                type="date"
                value={filters.date_to}
                onChange={(event) =>
                  setFilters({ ...filters, date_to: event.target.value, page: 1 })
                }
                className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
              />
            </Field>

            <Field label="Search">
              <input
                value={filters.q}
                onChange={(event) => setFilters({ ...filters, q: event.target.value, page: 1 })}
                placeholder="Invoice, customer, payment"
                className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
              />
            </Field>

            <Field label="Status">
              <select
                value={filters.status}
                onChange={(event) => setFilters({ ...filters, status: event.target.value, page: 1 })}
                className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
              >
                <option value="">All</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
                <option value="voided">Voided</option>
              </select>
            </Field>

            <div className="flex items-end">
              <Button onClick={() => load({ ...filters, page: 1 })} disabled={loading} variant="primary">
                {loading ? "Loading..." : "Apply"}
              </Button>
            </div>
          </div>

          <div className="border border-[#D7DFDF] bg-white">
            <SectionTitle>Receipt List</SectionTitle>

            <div className="overflow-auto">
              <table className="min-w-[1380px] w-full border-collapse text-left text-[12px]">
                <thead className="bg-[#003637] text-white">
                  <tr>
                    <th className="px-3 py-2 font-semibold">Invoice</th>
                    <th className="px-3 py-2 font-semibold">Date</th>
                    <th className="px-3 py-2 font-semibold">Branch</th>
                    <th className="px-3 py-2 font-semibold">Till</th>
                    <th className="px-3 py-2 font-semibold">Customer</th>
                    <th className="px-3 py-2 font-semibold">Payment</th>
                    <th className="px-3 py-2 font-semibold">Currency</th>
                    <th className="px-3 py-2 text-right font-semibold">Subtotal</th>
                    <th className="px-3 py-2 text-right font-semibold">Tax</th>
                    <th className="px-3 py-2 text-right font-semibold">Total</th>
                    <th className="px-3 py-2 font-semibold">Status</th>
                    <th className="px-3 py-2 text-right font-semibold">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((sale) => (
                    <tr key={sale.id} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                      <td className="px-3 py-2 text-[#003636]">{sale.invoice_number}</td>
                      <td className="px-3 py-2">{dateTime(sale.transaction_date)}</td>
                      <td className="px-3 py-2">{sale.branch?.branch_name || sale.branch?.branch_code || sale.branch_id || "-"}</td>
                      <td className="px-3 py-2">{sale.till?.till_name || sale.till?.till_code || sale.till_id || "-"}</td>
                      <td className="px-3 py-2">{sale.customer_name || "Walk-in Customer"}</td>
                      <td className="px-3 py-2">{sale.payment_method || "-"}</td>
                      <td className="px-3 py-2">{sale.currency_code}</td>
                      <td className="px-3 py-2 text-right">{money(sale.subtotal_amount, sale.currency_code)}</td>
                      <td className="px-3 py-2 text-right">{money(sale.tax_amount, sale.currency_code)}</td>
                      <td className="px-3 py-2 text-right text-[#003636]">{money(sale.total_amount, sale.currency_code)}</td>
                      <td className="px-3 py-2"><StatusPill value={sale.status} /></td>
                      <td className="px-3 py-2">
                        <RowActionMenu
                          disabled={pdfLoading}
                          actions={[
                            { label: "View", onClick: () => viewSale(sale) },
                            { label: "Share PDF", onClick: () => sharePdf(sale) },
                            { label: "Download PDF", onClick: () => downloadPdf(sale) },
                          ]}
                        />
                      </td>
                    </tr>
                  ))}

                  {rows.length === 0 && (
                    <tr>
                      <td colSpan={12} className="px-3 py-10 text-center text-[#637877]">
                        No receipts found for the selected date.
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

        <ReceiptDetailModal
          open={detailOpen}
          sale={selectedSale}
          onClose={() => setDetailOpen(false)}
          onDownload={downloadPdf}
          onShare={sharePdf}
          loading={pdfLoading}
        />
      </div>
    </div>
  );
}
