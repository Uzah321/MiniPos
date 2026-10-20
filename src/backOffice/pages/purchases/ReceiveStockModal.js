// src/backOffice/pages/purchases/ReceiveStockModal.js

import React, {
  useEffect,
  useMemo,
  useState,
  useCallback,
  useRef,
} from "react";

import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import {
  getProducts,
  getProduct,
  receiveProductStock,
} from "../../../services/ProductService";

import { getBaseCurrencies } from "../../../services/CurrencyService";
import { getInventoryWarehouses } from "../../../services/EnterpriseInventoryService";
import { downloadGoodsReceivedNote } from "../../../services/PurchaseService";

// ============================================================================
// Helper Functions
// ============================================================================

function normalizeRows(response) {
  if (Array.isArray(response)) return response;

  const payload = response?.data;

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(response?.products)) return response.products;
  if (Array.isArray(payload?.products)) return payload.products;
  if (Array.isArray(response?.currencies)) return response.currencies;

  return [];
}

function resolveProductDetail(response) {
  return response?.data || response?.product || response || null;
}

function getApiMessage(error, fallback = "Request failed.") {
  const responseData = error?.response?.data;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.data && typeof responseData.data === "object") {
    const values = Object.values(responseData.data).flat();
    if (values.length) return values.join("\n");
  }

  if (responseData?.error) return responseData.error;
  if (responseData?.message) return responseData.message;
  if (error?.data?.error) return error.data.error;
  if (error?.data?.message) return error.data.message;
  if (error?.message && !error?.isAxiosError) return error.message;

  return fallback;
}

function formatQty(value) {
  if (value === null || value === undefined || value === "") return "-";

  const num = Number(value);
  return Number.isNaN(num) ? "-" : num.toFixed(4);
}

function formatMoney(value, currency = "") {
  if (value === null || value === undefined || value === "") return "-";

  const num = Number(value);
  if (Number.isNaN(num)) return "-";

  const cur = currency ? `${currency} ` : "";
  return `${cur}${num.toFixed(4)}`.trim();
}

function formatDateTime(value) {
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
}

function getUomDisplay(product) {
  if (!product?.uom) return "-";

  const code = product.uom.code ? ` (${product.uom.code})` : "";
  return `${product.uom.name || "-"}${code}`;
}

function getProductBarcode(product) {
  if (product?.barcode) return product.barcode;

  const barcodes = Array.isArray(product?.barcodes) ? product.barcodes : [];
  const primary =
    barcodes.find((barcode) => barcode.type === "primary") || barcodes[0];

  return primary?.barcode || "-";
}


function normalizeProductType(value) {
  return String(value || "").trim().toLowerCase().replace(/[\s-]+/g, "_");
}

function isReceiveStockProduct(product) {
  const type = normalizeProductType(product?.product_type || product?.productType);

  // Finished goods are menu/sales output items. They must not be received directly here.
  if (type === "finished_good") return false;

  return true;
}

// ============================================================================
// Branch Resolver Hooks
// ============================================================================

function useBranchResolver(currentBranchId) {
  return useMemo(() => {
    if (currentBranchId) return currentBranchId;

    const branchKeys = [
      "back_user",
      "user",
      "authUser",
      "adminUser",
      "pos_user",
      "salexa_user",
      "currentUser",
    ];

    for (const key of branchKeys) {
      try {
        const raw = localStorage.getItem(key);
        if (!raw) continue;

        const parsed = JSON.parse(raw);

        if (parsed?.branch_id) return parsed.branch_id;
        if (parsed?.branch?.id) return parsed.branch.id;
        if (parsed?.data?.branch_id) return parsed.data.branch_id;
        if (parsed?.data?.branch?.id) return parsed.data.branch.id;
      } catch {
        // Ignore invalid localStorage data.
      }
    }

    return "";
  }, [currentBranchId]);
}

function useBranchNameResolver(currentBranchName) {
  return useMemo(() => {
    if (currentBranchName) return currentBranchName;

    const branchKeys = [
      "back_user",
      "user",
      "authUser",
      "adminUser",
      "pos_user",
      "salexa_user",
      "currentUser",
    ];

    for (const key of branchKeys) {
      try {
        const raw = localStorage.getItem(key);
        if (!raw) continue;

        const parsed = JSON.parse(raw);

        if (parsed?.branch?.branch_name) return parsed.branch.branch_name;
        if (parsed?.branch?.name) return parsed.branch.name;
        if (parsed?.data?.branch?.branch_name) {
          return parsed.data.branch.branch_name;
        }
        if (parsed?.data?.branch?.name) return parsed.data.branch.name;
      } catch {
        // Ignore invalid localStorage data.
      }
    }

    return "Current Branch";
  }, [currentBranchName]);
}

// ============================================================================
// Data Hooks
// ============================================================================

function useBaseCurrencies(open) {
  const [currencies, setCurrencies] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;

    let mounted = true;

    const fetchCurrencies = async () => {
      try {
        setLoading(true);
        setError("");

        const response = await getBaseCurrencies({
          is_active: true,
          is_base_currency: true,
          per_page: 200,
        });

        const rows = normalizeRows(response);

        if (mounted) {
          setCurrencies(rows);
        }
      } catch (err) {
        if (mounted) {
          setError(getApiMessage(err));
        }
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    };

    fetchCurrencies();

    return () => {
      mounted = false;
    };
  }, [open]);

  const defaultCurrencyCode = useMemo(() => {
    return currencies?.[0]?.code || "";
  }, [currencies]);

  return {
    currencies,
    loading,
    error,
    defaultCurrencyCode,
  };
}

function useProducts(open, searchTerm) {
  const [products, setProducts] = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(false);
  const [selectedProduct, setSelectedProduct] = useState(null);
  const [loadingProductDetail, setLoadingProductDetail] = useState(false);

  const debounceRef = useRef(null);

  const loadProducts = useCallback(async (keyword = "") => {
    try {
      setLoadingProducts(true);

      const response = await getProducts({
        q: keyword || undefined,
        per_page: 100,
      });

      const rows = normalizeRows(response).filter(isReceiveStockProduct);
      setProducts(rows);

      return rows;
    } finally {
      setLoadingProducts(false);
    }
  }, []);

  const fetchProductDetail = useCallback(async (product) => {
    if (!product?.id) return product;

    try {
      setLoadingProductDetail(true);

      const response = await getProduct(product.id);
      return resolveProductDetail(response);
    } catch {
      return product;
    } finally {
      setLoadingProductDetail(false);
    }
  }, []);

  const selectProduct = useCallback(
    async (product) => {
      const detail = await fetchProductDetail(product);
      setSelectedProduct(detail);
    },
    [fetchProductDetail]
  );

  const clearSelectedProduct = useCallback(() => {
    setSelectedProduct(null);
  }, []);

  useEffect(() => {
    if (!open) return;

    loadProducts("").catch(() => {});
    setSelectedProduct(null);
  }, [open, loadProducts]);

  useEffect(() => {
    if (!open) return;

    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
    }

    debounceRef.current = setTimeout(() => {
      loadProducts(searchTerm.trim()).catch(() => {});
    }, 300);

    return () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current);
      }
    };
  }, [open, searchTerm, loadProducts]);

  return {
    products,
    loadingProducts,
    selectedProduct,
    loadingProductDetail,
    selectProduct,
    clearSelectedProduct,
  };
}

function useBranchProductData(selectedProduct, resolvedBranchId) {
  const branchStock = useMemo(() => {
    const stocks = Array.isArray(selectedProduct?.stocks)
      ? selectedProduct.stocks
      : [];

    if (resolvedBranchId) {
      const matched = stocks.find(
        (stock) => String(stock.branch_id) === String(resolvedBranchId)
      );

      if (matched) return matched;
    }

    return stocks[0] || null;
  }, [selectedProduct, resolvedBranchId]);

  const branchCost = useMemo(() => {
    const costs = Array.isArray(selectedProduct?.costs)
      ? selectedProduct.costs
      : [];

    if (resolvedBranchId) {
      const matched = costs.find(
        (cost) => String(cost.branch_id) === String(resolvedBranchId)
      );

      if (matched) return matched;
    }

    return costs[0] || null;
  }, [selectedProduct, resolvedBranchId]);

  const branchPrices = useMemo(() => {
    const prices = Array.isArray(selectedProduct?.prices)
      ? selectedProduct.prices
      : [];

    if (!resolvedBranchId) return prices;

    const branchSpecific = prices.filter(
      (price) => String(price.branch_id) === String(resolvedBranchId)
    );

    const companyLevel = prices.filter((price) => !price.branch_id);

    return [...branchSpecific, ...companyLevel];
  }, [selectedProduct, resolvedBranchId]);

  const defaultSellingPrice = useMemo(() => {
    if (!branchPrices.length) return null;

    return (
      branchPrices.find((price) => price.is_default && price.is_active) ||
      branchPrices.find((price) => price.is_active) ||
      branchPrices[0]
    );
  }, [branchPrices]);

  return {
    branchStock,
    branchCost,
    branchPrices,
    defaultSellingPrice,
  };
}

function useRecentReceipts() {
  const [receipts, setReceipts] = useState([]);

  const addReceipt = useCallback((receipt) => {
    setReceipts((prev) => [receipt, ...prev].slice(0, 50));
  }, []);

  const clearReceipts = useCallback(() => {
    setReceipts([]);
  }, []);

  return {
    receipts,
    addReceipt,
    clearReceipts,
  };
}

function useReceiveStockForm({
  selectedProduct,
  branchCost,
  defaultSellingPrice,
  defaultCurrencyCode,
  currencies,
  warehouses,
  resolvedBranchId,
  resolvedBranchName,
  onSuccess,
  showError,
  showSuccess,
}) {
  const receiptGroupKeyRef = useRef(
    `quick-${resolvedBranchId || "branch"}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  );
  const [formData, setFormData] = useState({
    quantity: "",
    unit_cost: "",
    currency: "",
    warehouse_id: "",
    lot_number: "",
    manufactured_at: "",
    expires_at: "",
    notes: "",
  });

  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!selectedProduct) return;

    setFormData((prev) => ({
      ...prev,
      currency:
        prev.currency ||
        branchCost?.currency ||
        defaultSellingPrice?.currency ||
        defaultCurrencyCode ||
        "",
      unit_cost:
        prev.unit_cost ||
        branchCost?.last_cost?.toString() ||
        branchCost?.average_cost?.toString() ||
        "",
      warehouse_id:
        prev.warehouse_id || (warehouses.length === 1 ? String(warehouses[0].id) : ""),
    }));
  }, [
    selectedProduct,
    branchCost,
    defaultSellingPrice,
    defaultCurrencyCode,
    warehouses,
  ]);

  const handleChange = useCallback((e) => {
    const { name, value } = e.target;

    setFormData((prev) => ({
      ...prev,
      [name]: value,
    }));
  }, []);

  const clearForm = useCallback(() => {
    setFormData((prev) => ({
      quantity: "",
      unit_cost: "",
      currency: prev.currency || defaultCurrencyCode || "",
      warehouse_id: prev.warehouse_id || "",
      lot_number: "",
      manufactured_at: "",
      expires_at: "",
      notes: "",
    }));
  }, [defaultCurrencyCode]);

  const validate = useCallback(() => {
    if (!resolvedBranchId) {
      showError(
        "Branch Missing",
        "Current logged-in branch could not be resolved."
      );
      return false;
    }

    if (!selectedProduct?.id) {
      showError("Validation Error", "Please select a product.");
      return false;
    }

    if (!isReceiveStockProduct(selectedProduct)) {
      showError(
        "Invalid Product",
        "Finished goods cannot be received directly. Receive raw materials, packaging, consumables or semi-finished stock items only."
      );
      return false;
    }

    if (!currencies.length) {
      showError(
        "Currency Missing",
        "No active base currency was found. Please configure currency first."
      );
      return false;
    }

    if (!formData.currency) {
      showError("Validation Error", "Please select a currency.");
      return false;
    }

    if (!formData.warehouse_id) {
      showError("Validation Error", "Please select the warehouse receiving this stock.");
      return false;
    }

    if (selectedProduct?.batch_tracking_enabled && !formData.lot_number.trim()) {
      showError("Validation Error", "A lot number is required for this batch-tracked product.");
      return false;
    }

    if (selectedProduct?.expiry_tracking_enabled && !formData.expires_at) {
      showError("Validation Error", "An expiry date is required for this expiry-tracked product.");
      return false;
    }

    const currencyExists = currencies.some(
      (currency) => currency.code === formData.currency
    );

    if (!currencyExists) {
      showError(
        "Invalid Currency",
        "Selected currency is not an active base currency."
      );
      return false;
    }

    const quantityNum = Number(formData.quantity);

    if (!formData.quantity || quantityNum <= 0) {
      showError("Validation Error", "Quantity must be greater than 0.");
      return false;
    }

    const costNum = Number(formData.unit_cost);

    if (formData.unit_cost === "" || costNum < 0) {
      showError("Validation Error", "Unit cost must be 0 or greater.");
      return false;
    }

    return true;
  }, [
    resolvedBranchId,
    selectedProduct,
    currencies,
    formData,
    showError,
  ]);

  const estimatedTotalCost = useMemo(() => {
    if (!formData.quantity || formData.unit_cost === "") return "";

    return Number(formData.quantity) * Number(formData.unit_cost);
  }, [formData.quantity, formData.unit_cost]);

  const save = useCallback(async () => {
    if (!validate()) return;

    try {
      setSaving(true);

      const payload = {
        branch_id: resolvedBranchId,
        quantity: Number(formData.quantity),
        unit_cost: Number(formData.unit_cost),
        currency: formData.currency,
        warehouse_id: formData.warehouse_id,
        lot_number: formData.lot_number || null,
        manufactured_at: formData.manufactured_at || null,
        expires_at: formData.expires_at || null,
        notes: formData.notes || null,
        receipt_group_key: receiptGroupKeyRef.current,
      };

      const response = await receiveProductStock(selectedProduct.id, payload);

      showSuccess(
        "Stock Received",
        response?.message || "Stock received successfully."
      );

      onSuccess(response, {
        ...formData,
        branch_name: resolvedBranchName,
      });

      clearForm();
    } catch (error) {
      showError("Receive Stock Failed", getApiMessage(error));
    } finally {
      setSaving(false);
    }
  }, [
    validate,
    resolvedBranchId,
    resolvedBranchName,
    formData,
    selectedProduct,
    showSuccess,
    showError,
    onSuccess,
    clearForm,
  ]);

  return {
    formData,
    saving,
    handleChange,
    clearForm,
    save,
    estimatedTotalCost,
  };
}

// ============================================================================
// Main Component
// ============================================================================

const ReceiveStockModal = ({
  open,
  onClose,
  onSaved,
  currentBranchId = "",
  currentBranchName = "",
}) => {
  const resolvedBranchId = useBranchResolver(currentBranchId);
  const resolvedBranchName = useBranchNameResolver(currentBranchName);
  const [warehouses, setWarehouses] = useState([]);
  const [warehousesLoading, setWarehousesLoading] = useState(false);
  const [warehousesError, setWarehousesError] = useState("");

  useEffect(() => {
    if (!open) return;
    let mounted = true;

    setWarehousesLoading(true);
    setWarehousesError("");
    getInventoryWarehouses()
      .then((response) => {
        const rows = normalizeRows(response);
        if (!mounted) return;
        setWarehouses(rows.filter((warehouse) => warehouse?.is_active !== false && (
          !resolvedBranchId ||
          !warehouse?.branch_id ||
          String(warehouse.branch_id) === String(resolvedBranchId)
        )));
      })
      .catch((error) => {
        if (!mounted) return;
        setWarehouses([]);
        setWarehousesError(getApiMessage(error, "Warehouses could not be loaded."));
      })
      .finally(() => {
        if (mounted) setWarehousesLoading(false);
      });

    return () => {
      mounted = false;
    };
  }, [open, resolvedBranchId]);

  const [searchTerm, setSearchTerm] = useState("");
  const [productPickerOpen, setProductPickerOpen] = useState(false);

  const {
    currencies,
    loading: currenciesLoading,
    error: currenciesError,
    defaultCurrencyCode,
  } = useBaseCurrencies(open);

  const {
    products,
    loadingProducts,
    selectedProduct,
    loadingProductDetail,
    selectProduct,
    clearSelectedProduct,
  } = useProducts(open, searchTerm);

  const {
    branchStock,
    branchCost,
    branchPrices,
    defaultSellingPrice,
  } = useBranchProductData(selectedProduct, resolvedBranchId);

  const {
    receipts: recentReceipts,
    addReceipt,
    clearReceipts,
  } = useRecentReceipts();

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });
  const [downloadingGrv, setDownloadingGrv] = useState(false);

  const showMessage = useCallback(
    ({ type = "success", title = "", message = "" }) => {
      setMessageModal({
        open: true,
        type,
        title,
        message,
      });
    },
    []
  );

  const closeMessageModal = useCallback(() => {
    setMessageModal((prev) => ({
      ...prev,
      open: false,
    }));
  }, []);

  const handleReceiptSuccess = useCallback(
    (response, formData) => {
      if (!selectedProduct) return;

      const savedCurrency = response?.currency || formData.currency;
      const purchaseReceiptId = response?.goods_received?.id || null;

      const recentRow = {
        id: `${purchaseReceiptId || "pending"}-${selectedProduct.id}-${Date.now()}`,
        purchase_receipt_id: purchaseReceiptId,
        grn_number: response?.goods_received?.grn_number || "Pending GRN",
        received_at: new Date().toISOString(),
        sku: selectedProduct?.sku || "-",
        code: selectedProduct?.code || "-",
        barcode: getProductBarcode(selectedProduct),
        product_name: selectedProduct?.name || "-",
        uom: getUomDisplay(selectedProduct),
        quantity: Number(formData.quantity),
        unit_cost: Number(formData.unit_cost),
        currency: savedCurrency,
        selling_price: defaultSellingPrice?.price || "",
        selling_currency: defaultSellingPrice?.currency || savedCurrency,
        notes: formData.notes || "-",
        branch_name: resolvedBranchName,
      };

      addReceipt(recentRow);

      if (typeof onSaved === "function") {
        onSaved(response);
      }
    },
    [
      selectedProduct,
      defaultSellingPrice,
      resolvedBranchName,
      addReceipt,
      onSaved,
    ]
  );

  const {
    formData,
    saving,
    handleChange,
    clearForm,
    save,
    estimatedTotalCost,
  } = useReceiveStockForm({
    selectedProduct,
    branchCost,
    defaultSellingPrice,
    defaultCurrencyCode,
    currencies,
    warehouses,
    resolvedBranchId,
    resolvedBranchName,
    onSuccess: handleReceiptSuccess,
    showError: (title, message) =>
      showMessage({ type: "error", title, message }),
    showSuccess: (title, message) =>
      showMessage({ type: "success", title, message }),
  });

  const handleClose = useCallback(() => {
    setSearchTerm("");
    setProductPickerOpen(false);
    clearSelectedProduct();
    onClose();
  }, [onClose, clearSelectedProduct]);

  const handleProductSelect = async (product) => {
    await selectProduct(product);
    setProductPickerOpen(false);
  };

  useEffect(() => {
    if (currenciesError && open) {
      showMessage({
        type: "error",
        title: "Currency Load Failed",
        message: currenciesError,
      });
    }
  }, [currenciesError, open, showMessage]);

  const productStats = useMemo(() => {
    return {
      onHand: branchStock?.quantity_on_hand ?? 0,
      available: branchStock?.available_quantity ?? 0,
      reserved: branchStock?.reserved_quantity ?? 0,
      lastCost: branchCost?.last_cost ?? "",
      averageCost: branchCost?.average_cost ?? "",
    };
  }, [branchStock, branchCost]);

  const downloadGrvPdf = useCallback(async () => {
    if (!recentReceipts.length) {
      showMessage({
        type: "error",
        title: "No GRV Rows",
        message: "Save received stock first. The GRV uses the rows in the recently added stock table.",
      });
      return;
    }

    const purchaseReceiptId = recentReceipts.find((row) => row.purchase_receipt_id)?.purchase_receipt_id;
    if (!purchaseReceiptId) {
      showMessage({
        type: "error",
        title: "GRV Not Available",
        message: "The server did not return a goods-received voucher number. Receive the stock again, then retry the download.",
      });
      return;
    }

    try {
      setDownloadingGrv(true);
      await downloadGoodsReceivedNote(purchaseReceiptId);
    } catch (error) {
      showMessage({
        type: "error",
        title: "GRV Download Failed",
        message: getApiMessage(error, "The backend could not generate the goods received voucher."),
      });
    } finally {
      setDownloadingGrv(false);
    }
  }, [recentReceipts, showMessage]);

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={handleClose}
        title="QUICK RECEIVE"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="rx-shell">
          {/* TOP BAR */}
          <div className="rx-topbar">
            <div>
              <h1>Quick Receive</h1>
              <p>{resolvedBranchName} · Goods receipt</p>
            </div>

            <div className="rx-top-actions">
              <button
                type="button"
                onClick={downloadGrvPdf}
                disabled={recentReceipts.length === 0 || downloadingGrv}
                className="rx-btn"
              >
                {downloadingGrv ? "Downloading..." : "Download GRV"}
              </button>

              <button
                type="button"
                onClick={clearForm}
                disabled={saving}
                className="rx-btn"
              >
                Clear
              </button>

              <button
                type="button"
                onClick={save}
                disabled={saving || !selectedProduct}
                className="rx-btn rx-btn-primary"
              >
                {saving ? "Posting..." : "Post Receipt"}
              </button>
            </div>
          </div>

          {/* PRODUCT SEARCH */}
          <div className="rx-searchbar">
            <div className="rx-search-wrap">
              <label>Product</label>

              <div className="rx-search-input-row">
                <input
                  type="text"
                  value={searchTerm}
                  onChange={(e) => {
                    setSearchTerm(e.target.value);
                    setProductPickerOpen(true);
                  }}
                  onFocus={() => setProductPickerOpen(true)}
                  placeholder="Search product, SKU, code or barcode"
                />

                <button
                  type="button"
                  onClick={() => setProductPickerOpen((prev) => !prev)}
                >
                  {productPickerOpen ? "Close" : "Browse"}
                </button>
              </div>

              {productPickerOpen && (
                <div className="rx-picker">
                  <div className="rx-picker-head">
                    {loadingProducts
                      ? "Loading products..."
                      : `${products.length} receivable stock items found`}
                  </div>

                  <div className="rx-picker-list">
                    {loadingProducts ? (
                      <div className="rx-picker-empty">Loading products...</div>
                    ) : products.length === 0 ? (
                      <div className="rx-picker-empty">No receivable stock items found.</div>
                    ) : (
                      products.map((product) => {
                        const selected =
                          selectedProduct?.id &&
                          String(selectedProduct.id) === String(product.id);

                        return (
                          <button
                            key={product.id}
                            type="button"
                            onClick={() => handleProductSelect(product)}
                            className={`rx-picker-row ${
                              selected ? "rx-picker-row-active" : ""
                            }`}
                          >
                            <div>
                              <strong>{product?.name || "-"}</strong>
                              <span>
                                SKU: {product?.sku || "-"} · Code:{" "}
                                {product?.code || "-"} · Barcode:{" "}
                                {product?.barcode || "-"}
                              </span>
                            </div>

                            <em>{product?.product_type || "product"}</em>
                          </button>
                        );
                      })
                    )}
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* COMPACT ENTRY AREA */}
          <div className="rx-entry-grid">
            {/* PRODUCT SUMMARY */}
            <section className="rx-panel">
              <div className="rx-panel-head">
                <h2>Selected Product</h2>
                <span>{loadingProductDetail ? "Loading..." : "Ready"}</span>
              </div>

              {!selectedProduct ? (
                <div className="rx-empty-small">
                  Select a product to receive stock.
                </div>
              ) : (
                <div className="rx-product-summary">
                  <div className="rx-product-main">
                    <strong>{selectedProduct?.name || "-"}</strong>
                    <span>
                      SKU: {selectedProduct?.sku || "-"} · Code:{" "}
                      {selectedProduct?.code || "-"} · Barcode:{" "}
                      {getProductBarcode(selectedProduct)}
                    </span>
                  </div>

                  <div className="rx-metrics">
                    <RxMetric label="UOM" value={getUomDisplay(selectedProduct)} />
                    <RxMetric
                      label="On Hand"
                      value={formatQty(productStats.onHand)}
                    />
                    <RxMetric
                      label="Available"
                      value={formatQty(productStats.available)}
                    />
                    <RxMetric
                      label="Reserved"
                      value={formatQty(productStats.reserved)}
                    />
                    <RxMetric
                      label="Last Cost"
                      value={formatMoney(
                        productStats.lastCost,
                        branchCost?.currency || formData.currency
                      )}
                    />
                    <RxMetric
                      label="Selling"
                      value={
                        defaultSellingPrice
                          ? formatMoney(
                              defaultSellingPrice.price,
                              defaultSellingPrice.currency
                            )
                          : "-"
                      }
                    />
                  </div>
                </div>
              )}
            </section>

            {/* RECEIVE FORM */}
            <section className="rx-panel">
              <div className="rx-panel-head">
                <h2>Receive Entry</h2>
                <span>Required</span>
              </div>

              <div className="rx-form-grid">
                <RxField label="Quantity">
                  <input
                    type="number"
                    step="0.0001"
                    name="quantity"
                    value={formData.quantity}
                    onChange={handleChange}
                    placeholder="0.0000"
                  />
                </RxField>

                <RxField label="Unit Cost">
                  <input
                    type="number"
                    step="0.0001"
                    name="unit_cost"
                    value={formData.unit_cost}
                    onChange={handleChange}
                    placeholder="0.0000"
                  />
                </RxField>

                <RxField label="Currency">
                  <select
                    name="currency"
                    value={formData.currency}
                    onChange={handleChange}
                    disabled={currenciesLoading}
                  >
                    <option value="">
                      {currenciesLoading ? "Loading..." : "Select"}
                    </option>

                    {currencies.map((currency) => (
                      <option
                        key={currency.id || currency.code}
                        value={currency.code}
                      >
                        {currency.code} - {currency.name}
                      </option>
                    ))}
                  </select>
                </RxField>

                <RxField label="Receive Into Warehouse">
                  <select
                    name="warehouse_id"
                    value={formData.warehouse_id}
                    onChange={handleChange}
                    disabled={warehousesLoading}
                  >
                    <option value="">
                      {warehousesLoading
                        ? "Loading warehouses..."
                        : warehousesError
                          ? "Warehouses unavailable"
                          : warehouses.length
                            ? "Select warehouse"
                            : "No warehouse configured for this branch"}
                    </option>
                    {warehouses.map((warehouse) => (
                      <option key={warehouse.id} value={warehouse.id}>
                        {warehouse.branch_name || (warehouse.branch_id ? "Branch warehouse" : "Company-wide")} — {warehouse.name}{warehouse.code ? ` (${warehouse.code})` : ""}
                      </option>
                    ))}
                  </select>
                  {warehousesError && <div className="rx-field-error" role="alert">{warehousesError}</div>}
                </RxField>

                {(selectedProduct?.batch_tracking_enabled || selectedProduct?.expiry_tracking_enabled) && (
                  <RxField label="Lot Number">
                    <input
                      type="text"
                      name="lot_number"
                      value={formData.lot_number}
                      onChange={handleChange}
                      placeholder={selectedProduct?.batch_tracking_enabled ? "Required" : "Optional"}
                    />
                  </RxField>
                )}

                {(selectedProduct?.batch_tracking_enabled || selectedProduct?.expiry_tracking_enabled) && (
                  <RxField label="Manufactured Date">
                    <input type="date" name="manufactured_at" value={formData.manufactured_at} onChange={handleChange} />
                  </RxField>
                )}

                {selectedProduct?.expiry_tracking_enabled && (
                  <RxField label="Expiry Date">
                    <input type="date" name="expires_at" value={formData.expires_at} onChange={handleChange} />
                  </RxField>
                )}

                <RxField label="Notes">
                  <input
                    type="text"
                    name="notes"
                    value={formData.notes}
                    onChange={handleChange}
                    placeholder="Optional"
                  />
                </RxField>
              </div>

              <div className="rx-total-line">
                <span>Total Cost</span>
                <strong>
                  {estimatedTotalCost !== ""
                    ? formatMoney(estimatedTotalCost, formData.currency)
                    : "-"}
                </strong>
              </div>
            </section>
          </div>

          {/* RECENT TABLE */}
          <section className="rx-recent">
            <div className="rx-recent-head">
              <div>
                <h2>Recently Added Stock</h2>
                <p>{recentReceipts.length} row(s) in this session</p>
              </div>

              <div className="rx-recent-actions">
                <button
                  type="button"
                  onClick={downloadGrvPdf}
                  disabled={recentReceipts.length === 0 || downloadingGrv}
                  className="rx-btn rx-btn-primary"
                >
                  {downloadingGrv ? "Downloading..." : "Download GRV PDF"}
                </button>

                <button
                  type="button"
                  onClick={clearReceipts}
                  disabled={recentReceipts.length === 0}
                  className="rx-btn"
                >
                  Clear Table
                </button>
              </div>
            </div>

            <div className="rx-table-wrap">
              <table className="rx-table">
                <thead>
                  <tr>
                    <th>GRN Voucher</th>
                    <th>Time</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Code</th>
                    <th>Barcode</th>
                    <th>UOM</th>
                    <th>Qty</th>
                    <th>Unit Cost</th>
                    <th>Total</th>
                    <th>Currency</th>
                    <th>Notes</th>
                  </tr>
                </thead>

                <tbody>
                  {recentReceipts.length === 0 ? (
                    <tr>
                      <td colSpan={12} className="rx-empty-table">
                        Recently added stock will appear here after saving.
                      </td>
                    </tr>
                  ) : (
                    recentReceipts.map((row) => (
                      <tr key={row.id}>
                        <td className="rx-strong">{row.grn_number}</td>
                        <td>{formatDateTime(row.received_at)}</td>
                        <td className="rx-strong">{row.product_name}</td>
                        <td>{row.sku}</td>
                        <td>{row.code}</td>
                        <td>{row.barcode}</td>
                        <td>{row.uom}</td>
                        <td className="rx-strong">{formatQty(row.quantity)}</td>
                        <td>{formatMoney(row.unit_cost, row.currency)}</td>
                        <td className="rx-money">
                          {formatMoney(
                            Number(row.quantity) * Number(row.unit_cost),
                            row.currency
                          )}
                        </td>
                        <td>{row.currency}</td>
                        <td>{row.notes || "-"}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <ReceiveStockCompactStyles />
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

// ============================================================================
// Small UI Components
// ============================================================================

const RxMetric = ({ label, value }) => (
  <div className="rx-metric">
    <span>{label}</span>
    <strong>{value ?? "-"}</strong>
  </div>
);

const RxField = ({ label, children }) => (
  <label className="rx-field">
    <span>{label}</span>
    {children}
  </label>
);

// ============================================================================
// Styles
// ============================================================================

const ReceiveStockCompactStyles = () => (
  <style>
    {`
      :root {
        --rx-navy: #283838;
        --rx-navy-deep: #1C2928;
        --rx-teal: #069494;
        --rx-teal-dark: #008585;
        --rx-border: #BCC7C7;
        --rx-soft: #F7FAFC;
        --rx-row: #EFF4F4;
        --rx-text: #2B3C3C;
        --rx-muted: #6D7D7D;
        --rx-danger: #B42318;
      }

      .rx-shell {
        height: calc(100vh - 86px);
        min-height: 0;
        background: #F4F6F8;
        padding: 8px;
        display: grid;
        grid-template-rows: auto auto minmax(154px, auto) minmax(0, 1fr);
        gap: 8px;
        overflow: hidden;
        color: var(--rx-text);
      }

      .rx-topbar {
        min-height: 56px;
        flex-shrink: 0;
        background: #FFFFFF;
        border: 1px solid var(--rx-border);
        border-bottom: 2px solid var(--rx-teal);
        padding: 8px 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: none;
      }

      .rx-topbar h1 {
        margin: 0;
        font-size: 15px;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--rx-text);
        line-height: 1.1;
      }

      .rx-topbar p {
        margin: 4px 0 0;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--rx-muted);
      }

      .rx-top-actions {
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .rx-searchbar {
        position: relative;
        z-index: 50;
        flex-shrink: 0;
        background: #FFFFFF;
        border: 1px solid var(--rx-border);
        border-left: 1px solid var(--rx-border);
        padding: 9px 10px;
        box-shadow: none;
      }

      .rx-search-wrap {
        position: relative;
        max-width: none;
      }

      .rx-search-wrap label {
        display: block;
        margin-bottom: 5px;
        font-size: 10px;
        font-weight: 600;
        color: var(--rx-navy);
        letter-spacing: 0.12em;
        text-transform: uppercase;
      }

      .rx-search-input-row {
        display: grid;
        grid-template-columns: 1fr 118px;
      }

      .rx-search-input-row input {
        height: 38px;
        border: 1px solid var(--rx-border);
        background: #F8FAFC;
        padding: 0 10px;
        font-size: 12px;
        font-weight: 600;
        color: var(--rx-text);
        outline: none;
      }

      .rx-search-input-row input:focus {
        border-color: var(--rx-teal);
        background: #FFFFFF;
        box-shadow: inset 0 0 0 1px rgba(0, 188, 188, 0.45);
      }

      .rx-search-input-row button {
        height: 38px;
        border: 1px solid var(--rx-navy);
        border-left: 0;
        background: #F7FAFC;
        padding: 0 12px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--rx-teal-dark);
      }

      .rx-search-input-row button:hover {
        background: var(--rx-navy-deep);
      }

      .rx-picker {
        position: absolute;
        left: 0;
        top: 66px;
        width: min(980px, calc(100vw - 40px));
        background: #FFFFFF;
        border: 1px solid var(--rx-border);
        box-shadow: 0 18px 44px rgba(0, 54, 54, 0.26);
      }

      .rx-picker-head {
        height: 34px;
        border-bottom: 1px solid var(--rx-border);
        background: var(--rx-navy);
        padding: 9px 11px;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #FFFFFF;
      }

      .rx-picker-list {
        max-height: 300px;
        overflow-y: auto;
      }

      .rx-picker-empty {
        padding: 24px;
        text-align: center;
        font-size: 12px;
        font-weight: 600;
        color: var(--rx-muted);
      }

      .rx-picker-row {
        width: 100%;
        border: 0;
        border-bottom: 1px solid #E2E9E9;
        background: #FFFFFF;
        padding: 9px 11px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 14px;
        text-align: left;
      }

      .rx-picker-row:hover {
        background: #F1F5F5;
      }

      .rx-picker-row-active {
        background: #E6FFFB;
        box-shadow: inset 4px 0 0 var(--rx-teal);
      }

      .rx-picker-row strong {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--rx-text);
      }

      .rx-picker-row span {
        display: block;
        margin-top: 3px;
        font-size: 10px;
        font-weight: 600;
        color: var(--rx-muted);
      }

      .rx-picker-row em {
        font-style: normal;
        font-size: 9px;
        font-weight: 600;
        letter-spacing: 0.08em;
        color: var(--rx-navy);
        background: #E6FFFB;
        border: 1px solid var(--rx-teal);
        padding: 4px 7px;
        text-transform: uppercase;
      }

      .rx-entry-grid {
        flex-shrink: 0;
        display: grid;
        grid-template-columns: minmax(0, 1.1fr) minmax(420px, 0.9fr);
        gap: 8px;
      }

      .rx-panel {
        background: #FFFFFF;
        border: 1px solid var(--rx-border);
        min-height: 0;
        box-shadow: none;
      }

      .rx-panel-head {
        height: 38px;
        border-bottom: 1px solid var(--rx-border);
        background: #F7FAFC;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
      }

      .rx-panel-head h2 {
        margin: 0;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--rx-text);
      }

      .rx-panel-head span {
        font-size: 9px;
        font-weight: 600;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--rx-teal);
      }

      .rx-empty-small {
        height: 145px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px dashed #CBD6D6;
        margin: 10px;
        background: #F8FAFC;
        font-size: 12px;
        font-weight: 600;
        color: var(--rx-muted);
      }

      .rx-product-summary {
        padding: 10px;
      }

      .rx-product-main {
        border: 1px solid #CBD6D6;
        border-left: 4px solid var(--rx-teal);
        background: #F8FAFC;
        padding: 9px 10px;
        margin-bottom: 9px;
      }

      .rx-product-main strong {
        display: block;
        font-size: 14px;
        font-weight: 600;
        letter-spacing: 0.02em;
        color: var(--rx-text);
      }

      .rx-product-main span {
        display: block;
        margin-top: 4px;
        font-size: 10.5px;
        font-weight: 600;
        color: var(--rx-muted);
      }

      .rx-metrics {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 8px;
      }

      .rx-metric {
        border: 1px solid #CBD6D6;
        background: #FFFFFF;
        padding: 8px;
        min-width: 0;
        box-shadow: none;
      }

      .rx-metric span {
        display: block;
        font-size: 9px;
        font-weight: 600;
        color: var(--rx-muted);
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
      }

      .rx-metric strong {
        display: block;
        margin-top: 5px;
        font-size: 13px;
        font-weight: 600;
        color: var(--rx-navy);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .rx-form-grid {
        padding: 10px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 9px;
      }

      .rx-field span {
        display: block;
        margin-bottom: 5px;
        font-size: 10px;
        font-weight: 600;
        color: var(--rx-navy);
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .rx-field input,
      .rx-field select {
        width: 100%;
        height: 38px;
        border: 1px solid var(--rx-border);
        background: #FFFFFF;
        padding: 0 10px;
        font-size: 12px;
        font-weight: 600;
        color: var(--rx-text);
        outline: none;
      }

      .rx-field input:focus,
      .rx-field select:focus {
        border-color: var(--rx-teal);
        box-shadow: inset 0 0 0 1px rgba(0, 188, 188, 0.45);
      }

      .rx-field .rx-field-error {
        margin-top: 5px;
        border-left: 3px solid #D92D20;
        background: #FEF3F2;
        padding: 5px 7px;
        font-size: 10px;
        font-weight: 600;
        line-height: 1.35;
        color: #B42318;
      }

      .rx-total-line {
        margin: 0 10px 10px;
        height: 44px;
        border: 1px solid var(--rx-teal);
        background: #F5FBFB;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
      }

      .rx-total-line span {
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--rx-teal-dark);
      }

      .rx-total-line strong {
        font-size: 15px;
        font-weight: 600;
        color: var(--rx-navy);
      }

      .rx-recent {
        flex: 1;
        min-height: 0;
        background: #FFFFFF;
        border: 1px solid var(--rx-border);
        display: flex;
        flex-direction: column;
        box-shadow: none;
      }

      .rx-recent-head {
        min-height: 48px;
        height: auto;
        flex-shrink: 0;
        border-bottom: 1px solid var(--rx-border);
        background: #F7FAFC;
        padding: 7px 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
      }

      .rx-recent-head h2 {
        margin: 0;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.09em;
        text-transform: uppercase;
        color: var(--rx-text);
      }

      .rx-recent-head p {
        margin: 3px 0 0;
        font-size: 9px;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--rx-muted);
      }

      .rx-table-wrap {
        flex: 1;
        min-height: 0;
        overflow: auto;
        background: #FFFFFF;
      }

      .rx-table {
        width: 100%;
        min-width: 1180px;
        border-collapse: collapse;
      }

      .rx-table thead {
        position: sticky;
        top: 0;
        z-index: 10;
      }

      .rx-table th {
        background: #EFF3F3;
        border-bottom: 1px solid var(--rx-border);
        border-right: 1px solid #D7DFDE;
        padding: 9px 8px;
        text-align: left;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        color: var(--rx-navy);
        white-space: nowrap;
      }

      .rx-table td {
        border-bottom: 1px solid #E6ECEC;
        border-right: 1px solid #EEF3F2;
        padding: 7px 8px;
        font-size: 10.5px;
        font-weight: 600;
        color: #324444;
        white-space: nowrap;
      }

      .rx-table tr:nth-child(even) td {
        background: #FBFCFE;
      }

      .rx-table tr:hover td {
        background: #E6FFFB;
      }

      .rx-strong {
        font-weight: 600;
        color: var(--rx-text) !important;
      }

      .rx-money {
        font-weight: 600;
        color: var(--rx-teal-dark) !important;
      }

      .rx-empty-table {
        height: 300px;
        text-align: center;
        color: var(--rx-muted) !important;
        font-size: 12px !important;
        font-weight: 900 !important;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        background: #F8FAFC !important;
      }

      .rx-recent-actions {
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .rx-btn {
        height: 34px;
        border: 1px solid var(--rx-border);
        background: #FFFFFF;
        color: var(--rx-navy);
        padding: 0 14px;
        font-size: 10.5px;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .rx-btn:hover:not(:disabled) {
        background: #E6ECEC;
      }

      .rx-topbar .rx-btn,
      .rx-recent-head .rx-btn {
        border-color: var(--rx-border);
        background: #FFFFFF;
        color: var(--rx-text);
      }

      .rx-topbar .rx-btn:hover:not(:disabled),
      .rx-recent-head .rx-btn:hover:not(:disabled) {
        background: #EEF7F6;
      }

      .rx-btn-primary {
        background: var(--rx-teal) !important;
        border-color: var(--rx-teal) !important;
        color: #FFFFFF !important;
      }

      .rx-btn-primary:hover:not(:disabled) {
        background: #0FD3D3 !important;
      }

      button,
      input,
      select,
      textarea,
      div,
      section,
      table {
        border-radius: 0 !important;
      }

      button:disabled,
      input:disabled,
      select:disabled {
        opacity: 0.55;
        cursor: not-allowed;
      }

      @media (max-width: 1280px) {
        .rx-shell {
          grid-template-rows: auto auto auto minmax(0, 1fr);
        }

        .rx-entry-grid {
          grid-template-columns: 1fr;
        }

        .rx-metrics {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }
      }

      @media (max-width: 768px) {
        .rx-shell {
          padding: 7px;
          gap: 7px;
        }

        .rx-topbar {
          height: auto;
          align-items: flex-start;
          flex-direction: column;
          gap: 8px;
        }

        .rx-top-actions {
          width: 100%;
          display: grid;
          grid-template-columns: 1fr 1fr;
        }

        .rx-search-wrap {
          max-width: none;
        }

        .rx-picker {
          width: calc(100vw - 28px);
        }

        .rx-search-input-row {
          grid-template-columns: 1fr 92px;
        }

        .rx-form-grid {
          grid-template-columns: 1fr;
        }

        .rx-metrics {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }
    `}
  </style>
);

export default ReceiveStockModal;
