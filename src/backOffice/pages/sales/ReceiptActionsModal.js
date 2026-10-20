// src/backOffice/pages/sales/ReceiptActionsModal.jsx

import React, { useState } from "react";
import {
  cancelReceipt,
  findReceiptForAction,
  reprintReceipt,
} from "../../../services/ReceiptActionService";
import { qzPrintPdfUrl } from "../../../services/QzService";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";

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

const safeJson = (value) => {
  if (!value) return {};
  if (typeof value === "object") return value;

  try {
    return JSON.parse(value);
  } catch {
    return {};
  }
};

const getReceiptLines = (sale) => {
  if (!sale) return [];
  if (Array.isArray(sale.receipt_lines)) return sale.receipt_lines;
  if (Array.isArray(sale.receiptLines)) return sale.receiptLines;
  return [];
};

const getReceiptActionPayload = (responseOrPayload) => {
  if (!responseOrPayload) return {};

  if (responseOrPayload?.receipt_url || responseOrPayload?.print || responseOrPayload?.sale) {
    return responseOrPayload;
  }

  if (
    responseOrPayload?.data?.receipt_url ||
    responseOrPayload?.data?.print ||
    responseOrPayload?.data?.sale
  ) {
    return responseOrPayload.data;
  }

  if (
    responseOrPayload?.data?.data?.receipt_url ||
    responseOrPayload?.data?.data?.print ||
    responseOrPayload?.data?.data?.sale
  ) {
    return responseOrPayload.data.data;
  }

  return responseOrPayload?.data || responseOrPayload;
};

const ActionButton = ({ children, variant = "default", ...props }) => {
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
      <div className="absolute right-0 z-30 mt-1 min-w-[150px] border border-[#ACB8B8] bg-white py-1 text-left shadow-lg">
        {actions.map((action) => (
          <button
            key={action.label}
            type="button"
            disabled={action.disabled}
            onClick={(event) => {
              event.currentTarget.closest("details")?.removeAttribute("open");
              action.onClick();
            }}
            className={`block w-full px-3 py-2 text-left text-[11px] font-bold hover:bg-[#EFF4F4] disabled:cursor-not-allowed disabled:text-[#96A5A4] ${action.danger ? "text-[#B42318]" : "text-[#003636]"}`}
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

const Alert = ({ error, message }) => {
  if (!error && !message) return null;

  return (
    <div
      className={`border-b px-4 py-2 text-[12px] font-semibold ${
        error
          ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]"
          : "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
      }`}
    >
      {error || message}
    </div>
  );
};

const ReceiptDetailModal = ({
  sale,
  open,
  onClose,
  onReprint,
  onCancel,
  loading,
  cancelReason,
  setCancelReason,
  managerPin,
  setManagerPin,
}) => {
  if (!open || !sale) return null;

  const lines = getReceiptLines(sale);
  const metadata = safeJson(sale.metadata);

  return (
    <div className="fixed inset-0 z-[90] flex items-center justify-center bg-black/40 p-4">
      <div className="flex h-[92vh] w-full max-w-6xl flex-col border border-[#ACB8B8] bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b-2 border-[#00BCBC] bg-[#004142] px-4 py-3 text-white">
          <div>
            <div className="text-[16px] font-black uppercase tracking-wide">
              Receipt Details
            </div>
            <div className="text-[11px] font-semibold text-white/70">
              Main receipt information, line details, reprint and cancellation controls.
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
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Till</td>
                    <td className="px-3 py-2">
                      {metadata.till_name || sale.till?.till_name || sale.till_id || "-"}
                    </td>
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Customer</td>
                    <td className="px-3 py-2">{sale.customer_name || "Walk-in Customer"}</td>
                  </tr>
                  <tr className="border-b border-[#D7DFDF]">
                    <td className="bg-[#F6F8FA] px-3 py-2 font-semibold text-[#637877]">Currency</td>
                    <td className="px-3 py-2">{sale.currency_code}</td>
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

          <div className="mt-3 border border-[#D7DFDF] bg-white">
            <SectionTitle>Cancellation</SectionTitle>

            <div className="grid gap-3 p-3 md:grid-cols-[1fr_240px]">
              <Field label="Cancellation Reason">
                <textarea
                  value={cancelReason}
                  onChange={(event) => setCancelReason(event.target.value)}
                  rows={3}
                  placeholder="Reason is required before cancelling"
                  className="border border-[#ACB8B8] px-3 py-2 text-[12px] outline-none focus:border-[#069495]"
                />
              </Field>

              <Field label="Manager PIN / Approval Optional">
                <input
                  value={managerPin}
                  onChange={(event) => setManagerPin(event.target.value)}
                  placeholder="Optional"
                  className="h-9 border border-[#ACB8B8] px-3 text-[12px] outline-none focus:border-[#069495]"
                />
              </Field>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-[#D7DFDF] bg-white px-4 py-3">
          <ActionButton onClick={onReprint} disabled={loading} variant="soft">
            Reprint
          </ActionButton>

          <ActionButton
            onClick={onCancel}
            disabled={loading || sale.status !== "completed"}
            variant="danger"
          >
            Cancel Receipt
          </ActionButton>

          <ActionButton onClick={onClose}>
            Close
          </ActionButton>
        </div>
      </div>
    </div>
  );
};

export default function ReceiptActionsModal({ open = true, onClose }) {
  const [invoiceNumber, setInvoiceNumber] = useState("");
  const [searchRows, setSearchRows] = useState([]);
  const [selectedSale, setSelectedSale] = useState(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState("");
  const [managerPin, setManagerPin] = useState("");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const printReceipt = async (responseOrPayload) => {
    const payload = getReceiptActionPayload(responseOrPayload);

    const receiptUrl = payload?.receipt_url;
    const printerName = payload?.print?.printer?.printer_name;
    const autoPrint = payload?.print?.auto_print === true;
    const receiptNumber =
      payload?.receipt_number ||
      payload?.sale?.invoice_number ||
      payload?.cancel_transaction?.invoice_number ||
      "Receipt";

    if (!receiptUrl) {
      return {
        printed: false,
        skipped: true,
        message: "Receipt was processed, but no receipt URL was returned.",
      };
    }

    if (!autoPrint) {
      window.open(receiptUrl, "_blank", "noopener,noreferrer");

      return {
        printed: false,
        skipped: true,
        message: "Receipt opened. Auto print is off.",
      };
    }

    if (!printerName) {
      window.open(receiptUrl, "_blank", "noopener,noreferrer");

      return {
        printed: false,
        skipped: true,
        message: "Receipt opened. No active printer was returned.",
      };
    }

    try {
      await qzPrintPdfUrl({
        printerName,
        pdfUrl: receiptUrl,
        jobName: receiptNumber,
      });

      return {
        printed: true,
        skipped: false,
        message: `Printed on ${printerName}.`,
      };
    } catch (err) {
      console.error("Receipt print failed after successful server action:", err);
      window.open(receiptUrl, "_blank", "noopener,noreferrer");

      return {
        printed: false,
        skipped: false,
        message:
          err?.message ||
          "Receipt was processed, but QZ printing failed. The receipt PDF was opened instead.",
      };
    }
  };

  const searchReceipt = async () => {
    if (!invoiceNumber.trim()) {
      setError("Enter invoice number first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await findReceiptForAction(invoiceNumber.trim());
      const row = res?.data?.sale;

      setSearchRows(row ? [row] : []);
      setSelectedSale(null);
      setDetailOpen(false);
      setMessage(row ? "Receipt found." : "No receipt found.");
    } catch (err) {
      setSearchRows([]);
      setSelectedSale(null);
      setDetailOpen(false);
      setError(err?.response?.data?.message || "Receipt not found.");
    } finally {
      setLoading(false);
    }
  };

  const viewSale = (sale) => {
    setSelectedSale(sale);
    setCancelReason("");
    setManagerPin("");
    setDetailOpen(true);
  };

  const reprintSale = async (sale = selectedSale) => {
    if (!sale) {
      setError("Select a receipt first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await reprintReceipt({
        sale_id: sale.id,
        invoice_number: sale.invoice_number,
      });

      const printResult = await printReceipt(res);

      setMessage(
        printResult?.printed
          ? "Receipt reprinted successfully."
          : `Receipt reprint prepared successfully. ${printResult?.message || ""}`
      );
    } catch (err) {
      setError(err?.response?.data?.message || err?.response?.data?.error || "Reprint failed.");
    } finally {
      setLoading(false);
    }
  };

  const cancelSale = async (sale = selectedSale) => {
    if (!sale) {
      setError("Select a receipt first.");
      return;
    }

    if (!cancelReason.trim()) {
      setError("Cancellation reason is required.");
      return;
    }

    const confirmed = await requestEnterpriseConfirmation({
      title: "Cancel Receipt",
      message: `Cancel receipt ${sale.invoice_number}?`,
      confirmLabel: "Cancel receipt",
    });

    if (!confirmed) return;

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await cancelReceipt({
        sale_id: sale.id,
        invoice_number: sale.invoice_number,
        reason: cancelReason.trim(),
        manager_pin: managerPin || undefined,
        reprint_cancelled_receipt: true,
      });

      const printResult = await printReceipt(res);

      const payload = getReceiptActionPayload(res);
      const updatedOriginal = payload?.original_sale || {
        ...sale,
        status: "cancelled",
      };

      setSelectedSale(updatedOriginal);
      setSearchRows((rows) =>
        rows.map((row) => (row.id === updatedOriginal.id ? updatedOriginal : row))
      );
      setCancelReason("");
      setManagerPin("");
      setMessage(
        printResult?.printed
          ? "Receipt cancelled and cancellation receipt printed successfully."
          : `Receipt cancelled successfully. ${printResult?.message || ""}`
      );
    } catch (err) {
      setError(err?.response?.data?.message || err?.response?.data?.error || "Cancellation failed.");
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[80] bg-white">
      <div className="flex h-screen flex-col bg-[#F6F8FA] text-[#003636]">
        <div className="flex h-14 items-center justify-between border-b-2 border-[#00BCBC] bg-[#004142] px-4 text-white">
          <div>
            <div className="text-[16px] font-black uppercase tracking-wide">
              Receipt Controls
            </div>
            <div className="text-[11px] font-semibold text-white/70">
              Search sales transactions, view receipt lines, cancel receipts and reprint.
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

        <Alert error={error} message={message} />

        <div className="min-h-0 flex-1 overflow-auto p-4">
          <div className="mb-3 border border-[#D7DFDF] bg-white p-3">
            <div className="grid max-w-3xl gap-2 md:grid-cols-[1fr_auto]">
              <Field label="Search Invoice Number">
                <input
                  value={invoiceNumber}
                  onChange={(event) => setInvoiceNumber(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === "Enter") searchReceipt();
                  }}
                  placeholder="SAL-TILL001-260514-000001"
                  className="h-9 border border-[#ACB8B8] px-3 text-[13px] outline-none focus:border-[#069495]"
                />
              </Field>

              <div className="flex items-end">
                <ActionButton onClick={searchReceipt} disabled={loading} variant="primary">
                  {loading ? "Searching..." : "Search"}
                </ActionButton>
              </div>
            </div>
          </div>

          <div className="border border-[#D7DFDF] bg-white">
            <SectionTitle>Main Sales Transactions</SectionTitle>

            <div className="overflow-auto">
              <table className="min-w-[1200px] w-full border-collapse text-left text-[12px]">
                <thead className="bg-[#003637] text-white">
                  <tr>
                    <th className="px-3 py-2 font-semibold">Invoice</th>
                    <th className="px-3 py-2 font-semibold">Date</th>
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
                  {searchRows.map((sale) => (
                    <tr key={sale.id} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                      <td className="px-3 py-2 text-[#003636]">{sale.invoice_number}</td>
                      <td className="px-3 py-2">{dateTime(sale.transaction_date)}</td>
                      <td className="px-3 py-2">{sale.customer_name || "Walk-in Customer"}</td>
                      <td className="px-3 py-2">{sale.payment_method || "-"}</td>
                      <td className="px-3 py-2">{sale.currency_code}</td>
                      <td className="px-3 py-2 text-right">{money(sale.subtotal_amount, sale.currency_code)}</td>
                      <td className="px-3 py-2 text-right">{money(sale.tax_amount, sale.currency_code)}</td>
                      <td className="px-3 py-2 text-right text-[#003636]">{money(sale.total_amount, sale.currency_code)}</td>
                      <td className="px-3 py-2"><StatusPill value={sale.status} /></td>
                      <td className="px-3 py-2">
                        <RowActionMenu
                          actions={[
                            { label: "View", onClick: () => viewSale(sale) },
                            { label: "Reprint", onClick: () => reprintSale(sale), disabled: loading },
                            { label: "Cancel", onClick: () => viewSale(sale), disabled: sale.status !== "completed" || loading, danger: true },
                          ]}
                        />
                      </td>
                    </tr>
                  ))}

                  {searchRows.length === 0 && (
                    <tr>
                      <td colSpan={10} className="px-3 py-10 text-center text-[12px] text-[#637877]">
                        Search by invoice number to show the main sales transaction here.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <ReceiptDetailModal
          sale={selectedSale}
          open={detailOpen}
          onClose={() => setDetailOpen(false)}
          onReprint={() => reprintSale(selectedSale)}
          onCancel={() => cancelSale(selectedSale)}
          loading={loading}
          cancelReason={cancelReason}
          setCancelReason={setCancelReason}
          managerPin={managerPin}
          setManagerPin={setManagerPin}
        />
      </div>
    </div>
  );
}
