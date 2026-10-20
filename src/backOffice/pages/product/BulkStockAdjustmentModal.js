// src/backOffice/pages/product/BulkStockAdjustmentModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import {
  getProducts,
  adjustProductStockBulk,
} from "../../../services/ProductService";

const BulkStockAdjustmentModal = ({
  open,
  onClose,
  onSaved,
  currentBranchId = "",
  currentBranchName = "",
}) => {
  const [products, setProducts] = useState([]);
  const [quantityMap, setQuantityMap] = useState({});
  const [notesMap, setNotesMap] = useState({});

  const [search, setSearch] = useState("");
  const [productTypeFilter, setProductTypeFilter] = useState("");
  const [changedOnly, setChangedOnly] = useState(false);

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

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

  const normalizeProductsResponse = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;
    if (Array.isArray(response?.products)) return response.products;
    if (Array.isArray(payload?.products)) return payload.products;

    return [];
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

  const getBranchStock = (product) => {
    if (!Array.isArray(product?.stocks) || product.stocks.length === 0) {
      return null;
    }

    if (resolvedBranchId) {
      const branchStock = product.stocks.find(
        (stock) => stock.branch_id === resolvedBranchId
      );

      if (branchStock) return branchStock;
    }

    return product.stocks[0] || null;
  };

  const getCurrentQuantity = (product) => {
    const stock = getBranchStock(product);

    return Number(stock?.quantity_on_hand || 0);
  };

  const getAvailableQuantity = (product) => {
    const stock = getBranchStock(product);

    return Number(stock?.available_quantity || 0);
  };

  const getUomDisplay = (product) => {
    if (!product?.uom) return "-";

    const code = product.uom.code ? ` (${product.uom.code})` : "";
    return `${product.uom.name || "-"}${code}`;
  };

  const formatQty = (value) => {
    if (value === null || value === undefined || value === "") return "0.0000";
    return Number(value || 0).toFixed(4);
  };

  const loadProducts = async () => {
    try {
      setLoading(true);

      const params = {
        per_page: 300,
      };

      if (search.trim()) params.q = search.trim();
      if (productTypeFilter) params.product_type = productTypeFilter;

      const response = await getProducts(params);
      const rows = normalizeProductsResponse(response);

      setProducts(rows);

      setQuantityMap((prev) => {
        const next = { ...prev };

        rows.forEach((product) => {
          if (next[product.id] === undefined) {
            next[product.id] = String(getCurrentQuantity(product));
          }
        });

        return next;
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Products Load Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;

    setSearch("");
    setProductTypeFilter("");
    setChangedOnly(false);
    setQuantityMap({});
    setNotesMap({});

    loadProducts();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadProducts();
    }, 350);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, productTypeFilter]);

  const handleQuantityChange = (productId, value) => {
    setQuantityMap((prev) => ({
      ...prev,
      [productId]: value,
    }));
  };

  const handleNotesChange = (productId, value) => {
    setNotesMap((prev) => ({
      ...prev,
      [productId]: value,
    }));
  };

  const getNewQuantity = (product) => {
    const rawValue = quantityMap[product.id];

    if (rawValue === "" || rawValue === undefined || rawValue === null) {
      return null;
    }

    return Number(rawValue);
  };

  const getDifference = (product) => {
    const newQty = getNewQuantity(product);

    if (newQty === null || Number.isNaN(newQty)) return 0;

    return newQty - getCurrentQuantity(product);
  };

  const getAdjustmentType = (product) => {
    const difference = getDifference(product);

    if (difference > 0) return "increase";
    if (difference < 0) return "decrease";
    return "no change";
  };

  const rowHasChanged = (product) => {
    return getDifference(product) !== 0;
  };

  const filteredProducts = useMemo(() => {
    let rows = products;

    if (changedOnly) {
      rows = rows.filter((product) => rowHasChanged(product));
    }

    return rows;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [products, quantityMap, changedOnly]);

  const changedItems = useMemo(() => {
    return products
      .filter((product) => rowHasChanged(product))
      .filter((product) => {
        const newQty = getNewQuantity(product);
        return newQty !== null && !Number.isNaN(newQty) && newQty >= 0;
      })
      .map((product) => ({
        product_id: product.id,
        quantity: Number(getNewQuantity(product)),
        notes: notesMap[product.id] || null,
      }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [products, quantityMap, notesMap]);

  const validateSave = () => {
    if (!resolvedBranchId) {
      showMessage({
        type: "error",
        title: "Branch Missing",
        message: "Current logged-in branch could not be resolved.",
      });

      return false;
    }

    if (changedItems.length === 0) {
      showMessage({
        type: "error",
        title: "No Changes",
        message: "No stock quantity changes were found.",
      });

      return false;
    }

    for (const product of products) {
      const rawValue = quantityMap[product.id];

      if (rawValue === "" || rawValue === undefined || rawValue === null) {
        continue;
      }

      const quantity = Number(rawValue);

      if (Number.isNaN(quantity) || quantity < 0) {
        showMessage({
          type: "error",
          title: "Invalid Quantity",
          message: `Invalid quantity for ${product.name}.`,
        });

        return false;
      }
    }

    return true;
  };

  const handleSave = async () => {
    if (!validateSave()) return;

    try {
      setSaving(true);

      const payload = {
        branch_id: resolvedBranchId,
        items: changedItems,
      };

      const response = await adjustProductStockBulk(payload);

      const adjusted = response?.data?.adjusted || [];
      const skipped = response?.data?.skipped || [];

      showMessage({
        type: "success",
        title: "Stock Adjusted",
        message:
          response?.message ||
          `Stock adjustment completed. Adjusted: ${adjusted.length}, Skipped: ${skipped.length}.`,
      });

      if (typeof onSaved === "function") {
        onSaved(response);
      }

      await loadProducts();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Stock Adjustment Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSaving(false);
    }
  };

  const resetQuantitiesToCurrent = () => {
    const next = {};

    products.forEach((product) => {
      next[product.id] = String(getCurrentQuantity(product));
    });

    setQuantityMap(next);
    setNotesMap({});
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="BULK STOCK ADJUSTMENT"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#DFE5E5] overflow-hidden">
          <div className="h-full min-h-0 bg-white border border-[#ACB8B8] flex flex-col overflow-hidden">
            <div className="h-10 shrink-0 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
              <div>
                <h2 className="text-[12px] font-black uppercase tracking-wide text-white">
                  Stock Count / Adjustment
                </h2>
                <div className="text-[9px] font-bold uppercase text-[#00BCBC]">
                  Branch: {resolvedBranchName || "Current Branch"} • Changed:{" "}
                  {changedItems.length}
                </div>
              </div>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={loadProducts}
                  disabled={loading}
                  className="h-7 px-3 bg-white/10 text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {loading ? "Loading" : "Refresh"}
                </button>

                <button
                  type="button"
                  onClick={resetQuantitiesToCurrent}
                  disabled={loading || saving}
                  className="h-7 px-3 bg-white/10 text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  Reset
                </button>

                <button
                  type="button"
                  onClick={handleSave}
                  disabled={saving || changedItems.length === 0}
                  className="h-7 px-4 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {saving ? "Saving..." : "Save Adjustments"}
                </button>
              </div>
            </div>

            <div className="shrink-0 border-b border-[#D7DFDE] bg-[#F8FAFC] p-2 grid grid-cols-1 md:grid-cols-[1fr_180px_160px] gap-2">
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search product, barcode, SKU, product code..."
                className="input-small"
              />

              <select
                value={productTypeFilter}
                onChange={(e) => setProductTypeFilter(e.target.value)}
                className="input-small"
              >
                <option value="">All Product Types</option>
                <option value="simple">Simple</option>
                <option value="parent">Parent</option>
                <option value="child">Child</option>
                <option value="service">Service</option>
              </select>

              <label className="h-[1.85rem] border border-[#ACB8B8] bg-white px-2 flex items-center gap-2 text-[10px] font-black uppercase">
                <input
                  type="checkbox"
                  checked={changedOnly}
                  onChange={(e) => setChangedOnly(e.target.checked)}
                />
                Changed Only
              </label>
            </div>

            <div className="shrink-0 border-b border-[#D7DFDE] bg-[#EAF4F3] px-3 py-2">
              <div className="grid grid-cols-2 md:grid-cols-5 gap-2">
                <InfoText label="Products Loaded" value={products.length} />
                <InfoText label="Rows Displayed" value={filteredProducts.length} />
                <InfoText label="Changed Rows" value={changedItems.length} />
                <InfoText
                  label="Branch"
                  value={resolvedBranchName || "Current Branch"}
                />
                <InfoText
                  label="Mode"
                  value="Enter final counted quantity"
                />
              </div>
            </div>

            <div className="flex-1 min-h-0 overflow-auto">
              <table className="w-full border-collapse min-w-[1450px]">
                <thead className="sticky top-0 z-10">
                  <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                    {[
                      "Product",
                      "Barcode",
                      "SKU",
                      "Product Code",
                      "UOM",
                      "Current Qty",
                      "Available Qty",
                      "New Qty",
                      "Difference",
                      "Detected Type",
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
                        colSpan={11}
                        className="px-3 py-8 text-center text-[11px] text-[#637877]"
                      >
                        Loading products...
                      </td>
                    </tr>
                  ) : filteredProducts.length === 0 ? (
                    <tr>
                      <td
                        colSpan={11}
                        className="px-3 py-8 text-center text-[11px] text-[#637877]"
                      >
                        No products found.
                      </td>
                    </tr>
                  ) : (
                    filteredProducts.map((product) => {
                      const currentQty = getCurrentQuantity(product);
                      const availableQty = getAvailableQuantity(product);
                      const difference = getDifference(product);
                      const adjustmentType = getAdjustmentType(product);
                      const changed = rowHasChanged(product);

                      return (
                        <tr
                          key={product.id}
                          className={`border-b border-[#EFF2F2] hover:bg-[#F8FAFC] ${
                            changed ? "bg-[#FFF7ED]" : "bg-white"
                          }`}
                        >
                          <td className="px-3 py-2 text-[10px] font-black text-[#003636] min-w-[240px]">
                            {product.name || "-"}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {product.barcode || "-"}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-bold">
                            {product.sku || "-"}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {product.code || "-"}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {getUomDisplay(product)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-black">
                            {formatQty(currentQty)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {formatQty(availableQty)}
                          </td>

                          <td className="px-3 py-2">
                            <input
                              type="number"
                              step="0.0001"
                              min="0"
                              value={quantityMap[product.id] ?? ""}
                              onChange={(e) =>
                                handleQuantityChange(product.id, e.target.value)
                              }
                              className={`input-small ${
                                changed ? "changed-input" : ""
                              }`}
                              placeholder="0.0000"
                            />
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

                          <td className="px-3 py-2 text-[10px]">
                            <span
                              className={`px-2 py-1 text-[9px] font-black uppercase ${
                                adjustmentType === "increase"
                                  ? "bg-[#DCFCE7] text-[#166534]"
                                  : adjustmentType === "decrease"
                                  ? "bg-red-100 text-red-700"
                                  : "bg-[#E4E8E8] text-[#324444]"
                              }`}
                            >
                              {adjustmentType}
                            </span>
                          </td>

                          <td className="px-3 py-2 min-w-[220px]">
                            <input
                              type="text"
                              value={notesMap[product.id] || ""}
                              onChange={(e) =>
                                handleNotesChange(product.id, e.target.value)
                              }
                              className="input-small"
                              placeholder="Optional notes"
                            />
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>

            <div className="h-10 shrink-0 border-t border-[#D7DFDE] bg-[#F8FAFC] px-3 flex items-center justify-between">
              <div className="text-[10px] font-black uppercase text-[#324444]">
                Enter the final counted quantity. Backend will calculate increase
                or decrease automatically.
              </div>

              <button
                type="button"
                onClick={handleSave}
                disabled={saving || changedItems.length === 0}
                className="h-7 px-4 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
              >
                {saving ? "Saving..." : `Save ${changedItems.length} Change(s)`}
              </button>
            </div>
          </div>
        </div>

        <AppInlineStyles />
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
      .input-small {
        width: 100%;
        height: 1.85rem;
        border: 1px solid #ACB8B8;
        padding: 0 0.45rem;
        font-size: 10px;
        outline: none;
        background: white;
        border-radius: 0;
      }

      .input-small:focus {
        border-color: #00BCBC;
        box-shadow: inset 0 0 0 1px #00BCBC;
      }

      .changed-input {
        border-color: #F97316 !important;
        background: #FFF7ED !important;
        box-shadow: inset 0 0 0 1px #F97316;
      }

      button,
      input,
      select,
      textarea {
        border-radius: 0 !important;
      }
    `}
  </style>
);

export default BulkStockAdjustmentModal;
