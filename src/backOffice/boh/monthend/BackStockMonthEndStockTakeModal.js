// src/backOffice/boh/monthend/BackStockMonthEndStockTakeModal.jsx

import React, { useEffect, useMemo, useRef, useState } from "react";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import {
  beginBackStockMonthEndStockTake,
  downloadBackStockMonthEndBalanceSnapshot,
  downloadBackStockMonthEndCountSheet,
  getBackStockMonthEndSnapshot,
  uploadBackStockMonthEndCountSheet,
} from "../../../services/HospitalityMonthEndService";

const today = () => new Date().toISOString().slice(0, 10);
const monthEndDate = () => {
  const now = new Date();
  if (now.getDate() === 1) {
    return new Date(now.getFullYear(), now.getMonth(), 0).toISOString().slice(0, 10);
  }
  return new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0, 10);
};

const normalizeMonthEndInput = (value) => {
  const picked = new Date(`${String(value || today()).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(picked.getTime())) return monthEndDate();
  if (picked.getDate() === 1) {
    return new Date(picked.getFullYear(), picked.getMonth(), 0).toISOString().slice(0, 10);
  }
  return new Date(picked.getFullYear(), picked.getMonth() + 1, 0).toISOString().slice(0, 10);
};

const formatNumber = (value, decimals = 2) => {
  const number = Number(value || 0);
  if (!Number.isFinite(number)) return "0";
  return number.toLocaleString("en-GB", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
};

const formatDate = (value) => {
  if (!value) return "-";
  const raw = String(value).slice(0, 10);
  const parsed = new Date(`${raw}T00:00:00`);
  if (Number.isNaN(parsed.getTime())) return raw;
  return parsed.toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
};

const extractRows = (payload) => {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.lines)) return payload.data.lines;
  if (Array.isArray(payload?.lines)) return payload.lines;
  if (Array.isArray(payload?.data?.products)) return payload.data.products;
  if (Array.isArray(payload?.products)) return payload.products;
  if (Array.isArray(payload?.data?.rows)) return payload.data.rows;
  if (Array.isArray(payload?.rows)) return payload.rows;
  return [];
};

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.data && typeof responseData.data === "object") {
    const dataMessage = responseData.data.message || responseData.data.enterprise_message;
    if (dataMessage) return dataMessage;
  }

  if (responseData?.message) return responseData.message;
  if (source?.message) return source.message;

  return fallback;
};

const BackStockMonthEndStockTakeModal = ({ open, onClose, currentUser = null }) => {
  const fileRef = useRef(null);
  const [monthEnd, setMonthEnd] = useState(monthEndDate());
  const [snapshot, setSnapshot] = useState(null);
  const [rows, setRows] = useState([]);
  const [selectedFile, setSelectedFile] = useState(null);
  const [notes, setNotes] = useState("");
  const [replaceExisting, setReplaceExisting] = useState(false);
  const [operationMode, setOperationMode] = useState("");
  const [loading, setLoading] = useState(false);
  const [downloading, setDownloading] = useState("");
  const [beginning, setBeginning] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const showMessage = ({ type = "success", title = "", message = "" }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const loadSnapshot = async () => {
    try {
      setLoading(true);
      const response = await getBackStockMonthEndSnapshot({ month_end_date: monthEnd });
      setSnapshot(response?.data || response || null);
      setRows(extractRows(response));
    } catch (error) {
      setSnapshot(null);
      setRows([]);
      showMessage({
        type: "error",
        title: "Snapshot Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    setMonthEnd(monthEndDate());
    setSelectedFile(null);
    setNotes("");
    setReplaceExisting(false);
    setOperationMode("");
    setSnapshot(null);
    setRows([]);
  }, [open]);

  useEffect(() => {
    if (!open || !monthEnd) return;
    loadSnapshot();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, monthEnd]);

  const summary = useMemo(() => {
    const source = snapshot?.summary || snapshot || {};
    const totalLines =
      source.total_lines ??
      source.line_count ??
      source.products_count ??
      rows.length;

    const expectedQty =
      source.expected_total_quantity ??
      rows.reduce((sum, row) => sum + Number(row.expected_quantity || row.quantity_on_hand || row.on_hand || 0), 0);

    const value =
      source.total_value ??
      source.expected_total_value ??
      rows.reduce((sum, row) => {
        const qty = Number(row.expected_quantity || row.quantity_on_hand || row.on_hand || 0);
        const cost = Number(row.unit_cost || row.average_cost || row.last_cost || 0);
        return sum + qty * cost;
      }, 0);

    return {
      totalLines,
      expectedQty,
      value,
      stockArea: source.stock_area || "back",
      monthEndDate: source.month_end_date || monthEnd,
    };
  }, [snapshot, rows, monthEnd]);

  const handleBegin = async () => {
    try {
      setBeginning(true);
      const response = await beginBackStockMonthEndStockTake({
        month_end_date: monthEnd,
        stock_area: "back",
        notes: notes || null,
        replace_existing: replaceExisting ? 1 : 0,
      });
      showMessage({
        type: "success",
        title: "Month End Count Started",
        message: response?.message || "Back stock month end count has been opened.",
      });
      setOperationMode("");
      await loadSnapshot();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Begin Failed",
        message: getApiMessage(error),
      });
    } finally {
      setBeginning(false);
    }
  };

  const handleDownloadBalance = async () => {
    try {
      setDownloading("balance");
      await downloadBackStockMonthEndBalanceSnapshot({ month_end_date: monthEnd });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Download Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDownloading("");
    }
  };

  const handleDownloadCountSheet = async () => {
    try {
      setDownloading("count-sheet");
      await downloadBackStockMonthEndCountSheet({ month_end_date: monthEnd });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Download Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDownloading("");
    }
  };

  const handleUpload = async () => {
    if (!selectedFile) {
      showMessage({
        type: "error",
        title: "File Required",
        message: "Select the completed back stock count sheet first.",
      });
      return;
    }

    try {
      setUploading(true);
      const response = await uploadBackStockMonthEndCountSheet(selectedFile, {
        month_end_date: monthEnd,
        stock_area: "back",
        notes: notes || "Month End BOH back stock count uploaded.",
        replace_existing: replaceExisting ? 1 : 0,
      });
      showMessage({
        type: "success",
        title: "Count Uploaded",
        message: response?.message || "Back stock month end count was uploaded successfully.",
      });
      setSelectedFile(null);
      if (fileRef.current) fileRef.current.value = "";
      setOperationMode("");
      await loadSnapshot();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Upload Failed",
        message: getApiMessage(error),
      });
    } finally {
      setUploading(false);
    }
  };

  const openOperation = (mode) => {
    setOperationMode(mode);
    setNotes("");
    setReplaceExisting(false);
    setSelectedFile(null);
    if (fileRef.current) fileRef.current.value = "";
  };

  const closeOperation = () => {
    if (uploading || beginning) return;
    setOperationMode("");
    setSelectedFile(null);
    if (fileRef.current) fileRef.current.value = "";
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="BACK STOCK MONTH-END STOCK TAKE"
        subtitle="Formal BOH physical count • Front Stock is counted separately in Daily Front Stock Closing"
      >
        <div className="me-shell relative flex h-full min-h-0 flex-col bg-white">
          {(uploading || beginning) && <OperationProgress />}
          <div className="me-topbar flex min-h-14 shrink-0 flex-wrap items-end justify-between gap-2 px-4 py-2">
            <div className="flex flex-wrap items-center gap-2">
              <button type="button" onClick={() => openOperation("begin")} disabled={beginning || uploading} className="me-btn-primary">
                Start Formal Count
              </button>
              <button type="button" onClick={loadSnapshot} disabled={loading} className="me-btn-secondary">
                {loading ? "Loading" : "Refresh Snapshot"}
              </button>
              <button type="button" onClick={handleDownloadBalance} disabled={!!downloading} className="me-btn-secondary">
                {downloading === "balance" ? "Downloading" : "Download Balance"}
              </button>
              <button type="button" onClick={handleDownloadCountSheet} disabled={!!downloading} className="me-btn-secondary">
                {downloading === "count-sheet" ? "Downloading" : "Download Count Sheet"}
              </button>
              <button type="button" onClick={() => openOperation("upload")} disabled={beginning || uploading} className="me-btn-primary">
                Upload Completed Count
              </button>
            </div>

            <div className="flex items-end gap-2">
              <label className="text-[11px] font-medium uppercase tracking-wide text-[#324444]">
                <span className="mb-1 block text-[9px]">Month-End Date</span>
                <input
                  type="date"
                  value={monthEnd}
                  onChange={(event) => setMonthEnd(normalizeMonthEndInput(event.target.value))}
                  className="me-input w-[150px]"
                />
              </label>
            </div>
          </div>

          <div className="border-b border-[#A4E5E4] bg-[#F1F8F8] px-4 py-2 text-[10px] font-bold uppercase tracking-[0.04em] text-[#057B7B]">
            Back Stock only: this is the formal month-end BOH count. Front Stock must be counted through Front Stock Daily Count &amp; Reconciliation.
          </div>

          <div className="min-h-0 flex-1 overflow-auto bg-[#F4F7F7] p-3">
              <div className="mb-3 grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
                <InfoTile label="Area" value={String(summary.stockArea || "back").toUpperCase()} />
                <InfoTile label="Date" value={formatDate(summary.monthEndDate)} />
                <InfoTile label="Items" value={formatNumber(summary.totalLines, 0)} />
                <InfoTile label="Loaded" value={formatNumber(rows.length, 0)} />
                <InfoTile label="Expected Qty" value={formatNumber(summary.expectedQty, 4)} />
                <InfoTile label="Expected Value" value={`${rows[0]?.currency_code || "USD"} ${formatNumber(summary.value, 2)}`} />
              </div>

              <div className="me-card overflow-auto bg-white">
                <table className="sx-enterprise-table min-w-[1280px]">
                  <thead><tr><th>Code</th><th>SKU</th><th>Product</th><th>Type</th><th>UOM</th><th className="text-right">Expected Qty</th><th className="text-right">Unit Cost</th><th>Currency</th><th className="text-right">Expected Value</th></tr></thead>
                  <tbody>
                    {loading ? (
                      <tr><td colSpan="9" className="px-3 py-12 text-center text-[12px] text-[#637877]">Loading back stock snapshot...</td></tr>
                    ) : rows.length === 0 ? (
                      <tr><td colSpan="9" className="px-3 py-12 text-center text-[12px] text-[#637877]">No back-stock items found.</td></tr>
                    ) : rows.slice(0, 600).map((row, index) => {
                      const expectedQuantity = Number(row.expected_quantity ?? row.quantity_on_hand ?? row.on_hand ?? 0);
                      const unitCost = Number(row.unit_cost ?? row.average_cost ?? row.last_cost ?? 0);
                      return (
                        <tr key={row.product_id || row.id || `${row.product_code || "row"}-${index}`}>
                          <td>{row.product_code || row.code || "-"}</td>
                          <td>{row.product_sku || row.sku || "-"}</td>
                          <td><strong>{row.product_name || row.name || "Product"}</strong></td>
                          <td>{String(row.product_type || "-").replaceAll("_", " ")}</td>
                          <td>{row.uom_code || "EACH"}</td>
                          <td className="text-right">{formatNumber(expectedQuantity, 4)}</td>
                          <td className="text-right">{formatNumber(unitCost, 4)}</td>
                          <td>{row.currency_code || "USD"}</td>
                          <td className="text-right">{formatNumber(row.expected_value ?? expectedQuantity * unitCost, 2)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
          </div>
        </div>

        <EnterpriseInlineStyles />
      </FullScreenModal>

      <EnterpriseFormModal
        open={Boolean(operationMode)}
        title={operationMode === "upload" ? "Upload Completed Back Stock Count" : "Start Back Stock Month-End Count"}
        subtitle={`BACK stock only • ${formatDate(monthEnd)}`}
        onClose={closeOperation}
        busy={uploading || beginning}
        width="680px"
      >
        <form onSubmit={(event) => { event.preventDefault(); if (operationMode === "upload") handleUpload(); else handleBegin(); }} className="space-y-3 p-4">
          <div className="grid grid-cols-2 gap-2 border border-[#A4E5E4] bg-[#F1F8F8] p-3 text-[11px]">
            <div><span className="block text-[9px] font-bold uppercase text-[#627474]">Stock Area</span><strong>Back Stock / BOH</strong></div>
            <div><span className="block text-[9px] font-bold uppercase text-[#627474]">Month-End Date</span><strong>{formatDate(monthEnd)}</strong></div>
          </div>

          {operationMode === "upload" && (
            <label className="block">
              <div className="mb-1 text-[10px] font-bold uppercase tracking-wide text-[#324444]">Completed Count Sheet</div>
              <input ref={fileRef} type="file" accept=".csv,.xlsx,.xls" onChange={(event) => setSelectedFile(event.target.files?.[0] || null)} className="block w-full border border-[#ACB8B8] bg-white p-2 text-[11px]" />
              <div className="mt-1 text-[9px] text-[#627474]">Upload the completed sheet downloaded from this Back Stock workflow.</div>
            </label>
          )}

          <label className="block">
            <div className="mb-1 text-[10px] font-bold uppercase tracking-wide text-[#324444]">Notes</div>
            <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={4} className="me-textarea" placeholder="Count instructions, supervisor notes, or upload comments" />
          </label>

          <label className="flex items-start gap-2 border border-[#FEDF89] bg-[#FFFAEB] p-3 text-[11px] font-medium text-[#7A2E0E]">
            <input type="checkbox" checked={replaceExisting} onChange={(event) => setReplaceExisting(event.target.checked)} className="mt-0.5 h-4 w-4" />
            <span><strong className="block uppercase">Replace an existing Back Stock count</strong>Use only when intentionally correcting the count already saved for this month-end date.</span>
          </label>

          <div className="flex justify-end gap-2 border-t border-[#DAE2E2] pt-3">
            <button type="button" onClick={closeOperation} disabled={uploading || beginning} className="me-btn-secondary">Cancel</button>
            <button type="submit" disabled={(operationMode === "upload" && !selectedFile) || uploading || beginning} className="me-btn-primary">
              {operationMode === "upload" ? (uploading ? "Uploading" : "Upload & Post Count") : (beginning ? "Starting" : "Start Back Stock Count")}
            </button>
          </div>
        </form>
      </EnterpriseFormModal>

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={closeMessageModal}
      />
    </>
  );
};

const OperationProgress = () => (
  <div className="me-progress-track" aria-hidden="true">
    <div className="me-progress-bar" />
  </div>
);

const InfoTile = ({ label, value }) => (
  <div className="me-tile">
    <div className="me-tile-label">{label}</div>
    <div className="me-tile-value" title={value}>{value}</div>
  </div>
);

const EnterpriseInlineStyles = () => (
  <style>
    {`
      .me-progress-track {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        z-index: 30;
        overflow: hidden;
        background: #E5EBEB;
      }

      .me-progress-bar {
        height: 100%;
        width: 38%;
        background: #7D9291;
        animation: me-progress-sweep 1.05s ease-in-out infinite;
      }

      @keyframes me-progress-sweep {
        0% { transform: translateX(-105%); }
        50% { transform: translateX(85%); }
        100% { transform: translateX(265%); }
      }

      .me-shell {
        border: 1px solid #D3DBDB;
        box-shadow: inset 0 3px 0 #D7DFDF;
      }

      .me-topbar {
        background: #F8FAFC;
        border-bottom: 1px solid #D7DFDF;
        color: #324444;
      }

      .me-side-head {
        background: #F8FAFC;
        border-bottom: 1px solid #D7DFDF;
      }

      .me-card {
        border: 1px solid #D3DBDB;
        box-shadow: 0 12px 28px rgba(9, 27, 27, 0.06);
      }

      .me-table-head {
        background: #F8FAFC;
        color: #637877;
        border-bottom: 1px solid #D7DFDF;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }

      .me-row {
        border-bottom: 1px solid #EFF2F2;
        color: #1E2B2B;
        font-size: 11px;
      }

      .me-row:hover {
        background: #F8FAFC;
      }

      .me-tile {
        background: #ffffff;
        border: 1px solid #D3DBDB;
        border-left: 3px solid #C2CCCC;
        padding: 12px;
        box-shadow: 0 10px 22px rgba(9, 27, 27, 0.045);
      }

      .me-tile-label {
        font-size: 9px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: #637877;
      }

      .me-tile-value {
        margin-top: 4px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 17px;
        font-weight: 500;
        color: #324444;
      }

      .me-input,
      .me-textarea {
        border: 1px solid #C2CCCC;
        background: #ffffff;
        font-size: 12px;
        outline: none;
      }

      .me-input {
        height: 34px;
        padding: 0 8px;
      }

      .me-textarea {
        width: 100%;
        padding: 8px;
        resize: vertical;
      }

      .me-input:focus,
      .me-textarea:focus {
        border-color: #93A6A6;
        box-shadow: 0 0 0 2px rgba(147, 166, 166, 0.18);
      }

      .me-btn-primary,
      .me-btn-secondary {
        min-height: 34px;
        padding: 0 14px;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.07em;
      }

      .me-btn-primary {
        border: 1px solid #AFBEBE;
        background: #EEF2F2;
        color: #324444;
      }

      .me-btn-secondary {
        border: 1px solid #C2CCCC;
        background: #ffffff;
        color: #324444;
      }

      .me-btn-primary:hover:not(:disabled),
      .me-btn-secondary:hover:not(:disabled) {
        background: #F8FAFC;
        border-color: #93A6A6;
      }

      .me-btn-primary:disabled,
      .me-btn-secondary:disabled {
        opacity: 0.55;
      }

      button,
      input,
      select,
      textarea {
        border-radius: 0 !important;
      }
    `}
  </style>
)


export default BackStockMonthEndStockTakeModal;
