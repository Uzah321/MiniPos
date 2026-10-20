// src/backOffice/pages/product/ProductPriceListModal.jsx

import React, { useEffect, useMemo, useState } from "react";

import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import {
  getProductCategories,
  getSuppliers,
  getPriceLevels,
  getProductPriceList,
  downloadProductPriceList,
} from "../../../services/ProductService";

const ProductPriceListModal = ({
  open,
  onClose,
  onOpenAdjustment,
  onViewHistory,
}) => {
  const [products, setProducts] = useState([]);
  const [pagination, setPagination] = useState(null);

  const [priceLevels, setPriceLevels] = useState([]);
  const [categories, setCategories] = useState([]);
  const [suppliers, setSuppliers] = useState([]);

  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [supplierFilter, setSupplierFilter] = useState("");
  const [productTypeFilter, setProductTypeFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [perPage, setPerPage] = useState(50);
  const [page, setPage] = useState(1);

  const [loading, setLoading] = useState(false);

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
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;

    return [];
  };

  const normalizeProducts = (response) => {
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

  const loadDropdowns = async () => {
    const [levelsRes, categoriesRes, suppliersRes] = await Promise.all([
      getPriceLevels(),
      getProductCategories({ per_page: 200 }),
      getSuppliers({ per_page: 200 }),
    ]);

    setPriceLevels(normalizeList(levelsRes));
    setCategories(normalizeList(categoriesRes));
    setSuppliers(normalizeList(suppliersRes));
  };

  const buildPriceListParams = (nextPage = page, nextPerPage = perPage) => {
    const params = {
      page: nextPage,
      per_page: nextPerPage,
    };

    if (search.trim()) params.q = search.trim();
    if (categoryFilter) params.category_id = categoryFilter;
    if (supplierFilter) params.supplier_id = supplierFilter;
    if (productTypeFilter) params.product_type = productTypeFilter;
    if (statusFilter !== "") params.is_active = statusFilter;

    return params;
  };

  const loadPriceList = async (nextPage = page) => {
    try {
      setLoading(true);

      const response = await getProductPriceList(
        buildPriceListParams(nextPage, perPage)
      );

      const normalized = normalizeProducts(response);

      setProducts(normalized.list);
      setPagination(normalized.pagination);
      setPage(nextPage);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Price List Failed",
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

    loadDropdowns().catch((error) => {
      showMessage({
        type: "error",
        title: "Dropdown Load Failed",
        message: getApiMessage(error),
      });
    });

    loadPriceList(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadPriceList(1);
    }, 350);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
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

  const getPriceForLevel = (product, priceLevelId) => {
    if (!Array.isArray(product?.prices)) return null;

    return (
      product.prices.find(
        (price) => String(price.price_level_id) === String(priceLevelId)
      ) ||
      product.prices.find(
        (price) => String(price.priceLevel?.id) === String(priceLevelId)
      ) ||
      product.prices.find(
        (price) => String(price.price_level?.id) === String(priceLevelId)
      ) ||
      null
    );
  };

  const formatPrice = (price) => {
    if (!price) return "-";
    return `${price.currency || "USD"} ${Number(price.price || 0).toFixed(4)}`;
  };

  const getBranchDisplay = (price) => {
    if (!price) return "";

    return (
      price?.branch?.branch_name ||
      price?.branch?.name ||
      (price.branch_id ? "Branch" : "Company")
    );
  };

  const downloadPriceList = async (format) => {
    try {
      setLoading(true);
      await downloadProductPriceList(format, buildPriceListParams(1, perPage));
    } catch (error) {
      showMessage({
        type: "error",
        title: `${format === "pdf" ? "PDF" : "Excel"} Export Failed`,
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  const downloadExcelPriceList = () => downloadPriceList("xlsx");
  const downloadPdfPriceList = () => downloadPriceList("pdf");

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="PRODUCT PRICE LIST"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#DFE5E5] overflow-hidden">
          <div className="h-full min-h-0 flex flex-col bg-white border border-[#ACB8B8]">
            <div className="h-10 shrink-0 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
              <h2 className="text-[12px] font-black uppercase tracking-wide text-white">
                Product Price Matrix
              </h2>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={loading}
                  onClick={() => loadPriceList(1)}
                  className="h-7 px-3 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {loading ? "Loading" : "Refresh"}
                </button>

                <button
                  type="button"
                  disabled={loading}
                  onClick={downloadExcelPriceList}
                  className="h-7 px-3 bg-[#16A34A] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  Excel
                </button>

                <button
                  type="button"
                  disabled={loading}
                  onClick={downloadPdfPriceList}
                  className="h-7 px-3 bg-[#DC2626] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  PDF
                </button>

                <button
                  type="button"
                  onClick={onOpenAdjustment}
                  className="h-7 px-3 bg-white/10 text-white text-[10px] font-black uppercase"
                >
                  Adjust Prices
                </button>

                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search product, sku, barcode..."
                  className="h-7 w-[260px] border border-white/20 bg-[#003637] px-2 text-[11px] text-white outline-none placeholder:text-white/50"
                />
              </div>
            </div>

            <div className="shrink-0 border-b border-[#D7DFDE] bg-[#F8FAFC] p-2 grid grid-cols-1 md:grid-cols-3 xl:grid-cols-5 gap-2">
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
              </select>

              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="input-small"
              >
                <option value="">All Status</option>
                <option value="true">Active</option>
                <option value="false">Inactive</option>
              </select>

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

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase min-w-[130px]">
                      Barcode
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
                        colSpan={7 + activePriceLevels.length}
                        className="px-3 py-8 text-center text-[12px] text-[#637877]"
                      >
                        Loading product prices...
                      </td>
                    </tr>
                  ) : products.length === 0 ? (
                    <tr>
                      <td
                        colSpan={7 + activePriceLevels.length}
                        className="px-3 py-8 text-center text-[12px] text-[#637877]"
                      >
                        No products found.
                      </td>
                    </tr>
                  ) : (
                    products.map((product) => (
                      <tr
                        key={product.id}
                        className="border-b border-[#EFF2F2] hover:bg-[#F8FAFC]"
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
                          {product.barcode || "-"}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {product?.category?.name || "-"}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {product.product_type || "-"}
                        </td>

                        {activePriceLevels.map((level) => {
                          const price = getPriceForLevel(product, level.id);

                          return (
                            <td
                              key={`${product.id}-${level.id}`}
                              className="px-3 py-2 text-[11px]"
                              title={price ? [getBranchDisplay(price), price.is_default ? "Default" : null].filter(Boolean).join(" · ") : undefined}
                            >
                              {price ? (
                                <span className="font-black text-[#003636]">{formatPrice(price)}</span>
                              ) : (
                                <span className="text-[#93A6A6]">-</span>
                              )}
                            </td>
                          );
                        })}

                        <td className="px-3 py-2">
                          <button
                            type="button"
                            onClick={() => onViewHistory?.(product)}
                            className="h-7 px-3 bg-[#004142] text-white text-[10px] font-black uppercase"
                          >
                            History
                          </button>
                        </td>
                      </tr>
                    ))
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
                  Showing {pagination.from || 0}-{pagination.to || 0} of{" "}
                  {pagination.total || 0} / Page{" "}
                  {pagination.current_page || page} of{" "}
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

      .input-small:focus {
        border-color: #00BCBC;
        box-shadow: inset 0 0 0 1px #00BCBC;
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

export default ProductPriceListModal;
