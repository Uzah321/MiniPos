// src/backOffice/pages/product/ProductPriceAdjustmentModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import {
  getProductCategories,
  getSuppliers,
  getPriceLevels,
  getProductPriceList,
  adjustProductPrice,
} from "../../../services/ProductService";

const ProductPriceAdjustmentModal = ({ open, onClose, onSaved }) => {
  const [products, setProducts] = useState([]);
  const [pagination, setPagination] = useState(null);

  const [priceLevels, setPriceLevels] = useState([]);
  const [categories, setCategories] = useState([]);
  const [suppliers, setSuppliers] = useState([]);

  const [priceMatrix, setPriceMatrix] = useState({});
  const [originalMatrix, setOriginalMatrix] = useState({});

  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [supplierFilter, setSupplierFilter] = useState("");
  const [productTypeFilter, setProductTypeFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [perPage, setPerPage] = useState(50);
  const [page, setPage] = useState(1);

  const [reason, setReason] = useState("");
  const [adjustmentType, setAdjustmentType] = useState("manual");
  const [currency, setCurrency] = useState("USD");

  const [loading, setLoading] = useState(false);
  const [savingRowId, setSavingRowId] = useState(null);
  const [savingAll, setSavingAll] = useState(false);

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

  const normalizeList = (response) => {
    const payload = response?.data ?? response;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;

    return [];
  };

  const normalizeProducts = (response) => {
    const payload = response?.data ?? response;

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

  const sameId = (a, b) => String(a || "") === String(b || "");

  const getProductBranchId = (product) => {
    return (
      product?.branch_id ||
      product?.branchId ||
      product?.branch?.id ||
      product?.company_branch_id ||
      product?.companyBranchId ||
      null
    );
  };

  const getPriceForLevel = (product, priceLevelId) => {
    if (!Array.isArray(product?.prices)) return null;

    return (
      product.prices.find((price) => sameId(price.price_level_id, priceLevelId)) ||
      product.prices.find((price) => sameId(price.priceLevel?.id, priceLevelId)) ||
      product.prices.find((price) => sameId(price.price_level?.id, priceLevelId)) ||
      null
    );
  };

  const buildMatrixFromProducts = (productList, levelList) => {
    const nextMatrix = {};
    const nextOriginal = {};

    productList.forEach((product) => {
      nextMatrix[product.id] = {};
      nextOriginal[product.id] = {};

      levelList.forEach((level) => {
        const price = getPriceForLevel(product, level.id);
        const value =
          price?.price !== undefined && price?.price !== null
            ? String(price.price)
            : "";

        nextMatrix[product.id][level.id] = {
          value,
          price_id: price?.id || null,
          branch_id: price?.branch_id || getProductBranchId(product) || null,
          is_default: !!price?.is_default,
          is_active: price?.is_active ?? true,
          currency: price?.currency || currency || "USD",
          starts_at: price?.starts_at || "",
          ends_at: price?.ends_at || "",
        };

        nextOriginal[product.id][level.id] = value;
      });
    });

    setPriceMatrix(nextMatrix);
    setOriginalMatrix(nextOriginal);
  };

  const loadDropdowns = async () => {
    const [levelsRes, categoriesRes, suppliersRes] = await Promise.all([
      getPriceLevels(),
      getProductCategories({ per_page: 200 }),
      getSuppliers({ per_page: 200 }),
    ]);

    const levels = normalizeList(levelsRes);

    setPriceLevels(levels);
    setCategories(normalizeList(categoriesRes));
    setSuppliers(normalizeList(suppliersRes));

    return levels;
  };

  const loadPriceList = async (nextPage = page, givenLevels = priceLevels) => {
    try {
      setLoading(true);

      const params = {
        page: nextPage,
        per_page: perPage,
      };

      if (search.trim()) params.q = search.trim();
      if (categoryFilter) params.category_id = categoryFilter;
      if (supplierFilter) params.supplier_id = supplierFilter;
      if (productTypeFilter) params.product_type = productTypeFilter;
      if (statusFilter !== "") params.is_active = statusFilter;

      const response = await getProductPriceList(params);
      const normalized = normalizeProducts(response);

      setProducts(normalized.list);
      setPagination(normalized.pagination);
      setPage(nextPage);

      buildMatrixFromProducts(normalized.list, givenLevels);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Adjustment List Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;

    setSearch("");
    setCategoryFilter("");
    setSupplierFilter("");
    setProductTypeFilter("");
    setStatusFilter("");
    setPage(1);
    setReason("");
    setAdjustmentType("manual");
    setCurrency("USD");

    loadDropdowns()
      .then((levels) => loadPriceList(1, levels))
      .catch((error) => {
        showMessage({
          type: "error",
          title: "Load Failed",
          message: getApiMessage(error),
        });
      });
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadPriceList(1);
    }, 350);

    return () => clearTimeout(timer);
  }, [
    search,
    categoryFilter,
    supplierFilter,
    productTypeFilter,
    statusFilter,
    perPage,
  ]);

  const activePriceLevels = useMemo(() => {
    return priceLevels.filter((level) => level.is_active !== false);
  }, [priceLevels]);

  const getCell = (productId, priceLevelId) => {
    return (
      priceMatrix?.[productId]?.[priceLevelId] || {
        value: "",
        price_id: null,
        branch_id: null,
        is_default: false,
        is_active: true,
        currency: currency || "USD",
        starts_at: "",
        ends_at: "",
      }
    );
  };

  const hasCellChanged = (productId, priceLevelId) => {
    const current = getCell(productId, priceLevelId).value;
    const original = originalMatrix?.[productId]?.[priceLevelId] ?? "";

    return String(current) !== String(original);
  };

  const handlePriceChange = (productId, priceLevelId, value) => {
    setPriceMatrix((prev) => ({
      ...prev,
      [productId]: {
        ...(prev[productId] || {}),
        [priceLevelId]: {
          ...getCell(productId, priceLevelId),
          value,
          currency,
        },
      },
    }));
  };

  const getChangedCellsForProduct = (product) => {
    return activePriceLevels
      .map((level) => {
        const cell = getCell(product.id, level.id);

        return {
          product,
          level,
          cell,
          changed: hasCellChanged(product.id, level.id),
        };
      })
      .filter((item) => {
        if (!item.changed) return false;
        if (item.cell.value === "") return false;
        return Number(item.cell.value) >= 0;
      });
  };

  const getAllChangedCells = () => {
    return products.flatMap((product) => getChangedCellsForProduct(product));
  };

  const validateSave = (changedCells) => {
    if (changedCells.length === 0) {
      showMessage({
        type: "error",
        title: "No Changes",
        message: "No price changes were found.",
      });

      return false;
    }

    for (const item of changedCells) {
      if (item.cell.value === "" || Number(item.cell.value) < 0) {
        showMessage({
          type: "error",
          title: "Validation Error",
          message: `Invalid price for ${item.product.name} - ${item.level.name}.`,
        });

        return false;
      }
    }

    return true;
  };

  const saveChangedCells = async (changedCells, rowProductId = null) => {
    if (!validateSave(changedCells)) return;

    try {
      if (rowProductId) {
        setSavingRowId(rowProductId);
      } else {
        setSavingAll(true);
      }

      for (const item of changedCells) {
        await adjustProductPrice(item.product.id, {
          branch_id:
            item.cell.branch_id ||
            getProductBranchId(item.product) ||
            null,
          price_level_id: item.level.id,
          price: Number(item.cell.value),
          currency: item.cell.currency || currency || "USD",
          is_default: !!item.cell.is_default,
          is_active: item.cell.is_active ?? true,
          starts_at: item.cell.starts_at || null,
          ends_at: item.cell.ends_at || null,
          adjustment_type: adjustmentType || "manual",
          reason: reason || null,
        });
      }

      showMessage({
        type: "success",
        title: "Prices Adjusted",
        message: `${changedCells.length} price adjustment(s) saved successfully.`,
      });

      await loadPriceList(page);

      if (typeof onSaved === "function") {
        onSaved();
      }
    } catch (error) {
      showMessage({
        type: "error",
        title: "Adjustment Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSavingRowId(null);
      setSavingAll(false);
    }
  };

  const handleSaveRow = (product) => {
    const changedCells = getChangedCellsForProduct(product);
    saveChangedCells(changedCells, product.id);
  };

  const handleSaveAll = () => {
    const changedCells = getAllChangedCells();
    saveChangedCells(changedCells);
  };

  const changedCount = getAllChangedCells().length;

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="PRICE ADJUSTMENT"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#DFE5E5] overflow-hidden">
          <div className="h-full min-h-0 flex flex-col bg-white border border-[#ACB8B8]">
            <div className="h-10 shrink-0 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
              <div>
                <h2 className="text-[12px] font-black uppercase tracking-wide text-white">
                  Product Price Adjustment Matrix
                </h2>
                <div className="text-[9px] font-bold uppercase text-[#00BCBC]">
                  Changed Cells: {changedCount}
                </div>
              </div>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={loading}
                  onClick={() => loadPriceList(1)}
                  className="h-7 px-3 bg-white/10 text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {loading ? "Loading" : "Refresh"}
                </button>

                <button
                  type="button"
                  disabled={savingAll || changedCount === 0}
                  onClick={handleSaveAll}
                  className="h-7 px-3 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {savingAll ? "Saving..." : "Save All Changes"}
                </button>
              </div>
            </div>

            <div className="shrink-0 border-b border-[#D7DFDE] bg-[#F8FAFC] p-2 grid grid-cols-1 md:grid-cols-3 xl:grid-cols-7 gap-2">
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search product, sku, barcode..."
                className="input-small"
              />

              <select
                value={categoryFilter}
                onChange={(e) => setCategoryFilter(e.target.value)}
                className="input-small"
              >
                <option value="">All Categories</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))}
              </select>

              <select
                value={supplierFilter}
                onChange={(e) => setSupplierFilter(e.target.value)}
                className="input-small"
              >
                <option value="">All Suppliers</option>
                {suppliers.map((supplier) => (
                  <option key={supplier.id} value={supplier.id}>
                    {supplier.name}
                  </option>
                ))}
              </select>

              <select
                value={productTypeFilter}
                onChange={(e) => setProductTypeFilter(e.target.value)}
                className="input-small"
              >
                <option value="">All Types</option>
                <option value="simple">Simple</option>
                <option value="parent">Parent</option>
                <option value="child">Child</option>
                <option value="service">Service</option>
                <option value="finished_good">Finished Good</option>
              </select>

              <select
                value={adjustmentType}
                onChange={(e) => setAdjustmentType(e.target.value)}
                className="input-small"
              >
                <option value="manual">Manual</option>
                <option value="promotion">Promotion</option>
                <option value="cost_change">Cost Change</option>
                <option value="manager_override">Manager Override</option>
                <option value="correction">Correction</option>
              </select>

              <input
                type="text"
                value={currency}
                onChange={(e) => setCurrency(String(e.target.value || "").toUpperCase())}
                placeholder="Currency"
                maxLength={3}
                className="input-small"
              />

              <select
                value={perPage}
                onChange={(e) => setPerPage(Number(e.target.value))}
                className="input-small"
              >
                <option value={25}>25 Rows</option>
                <option value={50}>50 Rows</option>
                <option value={100}>100 Rows</option>
                <option value={200}>200 Rows</option>
              </select>
            </div>

            <div className="shrink-0 border-b border-[#D7DFDE] bg-white p-2">
              <textarea
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Reason for price adjustment. This will be sent to audit trail if the backend supports it..."
                className="textarea-small h-[54px]"
              />
            </div>

            <div className="flex-1 min-h-0 overflow-auto">
              <table className="w-full border-collapse min-w-[1400px]">
                <thead className="sticky top-0 z-10">
                  <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase sticky left-0 bg-[#F3F6F8] z-20 min-w-[260px]">
                      Product
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase min-w-[110px]">
                      Code
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase min-w-[110px]">
                      SKU
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase min-w-[120px]">
                      Category
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase min-w-[130px]">
                      Type
                    </th>

                    {activePriceLevels.map((level) => (
                      <th
                        key={level.id}
                        className="text-left px-3 py-2 text-[11px] font-black uppercase min-w-[160px]"
                      >
                        {level.name}
                      </th>
                    ))}

                    <th className="w-[58px] px-3 py-2 text-center text-[11px] font-black uppercase" aria-label="Actions">
                      ⋮
                    </th>
                  </tr>
                </thead>

                <tbody>
                  {loading ? (
                    <tr>
                      <td
                        colSpan={6 + activePriceLevels.length}
                        className="px-3 py-8 text-center text-[12px] text-[#637877]"
                      >
                        Loading product prices...
                      </td>
                    </tr>
                  ) : products.length === 0 ? (
                    <tr>
                      <td
                        colSpan={6 + activePriceLevels.length}
                        className="px-3 py-8 text-center text-[12px] text-[#637877]"
                      >
                        No products found.
                      </td>
                    </tr>
                  ) : (
                    products.map((product) => {
                      const rowChanged = getChangedCellsForProduct(product).length > 0;

                      return (
                        <tr
                          key={product.id}
                          className={`border-b border-[#EFF2F2] hover:bg-[#F8FAFC] ${
                            rowChanged ? "bg-[#FFF7ED]" : ""
                          }`}
                        >
                          <td className="px-3 py-2 text-[11px] font-black sticky left-0 bg-white z-10 border-r border-[#EFF2F2]">
                            {product.name}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {product.code || "-"}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {product.sku || "-"}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {product?.category?.name || "-"}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {product.product_type || "-"}
                          </td>

                          {activePriceLevels.map((level) => {
                            const cell = getCell(product.id, level.id);
                            const changed = hasCellChanged(product.id, level.id);

                            return (
                              <td
                                key={`${product.id}-${level.id}`}
                                className="px-3 py-2"
                              >
                                <input
                                  type="number"
                                  step="0.0001"
                                  value={cell.value}
                                  title={`${cell.price_id ? "Existing" : "New"}${cell.branch_id ? " · Branch" : " · Current Branch"}${cell.is_default ? " · Default" : ""}`}
                                  onChange={(e) =>
                                    handlePriceChange(
                                      product.id,
                                      level.id,
                                      e.target.value
                                    )
                                  }
                                  className={`input-small ${
                                    changed ? "price-cell-changed" : ""
                                  }`}
                                  placeholder="0.0000"
                                />
                              </td>
                            );
                          })}

                          <td className="px-3 py-2">
                            <button
                              type="button"
                              disabled={!rowChanged || savingRowId === product.id}
                              onClick={() => handleSaveRow(product)}
                              className="h-7 px-3 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-50"
                            >
                              {savingRowId === product.id ? "Saving" : "Save Row"}
                            </button>
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
                  onClick={() => loadPriceList(page - 1)}
                  className="h-7 px-3 bg-[#E4E8E8] border border-[#ACB8B8] text-[10px] font-black uppercase disabled:opacity-50"
                >
                  Prev
                </button>

                <div className="text-[10px] font-black text-[#324444] uppercase">
                  Showing {pagination.from || 0}-{pagination.to || 0} of {" "}
                  {pagination.total || 0} / Page {pagination.current_page || page} of {" "}
                  {pagination.last_page || 1}
                </div>

                <button
                  type="button"
                  disabled={page >= (pagination.last_page || 1) || loading}
                  onClick={() => loadPriceList(page + 1)}
                  className="h-7 px-3 bg-[#E4E8E8] border border-[#ACB8B8] text-[10px] font-black uppercase disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            )}
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

const AppInlineStyles = () => (
  <style>
    {`
      .input-small {
        width: 100%;
        height: 2rem;
        border: 1px solid #ACB8B8;
        padding: 0 0.5rem;
        font-size: 11px;
        outline: none;
        background: white;
        border-radius: 0;
      }

      .textarea-small {
        width: 100%;
        border: 1px solid #ACB8B8;
        padding: 0.5rem;
        font-size: 11px;
        outline: none;
        background: white;
        resize: none;
        border-radius: 0;
      }

      .input-small:focus,
      .textarea-small:focus {
        border-color: #00BCBC;
        box-shadow: inset 0 0 0 1px #00BCBC;
      }

      .price-cell-changed {
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

export default ProductPriceAdjustmentModal;
