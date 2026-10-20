// src/backOffice/pages/product/ProductPriceAdjustmentHistoryModal.jsx

import React, { useEffect, useState } from "react";
import { Download } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, {
  useHospitalityReportLookups,
} from "../../../components/HospitalityReportScopePanel";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import ManagementReportNavigator, {
  MANAGEMENT_REPORT_KEYS,
} from "../../../components/ManagementReportNavigator";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";

import {
  downloadProductPriceAdjustmentHistory,
  getProductPriceAdjustmentHistory,
} from "../../../services/ProductService";

const ProductPriceAdjustmentHistoryModal = ({
  open,
  onClose,
  selectedProduct,
  businessModeLabel = "Hospitality",
  onNavigateReport,
}) => {
  const [history, setHistory] = useState([]);
  const [pagination, setPagination] = useState(null);

  const [perPage, setPerPage] = useState(50);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [generated, setGenerated] = useState(false);
  const [appliedConfig, setAppliedConfig] = useState(null);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserMode, setChooserMode] = useState("generate");
  const [exporting, setExporting] = useState(false);
  const [scopeFilters, setScopeFilters] = useState({
    branch_id: "",
    date_from: "",
    date_to: "",
    till_id: "",
    cashier_id: "",
    shift_session_id: "",
    currency_codes: [],
  });
  const scopeLookups = useHospitalityReportLookups(open, scopeFilters);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

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

  const showMessage = ({ type = "success", title, message }) => {
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

  const draftConfig = (nextPerPage = perPage) => ({
    productId: selectedProduct?.id || "",
    productName: selectedProduct?.name || "Selected product",
    productCode: selectedProduct?.code || "-",
    productSku: selectedProduct?.sku || "-",
    productBarcode: selectedProduct?.barcode || "-",
    perPage: nextPerPage,
    filters: {},
  });

  const loadHistory = async (nextPage = page, requestedConfig = null) => {
    const snapshot = { ...(requestedConfig || draftConfig()) };
    if (!snapshot.productId) {
      clearResults();
      setChooserOpen(false);
      showMessage({
        type: "error",
        title: "Product Required",
        message: "Open Price Adjustment History from a product in the Price List before generating this report.",
      });
      return;
    }

    try {
      setLoading(true);

      const response = await getProductPriceAdjustmentHistory(
        snapshot.productId,
        {
          ...snapshot.filters,
          page: nextPage,
          per_page: snapshot.perPage,
        }
      );

      const normalized = normalizeHistory(response);

      setHistory(normalized.list);
      setPagination(normalized.pagination);
      setPage(nextPage);
      setPerPage(snapshot.perPage);
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

    if (!snapshot.productId) {
      setChooserOpen(false);
      showMessage({
        type: "error",
        title: "Product Required",
        message: "Select a product before downloading price adjustment history.",
      });
      return;
    }

    try {
      setExporting(true);
      await downloadProductPriceAdjustmentHistory(
        snapshot.productId,
        format,
        snapshot.filters,
      );
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
  }, [open, selectedProduct]);

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

  const formatMoney = (value, currency = "USD") => {
    if (value === null || value === undefined || value === "") return "-";

    return `${currency} ${Number(value || 0).toFixed(4)}`;
  };

  const getAdjustedByName = (item) => {
    return (
      item?.adjudtedby?.name ||
      item?.adjustedBy?.name ||
      item?.adjusted_by_user?.name ||
      "Unknown User"
    );
  };

  const getAdjustedByMeta = (item) => {
    const user = item?.adjudtedby || item?.adjustedBy || item?.adjusted_by_user;

    if (!user) return "-";

    const parts = [
      user.username,
      user.employee_code,
      user.user_type,
    ].filter(Boolean);

    return parts.length ? parts.join(" • ") : "-";
  };

  const getProductName = (item) => {
    return (
      item?.product?.name ||
      item?.metadata?.product_name ||
      selectedProduct?.name ||
      "-"
    );
  };

  const getProductCode = (item) => {
    return (
      item?.product?.code ||
      item?.metadata?.product_code ||
      selectedProduct?.code ||
      "-"
    );
  };

  const getPriceLevelName = (item) => {
    return (
      item?.price_level?.name ||
      item?.priceLevel?.name ||
      item?.metadata?.price_level_name ||
      "-"
    );
  };

  const getBranchName = (item) => {
    return (
      item?.branch?.branch_name ||
      item?.branch?.name ||
      item?.metadata?.branch_name ||
      "Logged-In Branch"
    );
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="PRICE ADJUSTMENT HISTORY"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="price-adjustment-history-workspace flex h-full min-h-0 overflow-hidden bg-[#DFE5E5]">
          <ManagementReportNavigator
            activeKey={MANAGEMENT_REPORT_KEYS.PRICE_ADJUSTMENT}
            onNavigate={onNavigateReport}
            businessModeLabel={businessModeLabel}
          />
          <div className="h-full min-h-0 min-w-0 flex-1 flex flex-col bg-white border border-[#ACB8B8]">
            {!generated ? (
            <div className="min-h-0 flex-1 overflow-y-auto">
            <HospitalityReportScopePanel
              filters={scopeFilters}
              setFilters={(next) => {
                clearResults();
                setScopeFilters(next);
              }}
              lookups={scopeLookups}
              onApply={() => openChooser("generate")}
              loading={loading}
              businessModeLabel={businessModeLabel}
              supported={{
                branch_id: false,
                date_from: false,
                date_to: false,
                till_id: false,
                cashier_id: false,
                shift_session_id: false,
                currency_codes: false,
              }}
            />
            <ReportGenerationPrompt
              title="Configure Price Adjustment History"
              message={selectedProduct?.id
                ? `Generate the price history for ${selectedProduct.name || "the selected product"}.`
                : "Select a product from Price List, open its history, then select Generate."}
            />
            </div>
            ) : (
            <ReportViewShell
              title="Price Adjustment History"
              subtitle={appliedConfig?.productName || "Selected product"}
              onBackToFilters={() => setGenerated(false)}
              busy={loading || exporting}
              actions={(
                <>
                  <button
                    type="button"
                    onClick={() => loadHistory(1, appliedConfig)}
                    disabled={loading || exporting || !appliedConfig?.productId}
                    className="h-8 border border-[#ABB2B2] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-60"
                  >
                    {loading ? "Loading" : "Refresh"}
                  </button>
                  <button
                    type="button"
                    onClick={() => openChooser("download")}
                    disabled={loading || exporting || !appliedConfig?.productId}
                    className="inline-flex h-8 items-center gap-1 border border-[#027A48] bg-white px-3 text-[11px] font-semibold text-[#027A48] disabled:opacity-60"
                  >
                    <Download className="h-3.5 w-3.5" /> Download
                  </button>
                </>
              )}
            >

            <div className="h-16 shrink-0 border-b border-[#D7DFDE] bg-[#F8FAFC] px-3 grid grid-cols-2 md:grid-cols-5 gap-2 items-center">
              <InfoBox label="Product" value={appliedConfig?.productName || "-"} />
              <InfoBox label="Code" value={appliedConfig?.productCode || "-"} />
              <InfoBox label="SKU" value={appliedConfig?.productSku || "-"} />
              <InfoBox
                label="Barcode"
                value={appliedConfig?.productBarcode || "-"}
              />
              <InfoBox
                label="Total Records"
                value={pagination?.total ?? history.length}
              />
            </div>

            <div className="flex shrink-0 items-center justify-end gap-2 border-b border-[#D7DFDE] bg-white px-3 py-2 text-[10px] font-semibold uppercase text-[#465757]">
              <label htmlFor="price-history-page-size">Rows per page</label>
              <select
                id="price-history-page-size"
                value={perPage}
                onChange={(event) => loadHistory(1, {
                  ...appliedConfig,
                  perPage: Number(event.target.value),
                })}
                disabled={loading}
                className="h-7 border border-[#ACB8B8] bg-white px-2 text-[11px] text-[#324443] outline-none"
              >
                <option value={25}>25</option>
                <option value={50}>50</option>
                <option value={100}>100</option>
              </select>
            </div>

            <div className="flex-1 min-h-0 overflow-auto">
              <table className="w-full border-collapse min-w-[1450px]">
                <thead className="sticky top-0 z-10">
                  <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                    {[
                      "Date / Time",
                      "Product",
                      "Product Code",
                      "Price Level",
                      "Old Price",
                      "New Price",
                      "Change",
                      "Type",
                      "Reason",
                      "Adjusted By",
                      "User Details",
                      "Branch",
                    ].map((header) => (
                      <th
                        key={header}
                        className="text-left px-3 py-2 text-[11px] font-black uppercase"
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
                        colSpan={12}
                        className="px-3 py-8 text-center text-[12px] text-[#637877]"
                      >
                        Loading adjustment history...
                      </td>
                    </tr>
                  ) : history.length === 0 ? (
                    <tr>
                      <td
                        colSpan={12}
                        className="px-3 py-8 text-center text-[12px] text-[#637877]"
                      >
                        No adjustment history found.
                      </td>
                    </tr>
                  ) : (
                    history.map((item) => {
                      const oldPrice =
                        item.old_price !== null && item.old_price !== undefined
                          ? Number(item.old_price)
                          : null;

                      const newPrice = Number(item.new_price || 0);
                      const difference =
                        oldPrice === null ? null : newPrice - oldPrice;

                      return (
                        <tr
                          key={item.id}
                          className="border-b border-[#EFF2F2] hover:bg-[#F8FAFC]"
                        >
                          <td className="px-3 py-2 text-[11px] font-bold">
                            {formatDateTime(item.adjusted_at || item.created_at)}
                          </td>

                          <td className="px-3 py-2 text-[11px] font-black">
                            {getProductName(item)}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {getProductCode(item)}
                          </td>

                          <td className="px-3 py-2 text-[11px] font-bold">
                            {getPriceLevelName(item)}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {formatMoney(item.old_price, item.currency)}
                          </td>

                          <td className="px-3 py-2 text-[11px] font-black text-[#005A5A]">
                            {formatMoney(item.new_price, item.currency)}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {difference === null ? (
                              <span className="px-2 py-1 text-[10px] font-black uppercase bg-[#E8F7F5] text-[#005A5A]">
                                New Price
                              </span>
                            ) : (
                              <span
                                className={`px-2 py-1 text-[10px] font-black uppercase ${
                                  difference >= 0
                                    ? "bg-[#DCFCE7] text-[#166534]"
                                    : "bg-red-100 text-red-700"
                                }`}
                              >
                                {difference >= 0 ? "+" : ""}
                                {formatMoney(difference, item.currency)}
                              </span>
                            )}
                          </td>

                          <td className="px-3 py-2 text-[11px] uppercase">
                            {item.adjustment_type || "manual"}
                          </td>

                          <td className="px-3 py-2 text-[11px] max-w-[320px]">
                            <span className="line-clamp-2">
                              {item.reason || "No reason captured"}
                            </span>
                          </td>

                          <td className="px-3 py-2 text-[11px] font-black">
                            {getAdjustedByName(item)}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {getAdjustedByMeta(item)}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {getBranchName(item)}
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
          reportTitle="Price Adjustment History"
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

const InfoBox = ({ label, value }) => (
  <div className="border border-[#ACB8B8] bg-white px-2 py-1 min-h-[42px]">
    <div className="text-[8px] font-black uppercase text-[#637877]">
      {label}
    </div>
    <div className="text-[11px] font-black text-[#003636] truncate">
      {value}
    </div>
  </div>
);

const AppInlineStyles = () => (
  <style>
    {`
      .price-adjustment-history-workspace button,
      .price-adjustment-history-workspace input,
      .price-adjustment-history-workspace select,
      .price-adjustment-history-workspace textarea {
        border-radius: 0 !important;
      }
    `}
  </style>
);

export default ProductPriceAdjustmentHistoryModal;
