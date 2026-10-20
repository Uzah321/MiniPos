// src/backOffice/pages/report/StockAdjustmentHistoryModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import { Download } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, {
  useHospitalityReportLookups,
} from "../../../components/HospitalityReportScopePanel";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import InventoryReportNavigator, {
  LEGACY_STOCK_ADJUSTMENT_REPORT_KEY,
} from "../../../components/InventoryReportNavigator";
import {
  downloadStockAdjustmentHistory,
  getStockAdjustmentHistory,
} from "../../../services/ProductService";

const StockAdjustmentHistoryModal = ({
  open,
  onClose,
  currentBranchId = "",
  currentBranchName = "",
  businessModeLabel = "Hospitality",
  onNavigateReport,
}) => {
  const [history, setHistory] = useState([]);
  const [pagination, setPagination] = useState(null);

  const [search, setSearch] = useState("");
  const [adjustmentType, setAdjustmentType] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [branchFilter, setBranchFilter] = useState(null);
  const [perPage, setPerPage] = useState(500);
  const [page, setPage] = useState(1);

  const [loading, setLoading] = useState(false);
  const [generated, setGenerated] = useState(false);
  const [appliedConfig, setAppliedConfig] = useState(null);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserMode, setChooserMode] = useState("generate");
  const [exporting, setExporting] = useState(false);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const showMessage = ({ type = "success", title = "", message = "" }) => {
    setMessageModal({
      open: true,
      type,
      title,
      message,
    });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({
      ...prev,
      open: false,
    }));
  };

  const getApiMessage = (source, fallback = "Request failed.") => {
    const responseData = source?.response?.data;

    if (responseData?.errors && typeof responseData.errors === "object") {
      return Object.values(responseData.errors).flat().join("\n");
    }

    if (responseData?.error) return responseData.error;
    if (responseData?.message) return responseData.message;

    if (source?.data?.error) return source.data.error;
    if (source?.data?.message) return source.data.message;

    if (source?.message && !source?.isAxiosError) return source.message;

    return fallback;
  };

  const resolvedBranchId = useMemo(() => {
    if (currentBranchId) return currentBranchId;

    try {
      const possibleKeys = [
        "back_user",
        "user",
        "authUser",
        "adminUser",
        "pos_user",
        "salexa_user",
        "currentUser",
      ];

      for (const key of possibleKeys) {
        const raw = localStorage.getItem(key);
        if (!raw) continue;

        const parsed = JSON.parse(raw);

        if (parsed?.branch_id) return parsed.branch_id;
        if (parsed?.branch?.id) return parsed.branch.id;
        if (parsed?.data?.branch_id) return parsed.data.branch_id;
        if (parsed?.data?.branch?.id) return parsed.data.branch.id;
      }
    } catch {
      // ignore
    }

    return "";
  }, [currentBranchId]);

  const resolvedBranchName = useMemo(() => {
    if (currentBranchName) return currentBranchName;

    try {
      const possibleKeys = [
        "back_user",
        "user",
        "authUser",
        "adminUser",
        "pos_user",
        "salexa_user",
        "currentUser",
      ];

      for (const key of possibleKeys) {
        const raw = localStorage.getItem(key);
        if (!raw) continue;

        const parsed = JSON.parse(raw);

        if (parsed?.branch?.branch_name) return parsed.branch.branch_name;
        if (parsed?.branch?.name) return parsed.branch.name;
        if (parsed?.data?.branch?.branch_name) {
          return parsed.data.branch.branch_name;
        }
        if (parsed?.data?.branch?.name) return parsed.data.branch.name;
      }
    } catch {
      // ignore
    }

    return "Current Branch";
  }, [currentBranchName]);

  const effectiveBranchId = branchFilter === null ? resolvedBranchId : branchFilter;
  const scopeFilters = useMemo(() => ({
    branch_id: effectiveBranchId || "",
    date_from: dateFrom,
    date_to: dateTo,
    till_id: "",
    cashier_id: "",
    shift_session_id: "",
    currency_codes: [],
  }), [dateFrom, dateTo, effectiveBranchId]);
  const scopeLookups = useHospitalityReportLookups(open, scopeFilters);
  const selectedBranchName = useMemo(() => {
    if (!effectiveBranchId) return "All Branches";
    const branch = scopeLookups.branchById?.[effectiveBranchId];
    return branch?.branch_name || branch?.name || branch?.code || resolvedBranchName || "Current Branch";
  }, [effectiveBranchId, resolvedBranchName, scopeLookups.branchById]);

  const setReportScope = (next) => {
    clearResults();
    setBranchFilter(next.branch_id ?? effectiveBranchId ?? "");
    setDateFrom(next.date_from ?? dateFrom);
    setDateTo(next.date_to ?? dateTo);
  };

  const normalizeHistory = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) {
      return {
        list: payload,
        pagination: null,
      };
    }

    if (Array.isArray(payload?.data)) {
      return {
        list: payload.data,
        pagination: {
          current_page: payload.current_page,
          last_page: payload.last_page,
          per_page: payload.per_page,
          total: payload.total,
          from: payload.from,
          to: payload.to,
        },
      };
    }

    return {
      list: [],
      pagination: null,
    };
  };

  const clearResults = () => {
    setHistory([]);
    setPagination(null);
    setAppliedConfig(null);
    setGenerated(false);
  };

  const draftConfig = () => ({
    branchId: effectiveBranchId || "",
    search: search.trim(),
    adjustmentType,
    dateFrom,
    dateTo,
    perPage,
  });

  const paramsForConfig = (snapshot, nextPage = null) => {
    const params = {};
    if (nextPage !== null) {
      params.page = nextPage;
      params.per_page = snapshot.perPage;
    }
    if (snapshot.branchId) params.branch_id = snapshot.branchId;
    if (snapshot.search) params.q = snapshot.search;
    if (snapshot.adjustmentType) params.adjustment_type = snapshot.adjustmentType;
    if (snapshot.dateFrom) params.date_from = snapshot.dateFrom;
    if (snapshot.dateTo) params.date_to = snapshot.dateTo;
    return params;
  };

  const loadHistory = async (nextPage = page, requestedConfig = null) => {
    const snapshot = { ...(requestedConfig || draftConfig()) };
    try {
      setLoading(true);

      const params = paramsForConfig(snapshot, nextPage);

      const response = await getStockAdjustmentHistory(params);
      const normalized = normalizeHistory(response);

      setHistory(normalized.list);
      setPagination(normalized.pagination);
      setPage(nextPage);
      setAppliedConfig(snapshot);
      setGenerated(true);
      setChooserOpen(false);
      return true;
    } catch (error) {
      clearResults();
      setChooserOpen(false);
      showMessage({
        type: "error",
        title: "History Failed",
        message: getApiMessage(error),
      });
      return false;
    } finally {
      setLoading(false);
    }
  };

  const downloadHistory = async (format) => {
    const snapshot = appliedConfig || draftConfig();
    try {
      setExporting(true);
      await downloadStockAdjustmentHistory(format, paramsForConfig(snapshot));
      setChooserOpen(false);
    } catch (error) {
      setChooserOpen(false);
      showMessage({
        type: "error",
        title: "Export Failed",
        message: getApiMessage(error, "The backend could not generate this history report."),
      });
    } finally {
      setExporting(false);
    }
  };

  const openChooser = (mode = "generate") => {
    setChooserMode(mode);
    setChooserOpen(true);
  };

  useEffect(() => {
    if (!open) return;

    setPage(1);
    setHistory([]);
    setPagination(null);
    setGenerated(false);
    setAppliedConfig(null);
    setChooserOpen(false);
    setChooserMode("generate");
    setExporting(false);
  }, [open]);

  const clearFilters = () => {
    clearResults();
    setSearch("");
    setAdjustmentType("");
    setDateFrom("");
    setDateTo("");
    setBranchFilter(null);
    setPerPage(500);
  };

  const formatQty = (value) => {
    if (value === null || value === undefined || value === "") return "0.0000";
    return Number(value || 0).toFixed(4);
  };

  const formatCost = (value, currencyCode) => {
    if (value === null || value === undefined || value === "") return "-";
    const amount = Number(value || 0).toFixed(2);
    return currencyCode ? `${currencyCode} ${amount}` : amount;
  };

  const formatDateTime = (value) => {
    if (!value) return "-";

    try {
      return new Date(value).toLocaleString("en-GB", {
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
      });
    } catch {
      return value;
    }
  };

  const getProductName = (item) => {
    return item?.product?.name || item?.metadata?.product_name || "-";
  };

  const getProductSku = (item) => {
    return item?.product?.sku || item?.metadata?.sku || "-";
  };

  const getProductCode = (item) => {
    return item?.product?.code || item?.metadata?.product_code || "-";
  };

  const getBarcode = (item) => {
    return item?.product?.barcode || item?.metadata?.barcode || "-";
  };

  const getUom = (item) => {
    const uom = item?.product?.uom;

    if (!uom) return "-";

    const code = uom.code ? ` (${uom.code})` : "";
    return `${uom.name || "-"}${code}`;
  };

  const getBranch = (item) => {
    return (
      item?.branch?.branch_name ||
      item?.branch?.name ||
      resolvedBranchName ||
      "Current Branch"
    );
  };

  const getCreatedByName = (item) => {
    return (
      item?.created_by?.name ||
      item?.createdBy?.name ||
      item?.created_by_user?.name ||
      item?.created_by?.username ||
      item?.createdBy?.username ||
      "Unknown User"
    );
  };

  const getCreatedByMeta = (item) => {
    const user = item?.created_by || item?.createdBy || item?.created_by_user;

    if (!user) return "-";

    const parts = [user.username, user.employee_code, user.user_type].filter(
      Boolean
    );

    return parts.length ? parts.join(" • ") : "-";
  };

  const getDetectedType = (item) => {
    const quantityIn = Number(item?.quantity_in || 0);
    const quantityOut = Number(item?.quantity_out || 0);

    if (quantityIn > 0) return "increase";
    if (quantityOut > 0) return "decrease";
    return "no change";
  };

  const getDifference = (item) => {
    const quantityIn = Number(item?.quantity_in || 0);
    const quantityOut = Number(item?.quantity_out || 0);

    return quantityIn - quantityOut;
  };

  const totalIncrease = useMemo(() => {
    return history.reduce((sum, item) => {
      return sum + Number(item?.quantity_in || 0);
    }, 0);
  }, [history]);

  const totalDecrease = useMemo(() => {
    return history.reduce((sum, item) => {
      return sum + Number(item?.quantity_out || 0);
    }, 0);
  }, [history]);

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="STOCK ADJUSTMENT HISTORY"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="stock-adjustment-history-workspace flex h-full min-h-0 overflow-hidden bg-[#DFE5E5]">
          <InventoryReportNavigator
            activeKey={LEGACY_STOCK_ADJUSTMENT_REPORT_KEY}
            onNavigate={onNavigateReport}
          />
          <div className="h-full min-h-0 min-w-0 flex-1 bg-white border border-[#ACB8B8] flex flex-col overflow-hidden">
            {!generated ? (
            <div className="min-h-0 flex-1 overflow-y-auto">
            <HospitalityReportScopePanel
              filters={scopeFilters}
              setFilters={setReportScope}
              lookups={scopeLookups}
              onApply={() => openChooser("generate")}
              loading={loading}
              businessModeLabel={businessModeLabel}
              supported={{
                branch_id: true,
                date_from: true,
                date_to: true,
                till_id: false,
                cashier_id: false,
                shift_session_id: false,
                currency_codes: false,
              }}
            />
            <div className="shrink-0 border-b border-[#D7DFDE] bg-[#F8FAFC] p-2 grid grid-cols-1 md:grid-cols-3 gap-2">
              <input
                type="text"
                value={search}
                onChange={(e) => { clearResults(); setSearch(e.target.value); }}
                placeholder="Search product, SKU, barcode..."
                className="input-small"
              />

              <select
                value={adjustmentType}
                onChange={(e) => { clearResults(); setAdjustmentType(e.target.value); }}
                className="input-small"
              >
                <option value="">All Adjustment Types</option>
                <option value="increase">Increase</option>
                <option value="decrease">Decrease</option>
              </select>

              <div className="h-[1.85rem] border border-[#ACB8B8] bg-white px-2 flex items-center text-[10px] font-black uppercase text-[#324444]">
                {loading ? "Loading..." : `${history.length} Displayed`}
              </div>
            </div>

            <div className="flex justify-end border-b border-[#D7DFDE] bg-white px-3 py-2">
              <button
                type="button"
                onClick={clearFilters}
                disabled={loading}
                className="h-8 border border-[#ACB8B8] bg-white px-3 text-[10px] font-black uppercase text-[#324444] disabled:opacity-60"
              >
                Clear filters
              </button>
            </div>
            <ReportGenerationPrompt
              title="Configure Adjustment Audit History"
              message="Choose the branch, period and adjustment filters, then select Generate."
            />
            </div>
            ) : (
            <ReportViewShell
              title="Product Stock Adjustment Report"
              subtitle={`Branch: ${selectedBranchName}`}
              onBackToFilters={() => setGenerated(false)}
              busy={loading || exporting}
              actions={(
                <>
                  <button
                    type="button"
                    onClick={() => loadHistory(1, appliedConfig)}
                    disabled={loading || exporting}
                    className="h-8 border border-[#ABB2B2] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-60"
                  >
                    {loading ? "Loading" : "Refresh"}
                  </button>
                  <button
                    type="button"
                    onClick={() => openChooser("download")}
                    disabled={loading || exporting || !appliedConfig}
                    className="inline-flex h-8 items-center gap-1 border border-[#027A48] bg-white px-3 text-[11px] font-semibold text-[#027A48] disabled:opacity-60"
                  >
                    <Download className="h-3.5 w-3.5" /> Download
                  </button>
                </>
              )}
            >
            <div className="shrink-0 border-b border-[#D7DFDE] bg-[#EAF4F3] px-3 py-2">
              <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                <InfoText
                  label="Total Records"
                  value={pagination?.total ?? history.length}
                />
                <InfoText label="Displayed" value={history.length} />
                <InfoText label="Total Increase" value={formatQty(totalIncrease)} />
                <InfoText label="Total Decrease" value={formatQty(totalDecrease)} />
                <InfoText
                  label="Net Movement"
                  value={formatQty(totalIncrease - totalDecrease)}
                />
              </div>
            </div>

            <div className="flex-1 min-h-0 overflow-auto">
              <table className="w-full border-collapse min-w-[1780px]">
                <thead className="sticky top-0 z-10">
                  <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                    {[
                      "Date / Time",
                      "Product",
                      "Barcode",
                      "SKU",
                      "Product Code",
                      "UOM",
                      "Type",
                      "Qty In",
                      "Qty Out",
                      "Difference",
                      "Average Cost",
                      "Average-Cost Value",
                      "Cost Currency",
                      "Branch",
                      "Adjusted By",
                      "User Details",
                      "Notes",
                    ].map((header) => (
                      <th
                        key={header}
                        className="text-left px-3 py-2 text-[10px] font-black uppercase"
                      >
                        {header}
                      </th>
                    ))}
                  </tr>
                </thead>

                <tbody>
                  {loading ? (
                    <tr>
                      <td
                        colSpan={17}
                        className="px-3 py-8 text-center text-[11px] text-[#637877]"
                      >
                        Loading adjustment history...
                      </td>
                    </tr>
                  ) : history.length === 0 ? (
                    <tr>
                      <td
                        colSpan={17}
                        className="px-3 py-8 text-center text-[11px] text-[#637877]"
                      >
                        No stock adjustment history found.
                      </td>
                    </tr>
                  ) : (
                    history.map((item) => {
                      const type = getDetectedType(item);
                      const difference = getDifference(item);

                      return (
                        <tr
                          key={item.id}
                          className="border-b border-[#EFF2F2] hover:bg-[#F8FAFC]"
                        >
                          <td className="px-3 py-2 text-[10px] font-bold">
                            {formatDateTime(item.created_at)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-black text-[#003636] min-w-[220px]">
                            {getProductName(item)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {getBarcode(item)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-bold">
                            {getProductSku(item)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {getProductCode(item)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {getUom(item)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            <span
                              className={`px-2 py-1 text-[9px] font-black uppercase ${
                                type === "increase"
                                  ? "bg-[#DCFCE7] text-[#166534]"
                                  : type === "decrease"
                                  ? "bg-red-100 text-red-700"
                                  : "bg-[#E4E8E8] text-[#324444]"
                              }`}
                            >
                              {type}
                            </span>
                          </td>

                          <td className="px-3 py-2 text-[10px] font-black text-[#166534]">
                            {formatQty(item.quantity_in)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-black text-red-700">
                            {formatQty(item.quantity_out)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-black">
                            <span
                              className={
                                difference > 0
                                  ? "text-[#166534]"
                                  : difference < 0
                                  ? "text-red-700"
                                  : "text-[#637877]"
                              }
                            >
                              {difference > 0 ? "+" : ""}
                              {formatQty(difference)}
                            </span>
                          </td>

                          <td className="px-3 py-2 text-right text-[10px] font-bold">
                            {formatCost(item.average_cost, item.cost_currency_code)}
                          </td>

                          <td className="px-3 py-2 text-right text-[10px] font-black text-[#003636]">
                            {formatCost(item.cost_value, item.cost_currency_code)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-bold">
                            {item.cost_currency_code || "-"}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {getBranch(item)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-black">
                            {getCreatedByName(item)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {getCreatedByMeta(item)}
                          </td>

                          <td className="px-3 py-2 text-[10px] max-w-[300px]">
                            <span className="line-clamp-2">
                              {item.notes || "-"}
                            </span>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>

            {pagination && (
              <div className="h-10 shrink-0 border-t border-[#D7DFDE] bg-[#F8FAFC] px-3 flex items-center justify-between">
                <button
                  type="button"
                  disabled={page <= 1 || loading}
                  onClick={() => loadHistory(page - 1, appliedConfig)}
                  className="h-7 px-3 bg-[#E4E8E8] border border-[#ACB8B8] text-[10px] font-black uppercase disabled:opacity-50"
                >
                  Prev
                </button>

                <div className="text-[10px] font-black text-[#324444] uppercase">
                  Showing {pagination.from || 0}-{pagination.to || 0} of{" "}
                  {pagination.total || 0} / Page{" "}
                  {pagination.current_page || page} of{" "}
                  {pagination.last_page || 1}
                </div>

                <button
                  type="button"
                  disabled={page >= (pagination.last_page || 1) || loading}
                  onClick={() => loadHistory(page + 1, appliedConfig)}
                  className="h-7 px-3 bg-[#E4E8E8] border border-[#ACB8B8] text-[10px] font-black uppercase disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            )}
            </ReportViewShell>
            )}
          </div>
        </div>

        <AppInlineStyles />
        <ReportOutputChooser
          open={chooserOpen}
          reportTitle="Stock Adjustment History"
          description={chooserMode === "download" ? "Choose a backend-generated download format." : "View the history or download it directly from the backend."}
          busy={loading || exporting}
          onClose={() => setChooserOpen(false)}
          onView={chooserMode === "generate" ? () => loadHistory(1, draftConfig()) : undefined}
          onDownloadPdf={() => downloadHistory("pdf")}
          onDownloadExcel={() => downloadHistory("xlsx")}
          outputs={chooserMode === "download" ? { view: false } : undefined}
        />
      </FullScreenModal>

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

const InfoText = ({ label, value }) => {
  return (
    <div>
      <div className="text-[8px] font-black uppercase text-[#637877]">
        {label}
      </div>
      <div className="text-[10px] font-black text-[#003636]">{value}</div>
    </div>
  );
};

const AppInlineStyles = () => (
  <style>
    {`
      .stock-adjustment-history-workspace .input-small {
        width: 100%;
        height: 1.85rem;
        border: 1px solid #ACB8B8;
        padding: 0 0.45rem;
        font-size: 10px;
        outline: none;
        background: white;
        border-radius: 0;
      }

      .stock-adjustment-history-workspace .input-small:focus {
        border-color: #00BCBC;
        box-shadow: inset 0 0 0 1px #00BCBC;
      }

      .stock-adjustment-history-workspace button,
      .stock-adjustment-history-workspace input,
      .stock-adjustment-history-workspace select,
      .stock-adjustment-history-workspace textarea {
        border-radius: 0 !important;
      }
    `}
  </style>
);

export default StockAdjustmentHistoryModal;
