import React, { useEffect, useMemo, useState } from "react";
import ManagerApprovalModal from "./ManagerApprovalModal";
import {
  getPriceLevelOverrideConfig,
  getProductOverridePriceLevels,
  applyPriceLevelOverride,
} from "../services/PriceLevelOverrideService";

const money = (value, currency = "USD") =>
  `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const boolValue = (value) =>
  value === true || value === 1 || value === "1" || value === "true";

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;

  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }

  return data?.message || data?.error || error?.message || fallback;
};

const getLineProductId = (line) =>
  line?.product_id || line?.productId || line?.rawProduct?.id || null;

const getLineProductName = (line) =>
  line?.name || line?.product_name || line?.rawProduct?.name || "Product";

const getLineCurrentPrice = (line) =>
  Number(line?.price || line?.unit_price || line?.unitPrice || 0);

const getLineCurrentPriceLevelId = (line) =>
  line?.priceLevelId ||
  line?.price_level_id ||
  line?.basePriceLevelId ||
  line?.base_price_level_id ||
  null;

export default function PriceOverrideModal({
  open,
  onClose,
  line,
  onApplied,
  title = "Price Override",
}) {
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);
  const [loadingRows, setLoadingRows] = useState(false);
  const [config, setConfig] = useState({
    enabled: false,
    requires_manager_approval: true,
    cashier_can_override_without_manager: false,
  });
  const [rows, setRows] = useState([]);
  const [context, setContext] = useState(null);
  const [managerApprovalOpen, setManagerApprovalOpen] = useState(false);
  const [managerApproval, setManagerApproval] = useState(null);

  const productId = useMemo(() => getLineProductId(line), [line]);
  const productName = useMemo(() => getLineProductName(line), [line]);
  const currentPrice = useMemo(() => getLineCurrentPrice(line), [line]);
  const currentPriceLevelId = useMemo(() => getLineCurrentPriceLevelId(line), [line]);

  useEffect(() => {
    if (!open) return;

    setNotice("");
    setRows([]);
    setContext(null);
    setManagerApproval(null);
    setManagerApprovalOpen(false);
    loadConfigAndRows();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, productId]);

  if (!open) return null;

  const close = () => {
    setNotice("");
    setRows([]);
    setContext(null);
    setManagerApproval(null);
    setManagerApprovalOpen(false);
    onClose?.();
  };

  const loadConfigAndRows = async () => {
    if (!productId) {
      setNotice("No product line selected.");
      return;
    }

    try {
      setLoading(true);
      setNotice("");

      const response = await getPriceLevelOverrideConfig();
      const data = response?.data || response || {};

      const nextConfig = {
        enabled: boolValue(data.enabled),
        requires_manager_approval: boolValue(data.requires_manager_approval),
        cashier_can_override_without_manager: boolValue(
          data.cashier_can_override_without_manager
        ),
      };

      setConfig(nextConfig);

      if (!nextConfig.enabled) {
        setNotice("Price level override is disabled in General Settings.");
        return;
      }

      if (
        nextConfig.requires_manager_approval &&
        !nextConfig.cashier_can_override_without_manager
      ) {
        setNotice("");
        setManagerApprovalOpen(true);
        return;
      }

      await loadOverrideLevels(null);
    } catch (error) {
      setNotice(getErrorMessage(error, "Failed to load price override configuration."));
    } finally {
      setLoading(false);
    }
  };

  const loadOverrideLevels = async (approval = null) => {
    if (!productId) return;

    try {
      setLoadingRows(true);
      setNotice("");

      const approvalId =
        approval?.approval_id ||
        approval?.id ||
        approval?.manager_approval_id ||
        null;

      const response = await getProductOverridePriceLevels(productId, {
        manager_approval_id: approvalId,
      });

      const data = response?.data || response || {};
      const nextRows = Array.isArray(data.rows)
        ? data.rows
        : Array.isArray(data)
          ? data
          : [];

      setRows(nextRows);
      setContext({
        ...(data.context || {}),
        manager_approval: approval,
      });

      if (nextRows.length === 0) {
        setNotice("No eligible price levels found for this product and approved role.");
      }
    } catch (error) {
      setNotice(getErrorMessage(error, "Failed to load eligible price levels."));
    } finally {
      setLoadingRows(false);
    }
  };

  const handleManagerApproved = async (approval) => {
    setManagerApproval(approval);
    setManagerApprovalOpen(false);
    await loadOverrideLevels(approval);
  };

  const applyRow = async (row) => {
    if (!line || !row) return;

    try {
      setLoading(true);
      setNotice("");

      const approvalId =
        managerApproval?.approval_id ||
        managerApproval?.id ||
        managerApproval?.manager_approval_id ||
        context?.manager_approval?.approval_id ||
        context?.manager_approval?.id ||
        null;

      const response = await applyPriceLevelOverride({
        product_id: productId,
        product_price_id: row.id,
        line_id: line.lineId || line.id || null,
        old_price: currentPrice,
        old_price_level_id: currentPriceLevelId,
        manager_approval_id: approvalId,
        reason: `Applied ${row.price_level_name || "price level"} override`,
        metadata: {
          source: "quotation_or_sale_price_override_modal",
          product_name: productName,
          current_price: currentPrice,
          current_currency: line.currency,
          target_price: row.price,
          target_currency: row.currency,
          target_price_level_id: row.price_level_id,
          target_price_level_name: row.price_level_name,
        },
      });

      const payload = response?.data || response || {};
      const approvedPrice = payload.product_price || row;

      onApplied?.({
        ...payload,
        product_price_id: approvedPrice.id || row.id,
        price_level_id: approvedPrice.price_level_id || row.price_level_id,
        price_level_name: approvedPrice.price_level_name || row.price_level_name,
        price_level_code: approvedPrice.price_level_code || row.price_level_code,
        price: Number(approvedPrice.price || row.price || 0),
        unit_price: Number(approvedPrice.price || row.price || 0),
        currency: approvedPrice.currency || row.currency || line.currency,
        manager_approval_id: approvalId,
        approved_by: payload.approved_by || managerApproval?.approved_by || null,
        audit_id: payload.audit_id || null,
        old_price: currentPrice,
        old_price_level_id: currentPriceLevelId,
      });

      close();
    } catch (error) {
      setNotice(getErrorMessage(error, "Price override failed."));
    } finally {
      setLoading(false);
    }
  };

  const approvalRequired =
    config.enabled &&
    config.requires_manager_approval &&
    !config.cashier_can_override_without_manager &&
    !managerApproval;

  return (
    <>
      <div
        className={`fixed inset-0 z-[240] flex items-center justify-center bg-black/60 p-4 ${
          managerApprovalOpen ? "pointer-events-none opacity-25" : ""
        }`}
      >
        <div className="sx-large-modal-window border border-[#ACB8B8] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label={title}>
          <div className="shrink-0 border-b border-[#ACB8B8] bg-[#004344] px-4 py-3 text-white">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="text-[14px] font-black uppercase tracking-wide">
                  {title}
                </div>
                <div className="mt-[2px] truncate text-[11px] font-semibold text-white/75">
                  {productName} / Current: {money(currentPrice, line?.currency)}
                </div>
              </div>

              <button
                type="button"
                onClick={close}
                className="h-8 border border-white bg-white px-3 text-[10px] font-black uppercase text-[#004343]"
              >
                Close
              </button>
            </div>
          </div>

          {notice && (
            <div className="shrink-0 whitespace-pre-line border-b border-[#FEDF89] bg-[#FFFAEB] px-4 py-2 text-[11px] font-bold text-[#B54708]">
              {notice}
            </div>
          )}

          {(loading || loadingRows) && (
            <div className="shrink-0 border-b border-[#A4E5E4] bg-[#F1F8F8] px-4 py-2 text-[11px] font-black text-[#057B7B]">
              {loadingRows ? "Loading eligible price levels..." : "Processing..."}
            </div>
          )}

          <div className="min-h-0 flex-1 overflow-y-auto bg-[#F8FAFC] p-4">
            {!config.enabled && (
              <div className="border border-[#FDA29B] bg-[#FEF3F2] p-4 text-[12px] font-black text-[#B42318]">
                Price override is disabled in General Settings.
              </div>
            )}

            {approvalRequired && !managerApprovalOpen && (
              <div className="border border-[#FEDF89] bg-[#FFFAEB] p-4 text-[12px] font-bold text-[#B54708]">
                <div>Manager approval is required before eligible price levels can be shown.</div>

                <button
                  type="button"
                  onClick={() => setManagerApprovalOpen(true)}
                  className="mt-3 h-9 border border-[#D9A400] bg-[#FFC107] px-4 text-[11px] font-black uppercase text-[#5E4300]"
                >
                  Open Manager Approval
                </button>
              </div>
            )}

            <div className="grid gap-2">
              {rows.map((row) => {
                const sameLevel =
                  row.price_level_id &&
                  currentPriceLevelId &&
                  String(row.price_level_id) === String(currentPriceLevelId);

                return (
                  <button
                    key={row.id}
                    type="button"
                    disabled={sameLevel || loading}
                    onClick={() => applyRow(row)}
                    className="border border-[#D5DFDE] bg-white p-3 text-left transition hover:border-[#069495] hover:bg-[#E8F7F5] disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    <div className="flex items-center justify-between gap-3">
                      <div className="min-w-0">
                        <div className="truncate text-[12px] font-black uppercase text-[#0F2323]">
                          {row.price_level_name || "Price Level"}
                        </div>
                        <div className="mt-[2px] truncate text-[10px] font-bold text-[#637877]">
                          {row.price_level_code || "LEVEL"} / Product Price ID: {row.id}
                        </div>
                        {sameLevel && (
                          <div className="mt-1 inline-flex border border-[#CBD6D6] bg-[#F8FAFC] px-2 py-[2px] text-[9px] font-black uppercase text-[#637877]">
                            Current Level
                          </div>
                        )}
                      </div>

                      <div className="text-right">
                        <div className="text-[16px] font-black text-[#069495]">
                          {money(row.price, row.currency || line?.currency)}
                        </div>
                        <div className="text-[9px] font-bold uppercase text-[#637877]">
                          Select Price
                        </div>
                      </div>
                    </div>
                  </button>
                );
              })}
            </div>

            {config.enabled && !approvalRequired && !loadingRows && rows.length === 0 && (
              <div className="border border-dashed border-[#CBD6D6] bg-white p-6 text-center text-[12px] font-bold text-[#637877]">
                No price levels found yet.
              </div>
            )}
          </div>

          <div className="shrink-0 border-t border-[#D5DFDE] bg-white p-3">
            <button
              type="button"
              onClick={close}
              className="h-9 w-full border border-[#ACB8B8] bg-white text-[11px] font-black uppercase text-[#0F2323]"
            >
              Cancel
            </button>
          </div>
        </div>
      </div>

      {managerApprovalOpen && (
        <div className="fixed inset-0 z-[500]">
          <ManagerApprovalModal
            open
            onClose={() => {
              setManagerApprovalOpen(false);
              setNotice("Manager approval was cancelled.");
            }}
            onApproved={handleManagerApproved}
            title="Manager Approval Required"
            description={`Approve price level override for ${productName}.`}
            action="price_level_override"
            permission="approve_price_override"
            reason={`Price level override for ${productName}`}
            referenceType="price_level_override"
            referenceId={productId}
            metadata={{
              product_id: productId,
              product_name: productName,
              current_price: currentPrice,
              current_price_level_id: currentPriceLevelId,
              source: "PriceOverrideModal",
            }}
          />
        </div>
      )}
    </>
  );
}
