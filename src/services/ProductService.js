import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const multipartHeaders = () => ({
  ...companyHeaders(),
  "Content-Type": "multipart/form-data",
});

// =============================
// PRODUCT CATEGORIES
// =============================

export const getProductCategories = async (params = {}) => {
  const res = await api.get("/api/pos/product-categories", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getProductCategory = async (categoryId) => {
  const res = await api.get(`/api/pos/product-categories/${categoryId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createProductCategory = async (payload) => {
  const res = await api.post("/api/pos/product-categories", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateProductCategory = async (categoryId, payload) => {
  const res = await api.put(
    `/api/pos/product-categories/${categoryId}`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const deleteProductCategory = async (categoryId) => {
  const res = await api.delete(`/api/pos/product-categories/${categoryId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const uploadProductCategoryImage = async (categoryId, file) => {
  const formData = new FormData();
  formData.append("image", file);

  const res = await api.post(
    `/api/pos/product-categories/${categoryId}/image`,
    formData,
    {
      headers: multipartHeaders(),
    }
  );

  return res.data;
};

export const deleteProductCategoryImage = async (categoryId) => {
  const res = await api.delete(`/api/pos/product-categories/${categoryId}/image`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const uploadProductCategoryBannerImage = async (categoryId, file) => {
  const formData = new FormData();
  formData.append("image", file);

  const res = await api.post(
    `/api/pos/product-categories/${categoryId}/banner-image`,
    formData,
    {
      headers: multipartHeaders(),
    }
  );

  return res.data;
};

export const deleteProductCategoryBannerImage = async (categoryId) => {
  const res = await api.delete(
    `/api/pos/product-categories/${categoryId}/banner-image`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

// =============================
// PRODUCT SUB CATEGORIES
// =============================

export const getProductSubCategories = async (params = {}) => {
  const res = await api.get("/api/pos/product-sub-categories", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getProductSubCategory = async (subCategoryId) => {
  const res = await api.get(`/api/pos/product-sub-categories/${subCategoryId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createProductSubCategory = async (payload) => {
  const res = await api.post("/api/pos/product-sub-categories", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateProductSubCategory = async (subCategoryId, payload) => {
  const res = await api.put(
    `/api/pos/product-sub-categories/${subCategoryId}`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const deleteProductSubCategory = async (subCategoryId) => {
  const res = await api.delete(
    `/api/pos/product-sub-categories/${subCategoryId}`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const uploadProductSubCategoryImage = async (subCategoryId, file) => {
  const formData = new FormData();
  formData.append("image", file);

  const res = await api.post(
    `/api/pos/product-sub-categories/${subCategoryId}/image`,
    formData,
    {
      headers: multipartHeaders(),
    }
  );

  return res.data;
};

export const deleteProductSubCategoryImage = async (subCategoryId) => {
  const res = await api.delete(
    `/api/pos/product-sub-categories/${subCategoryId}/image`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const uploadProductSubCategoryBannerImage = async (subCategoryId, file) => {
  const formData = new FormData();
  formData.append("image", file);

  const res = await api.post(
    `/api/pos/product-sub-categories/${subCategoryId}/banner-image`,
    formData,
    {
      headers: multipartHeaders(),
    }
  );

  return res.data;
};

export const deleteProductSubCategoryBannerImage = async (subCategoryId) => {
  const res = await api.delete(
    `/api/pos/product-sub-categories/${subCategoryId}/banner-image`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

// =============================
// SUPPLIERS
// =============================

export const getSuppliers = async (params = {}) => {
  const res = await api.get("/api/pos/suppliers", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getSupplier = async (supplierId) => {
  const res = await api.get(`/api/pos/suppliers/${supplierId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createSupplier = async (payload) => {
  const res = await api.post("/api/pos/suppliers", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateSupplier = async (supplierId, payload) => {
  const res = await api.put(`/api/pos/suppliers/${supplierId}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deleteSupplier = async (supplierId) => {
  const res = await api.delete(`/api/pos/suppliers/${supplierId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

// =============================
// PRODUCTS
// =============================

export const getProducts = async (params = {}) => {
  const res = await api.get("/api/pos/products", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const exportProducts = async (format, params = {}) => {
  return api.get(`/api/pos/products/export/${format}`, {
    params,
    headers: companyHeaders(),
    responseType: "blob",
  });
};

export const getProduct = async (id) => {
  const res = await api.get(`/api/pos/products/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getHsCodeSuggestions = async (description) => {
  const res = await api.get("/api/pos/hs-code/suggestions", {
    params: { description },
    headers: companyHeaders(),
  });

  return res.data;
};

export const approveProductHsCode = async (productId, payload) => {
  const res = await api.post(`/api/pos/products/${productId}/hs-code/approve`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createProduct = async (payload) => {
  const res = await api.post("/api/pos/products", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateProduct = async (id, payload) => {
  const res = await api.put(`/api/pos/products/${id}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deleteProduct = async (id) => {
  const res = await api.delete(`/api/pos/products/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const uploadProductImage = async (productId, file) => {
  const formData = new FormData();
  formData.append("image", file);

  const res = await api.post(`/api/pos/products/${productId}/image`, formData, {
    headers: multipartHeaders(),
  });

  return res.data;
};

export const deleteProductImage = async (productId) => {
  const res = await api.delete(`/api/pos/products/${productId}/image`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const generateProductIdentity = async (payload) => {
  const res = await api.post("/api/pos/products/generate-identity", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getUoms = async (params = {}) => {
  const res = await api.get("/api/pos/uoms", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

// =============================
// PRODUCT PRICES
// =============================

export const addProductPrice = async (productId, payload) => {
  const res = await api.post(`/api/pos/products/${productId}/prices`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deleteProductPrice = async (productId, priceId) => {
  const res = await api.delete(
    `/api/pos/products/${productId}/prices/${priceId}`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const getPriceLevels = async (params = {}) => {
  const res = await api.get("/api/pos/price-levels", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const createPriceLevel = async (payload) => {
  const res = await api.post("/api/pos/price-levels", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updatePriceLevel = async (priceLevelId, payload) => {
  const res = await api.put(`/api/pos/price-levels/${priceLevelId}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deletePriceLevel = async (priceLevelId) => {
  const res = await api.delete(`/api/pos/price-levels/${priceLevelId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getRolePriceLevels = async (roleId) => {
  const res = await api.get(`/api/pos/roles/${roleId}/price-levels`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const syncRolePriceLevels = async (roleId, priceLevelIds = []) => {
  const res = await api.put(
    `/api/pos/roles/${roleId}/price-levels/sync`,
    {
      price_level_ids: priceLevelIds,
    },
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const getVisibleProductPricesForCashier = async (
  productId,
  params = {}
) => {
  const res = await api.get(`/api/pos/products/${productId}/visible-prices`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getProductPriceList = async (params = {}) => {
  const res = await api.get("/api/pos/product-price-list", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const downloadProductPriceList = (format = "pdf", params = {}) => {
  const normalizedFormat = String(format || "pdf").toLowerCase() === "excel" ? "xlsx" : String(format || "pdf").toLowerCase();

  return downloadBackendReport({
    client: api,
    url: `/api/pos/product-price-list/download/${normalizedFormat}`,
    params,
    headers: companyHeaders(),
    fallbackName: `product-price-list.${normalizedFormat}`,
    extension: normalizedFormat,
    mimeType: normalizedFormat === "pdf"
      ? "application/pdf"
      : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};

export const adjustProductPrice = async (productId, payload) => {
  const res = await api.post(
    `/api/pos/products/${productId}/prices/adjust`,
    {
      branch_id: payload.branch_id || null,
      price_level_id: payload.price_level_id,
      price: payload.price,
      currency: payload.currency || "USD",
      is_default: payload.is_default ?? false,
      is_active: payload.is_active ?? true,
      starts_at: payload.starts_at || null,
      ends_at: payload.ends_at || null,
    },
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const getProductPriceAdjustmentHistory = async (
  productId,
  params = {}
) => {
  const res = await api.get(
    `/api/pos/products/${productId}/price-adjustments`,
    {
      params,
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const downloadProductPriceAdjustmentHistory = async (
  productId,
  format,
  params = {}
) => {
  const normalizedFormat = String(format || "xlsx").toLowerCase() === "excel"
    ? "xlsx"
    : String(format || "xlsx").toLowerCase();

  return downloadBackendReport({
    client: api,
    url: `/api/pos/products/${productId}/price-adjustments/export/${normalizedFormat}`,
    params,
    headers: companyHeaders(),
    fallbackName: `product-price-adjustment-history.${normalizedFormat}`,
    extension: normalizedFormat,
    mimeType: normalizedFormat === "pdf"
      ? "application/pdf"
      : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};

// =============================
// STOCK
// =============================

export const receiveProductStock = async (productId, payload) => {
  const res = await api.post(
    `/api/pos/products/${productId}/receive-stock`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const adjustProductStock = async (productId, payload) => {
  const res = await api.post(
    `/api/pos/products/${productId}/adjust-stock`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const adjustProductStockBulk = async (payload) => {
  const res = await api.post("/api/pos/products/adjust-stock-bulk", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockAdjustmentHistory = async (params = {}) => {
  const res = await api.get("/api/pos/stock-adjustment-history", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const downloadStockAdjustmentHistory = async (format, params = {}) => {
  const normalizedFormat = String(format || "xlsx").toLowerCase() === "excel"
    ? "xlsx"
    : String(format || "xlsx").toLowerCase();

  return downloadBackendReport({
    client: api,
    url: `/api/pos/stock-adjustment-history/export/${normalizedFormat}`,
    params,
    headers: companyHeaders(),
    fallbackName: `stock-adjustment-history.${normalizedFormat}`,
    extension: normalizedFormat,
    mimeType: normalizedFormat === "pdf"
      ? "application/pdf"
      : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};

export const breakProduct = async (childProductId, payload) => {
  const res = await api.post(
    `/api/pos/products/${childProductId}/break`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};
