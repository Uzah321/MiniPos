import api from "../utils/apiBack";
import { getLocalTillIdentity, isFrontOfficeWorkspace } from "../utils/apiConfig";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const unwrap = (response) => response?.data ?? response;

export const getMenuItems = async (params = {}) => {
  const productTypes =
    params.product_types || params.product_type || "finished_good,service";
  const localTill = getLocalTillIdentity();
  const shopId = params.shop_id
    || params.branch_id
    || (isFrontOfficeWorkspace() ? localTill.shop_id : null);
  const requestParams = {
    ...params,
    product_types: productTypes,
    ...(shopId ? { shop_id: shopId, branch_id: params.branch_id || shopId } : {}),
  };

  // The API gives precedence to product_types. Avoid sending a stale
  // product_type alongside it because that makes the type selector appear to
  // work while the server continues returning every menu-item type.
  delete requestParams.product_type;

  const res = await api.get("/api/pos/products", {
    params: requestParams,
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const createMenuItem = async (payload) => {
  const res = await api.post("/api/pos/products", payload, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const updateMenuItem = async (id, payload) => {
  const res = await api.put(`/api/pos/products/${id}`, payload, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const deleteMenuItem = async (id) => {
  const res = await api.delete(`/api/pos/products/${id}`, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const addMenuItemPrice = async (id, payload) => {
  const res = await api.post(`/api/pos/products/${id}/prices`, payload, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};


export const uploadMenuItemImage = async (id, file) => {
  const formData = new FormData();
  formData.append("image", file);

  const res = await api.post(`/api/pos/products/${id}/image`, formData, {
    headers: {
      ...companyHeaders(),
      "Content-Type": "multipart/form-data",
    },
  });

  return unwrap(res);
};

export const deleteMenuItemImage = async (id) => {
  const res = await api.delete(`/api/pos/products/${id}/image`, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const buildMenuItemPricePayload = ({
  shop_id = "",
  branch_id = "",
  price_level_id = "",
  price = "",
  currency = "USD",
}) => ({
  shop_id: shop_id || branch_id || null,
  branch_id: branch_id || shop_id || null,
  price_level_id,
  price: Number(price || 0),
  currency: currency || "USD",
  is_default: true,
  is_active: true,
  starts_at: null,
  ends_at: null,
});

export const buildMenuItemPayload = ({
  name,
  code = "",
  sku = "",
  barcode = "",
  description = "",
  category_id = "",
  supplier_id = "",
  base_uom_id = "",
  tax_id = "",
  price = "",
  selling_price = "",
  price_level_id = "",
  currency = "USD",
  branch_id = "",
  shop_id = "",
  product_type = "finished_good",
  stock_tracking_enabled = false,
  allow_negative_stock = false,
  allow_discount = true,
  taxable = false,
  is_active = true,
}) => {
  const actualPrice =
    price !== "" && price !== null && price !== undefined ? price : selling_price;

  const isService = product_type === "service";

  const payload = {
    // Hospitality menu items belong to the active operating branch. Keeping
    // them branch-scoped prevents a menu item created in Hospitality from
    // becoming a company-global Retail product.
    shop_id: shop_id || branch_id || null,
    branch_id: branch_id || shop_id || null,
    is_global: false,
    category_id: category_id || null,
    supplier_id: supplier_id || null,
    parent_product_id: null,
    base_uom_id,
    tax_id: tax_id || null,

    name: String(name || "").trim(),
    code: code || null,
    sku: sku || null,
    barcode: barcode || null,
    description: description || null,

    product_type: isService ? "service" : "finished_good",

    stock_tracking_enabled: isService ? false : !!stock_tracking_enabled,
    // In Hospitality this product-level flag is the explicit menu-item
    // permission for its recipe to consume FRONT stock below zero. Menu items
    // do not need to track their own finished-good stock for this control.
    allow_negative_stock: isService ? false : !!allow_negative_stock,
    allow_discount: !!allow_discount,
    taxable: !!taxable,
    is_active: !!is_active,

    reorder_level: 0,
    minimum_stock: 0,
    maximum_stock: 0,
  };

  if (
    price_level_id &&
    actualPrice !== "" &&
    actualPrice !== null &&
    actualPrice !== undefined
  ) {
    payload.prices = [
      buildMenuItemPricePayload({
        shop_id,
        branch_id: branch_id || shop_id,
        price_level_id,
        price: actualPrice,
        currency,
      }),
    ];
  }

  return payload;
};

export const getSalesIndustryMode = async (branchId = null) => {
  const res = await api.get("/api/pos/settings/industry-mode", {
    params: branchId ? { branch_id: branchId } : {},
    headers: companyHeaders(),
  });

  const payload = unwrap(res);
  const data = payload?.data || payload || {};

  return {
    isFastFoodIndustryMode: !!data.is_fast_food_industry_mode,

    bohEnabled: !!data.boh_enabled,
    fastFoodModeEnabled: !!data.fast_food_mode_enabled,
    receiveStockToBohFirst: !!data.receive_stock_to_boh_first,
    recipeConsumesFrontStock: !!data.recipe_consumes_front_stock,

    raw: data,
  };
};

export default {
  getMenuItems,
    getSalesIndustryMode,
  createMenuItem,
  updateMenuItem,
  deleteMenuItem,
  addMenuItemPrice,
  uploadMenuItemImage,
  deleteMenuItemImage,
  buildMenuItemPayload,
  buildMenuItemPricePayload,
};
