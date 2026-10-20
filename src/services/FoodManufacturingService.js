import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const unwrap = (response) => response?.data ?? response;

const cleanId = (value) =>
  value === undefined || value === null || value === "" ? null : value;

const cleanStockArea = (value, fallback = "back") => {
  const area = String(value || "").trim().toLowerCase();
  return ["front", "back"].includes(area) ? area : fallback;
};

export const saveBlobResponse = (
  response,
  fallbackFilename = "download.bin"
) => {
  const blob = response?.data || response;
  if (!(blob instanceof Blob)) return false;

  const disposition =
    response?.headers?.["content-disposition"] ||
    response?.headers?.["Content-Disposition"] ||
    "";

  const match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
  const filename = decodeURIComponent(match?.[1] || fallbackFilename);

  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();

  window.URL.revokeObjectURL(url);
  return true;
};

// -----------------------------------------------------------------------------
// Food Manufacturing / Recipe BOM Service
// Base route: /api/pos/food-manufacturing
// -----------------------------------------------------------------------------

export const getFoodRecipes = async (params = {}) => {
  const res = await api.get("/api/pos/food-manufacturing/recipes", {
    params,
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const getModifierRecipes = async (params = {}) =>
  getFoodRecipes({
    per_page: 1000,
    recipe_type: "modifier",
    ...params,
  });

export const getUnlinkedFoodRecipes = async (params = {}) =>
  getFoodRecipes({
    per_page: 1000,
    unlinked: true,
    ...params,
  });

export const createFoodRecipe = async (payload) => {
  const res = await api.post("/api/pos/food-manufacturing/recipes", payload, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const getFoodRecipe = async (recipeId) => {
  const res = await api.get(`/api/pos/food-manufacturing/recipes/${recipeId}`, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const updateFoodRecipe = async (recipeId, payload) => {
  const res = await api.put(
    `/api/pos/food-manufacturing/recipes/${recipeId}`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return unwrap(res);
};

export const deleteFoodRecipe = async (recipeId) => {
  const res = await api.delete(
    `/api/pos/food-manufacturing/recipes/${recipeId}`,
    {
      headers: companyHeaders(),
    }
  );

  return unwrap(res);
};

export const getFoodRecipeCost = async (recipeId, params = {}) => {
  const res = await api.get(
    `/api/pos/food-manufacturing/recipes/${recipeId}/cost`,
    {
      params,
      headers: companyHeaders(),
    }
  );

  return unwrap(res);
};

export const downloadMenuItemRecipeCostReport = async (menuProductIds = [], format = "xlsx", params = {}) => {
  const type = String(format || "xlsx").toLowerCase();
  const ids = Array.isArray(menuProductIds) ? menuProductIds : [menuProductIds];

  return downloadBackendReport({
    client: api,
    url: `/api/pos/food-manufacturing/recipes/cost-report/${type}`,
    params: {
      ...params,
      menu_product_ids: ids.map((id) => String(id || "").trim()).filter(Boolean).join(","),
    },
    headers: companyHeaders(),
    fallbackName: `menu-item-recipe-cost-report.${type}`,
    extension: type,
    mimeType: type === "pdf" ? "application/pdf" : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};

export const linkRecipeToMenuItem = async (menuProductId, payload) => {
  const res = await api.post(
    `/api/pos/food-manufacturing/menu-items/${menuProductId}/link-recipe`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return unwrap(res);
};

// -----------------------------------------------------------------------------
// Manufacturing / Prep Batch Service
// -----------------------------------------------------------------------------

export const getFoodManufacturingBatches = async (params = {}) => {
  const res = await api.get("/api/pos/food-manufacturing/batches", {
    params,
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const createFoodManufacturingBatch = async (payload) => {
  const res = await api.post("/api/pos/food-manufacturing/batches", payload, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const getFoodManufacturingBatch = async (batchId) => {
  const res = await api.get(`/api/pos/food-manufacturing/batches/${batchId}`, {
    headers: companyHeaders(),
  });

  return unwrap(res);
};

// -----------------------------------------------------------------------------
// Production Report Service
// -----------------------------------------------------------------------------

export const getFoodManufacturingProductionReport = async (params = {}) => {
  const res = await api.get("/api/pos/food-manufacturing/reports/production", {
    params,
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const downloadFoodManufacturingProductionExcel = (params = {}) =>
  downloadBackendReport({
    client: api,
    url: "/api/pos/food-manufacturing/reports/production/excel",
    params,
    headers: companyHeaders(),
    fallbackName: "food-manufacturing-production.xls",
    extension: "xls",
    mimeType: "application/vnd.ms-excel",
  });

export const downloadFoodManufacturingProductionPdf = (params = {}) =>
  downloadBackendReport({
    client: api,
    url: "/api/pos/food-manufacturing/reports/production/pdf",
    params,
    headers: companyHeaders(),
    fallbackName: "food-manufacturing-production.pdf",
    extension: "pdf",
    mimeType: "application/pdf",
  });

// -----------------------------------------------------------------------------
// Production Material Planning Service
// -----------------------------------------------------------------------------

export const getFoodManufacturingProductionPlan = async (params = {}) => {
  const res = await api.get("/api/pos/food-manufacturing/planning/materials", {
    params,
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const downloadFoodManufacturingPlanningExcel = (params = {}) =>
  downloadBackendReport({
    client: api,
    url: "/api/pos/food-manufacturing/planning/materials/excel",
    params,
    headers: companyHeaders(),
    fallbackName: "food-manufacturing-material-plan.xls",
    extension: "xls",
    mimeType: "application/vnd.ms-excel",
  });

export const downloadFoodManufacturingPlanningPdf = (params = {}) =>
  downloadBackendReport({
    client: api,
    url: "/api/pos/food-manufacturing/planning/materials/pdf",
    params,
    headers: companyHeaders(),
    fallbackName: "food-manufacturing-material-plan.pdf",
    extension: "pdf",
    mimeType: "application/pdf",
  });

// -----------------------------------------------------------------------------
// Saved Production Plans Service
// -----------------------------------------------------------------------------

export const saveFoodManufacturingProductionPlan = async (payload) => {
  const res = await api.post(
    "/api/pos/food-manufacturing/planning/plans",
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return unwrap(res);
};

export const getFoodManufacturingSavedPlans = async (params = {}) => {
  const res = await api.get("/api/pos/food-manufacturing/planning/plans", {
    params,
    headers: companyHeaders(),
  });

  return unwrap(res);
};

// Alias required by your current frontend import.
export const getFoodManufacturingProductionPlans =
  getFoodManufacturingSavedPlans;

export const getFoodManufacturingSavedPlan = async (planId) => {
  const res = await api.get(
    `/api/pos/food-manufacturing/planning/plans/${planId}`,
    {
      headers: companyHeaders(),
    }
  );

  return unwrap(res);
};

export const deleteFoodManufacturingSavedPlan = async (planId) => {
  const res = await api.delete(
    `/api/pos/food-manufacturing/planning/plans/${planId}`,
    {
      headers: companyHeaders(),
    }
  );

  return unwrap(res);
};

export const downloadFoodManufacturingSavedPlansExcel = (params = {}) =>
  downloadBackendReport({
    client: api,
    url: "/api/pos/food-manufacturing/planning/plans/excel",
    params,
    headers: companyHeaders(),
    fallbackName: "food-manufacturing-saved-plans.xls",
    extension: "xls",
    mimeType: "application/vnd.ms-excel",
  });

export const downloadFoodManufacturingSavedPlansPdf = (params = {}) =>
  downloadBackendReport({
    client: api,
    url: "/api/pos/food-manufacturing/planning/plans/pdf",
    params,
    headers: companyHeaders(),
    fallbackName: "food-manufacturing-saved-plans.pdf",
    extension: "pdf",
    mimeType: "application/pdf",
  });

// -----------------------------------------------------------------------------
// Helper payload builders
// -----------------------------------------------------------------------------

export const buildFoodRecipePayload = ({
  menu_product_id = null,
  produced_product_id = null,
  name,
  recipe_type = "menu",
  yield_quantity = 1,
  yield_uom_id = null,
  currency = "USD",
  labour_cost = 0,
  overhead_cost = 0,
  packaging_cost = 0,
  notes = "",
  is_active = true,
  make_active = true,
  source_stock_area = null,
  destination_stock_area = null,
  ingredients = [],
}) => {
  const type = String(recipe_type || "menu").trim().toLowerCase();

  return {
    menu_product_id: cleanId(menu_product_id),
    produced_product_id: cleanId(produced_product_id),
    name,
    recipe_type: type,
    yield_quantity: Number(yield_quantity || 1),
    yield_uom_id: cleanId(yield_uom_id),
    currency,
    labour_cost: Number(labour_cost || 0),
    overhead_cost: Number(overhead_cost || 0),
    packaging_cost: Number(packaging_cost || 0),
    notes,
    is_active: !!is_active,
    make_active: !!make_active,
    source_stock_area:
      source_stock_area ||
      (type === "menu" || type === "modifier" ? "front" : "back"),
    destination_stock_area:
      destination_stock_area ||
      (type === "manufacturing" || type === "prep" ? "back" : null),
    ingredients: ingredients.map((item) => ({
      ingredient_product_id: cleanId(item.ingredient_product_id),
      quantity: Number(item.quantity || 0),
      uom_id: cleanId(item.uom_id),
      wastage_percent: Number(item.wastage_percent || 0),
      line_type: item.line_type || "ingredient",
      notes: item.notes || null,
    })),
  };
};

export const buildFoodManufacturingBatchPayload = ({
  recipe_id,
  branch_id,
  produced_product_id = null,
  stock_area = "back",
  source_stock_area = null,
  destination_stock_area = null,
  planned_quantity = null,
  planned_yield_quantity = null,
  actual_yield_quantity,
  production_plan_id = null,
  plan_id = null,
  currency = "USD",
  notes = "",
}) => {
  const sourceArea = cleanStockArea(source_stock_area || stock_area, "back");
  const destinationArea = cleanStockArea(
    destination_stock_area || "back",
    "back"
  );
  const plannedQty = Number(planned_yield_quantity || planned_quantity || actual_yield_quantity || 0);
  const actualQty = Number(actual_yield_quantity || plannedQty || 0);
  const selectedPlanId = cleanId(plan_id || production_plan_id);

  return {
    recipe_id: cleanId(recipe_id),
    branch_id: cleanId(branch_id),
    produced_product_id: cleanId(produced_product_id),
    stock_area: sourceArea,
    source_stock_area: sourceArea,
    destination_stock_area: destinationArea,
    planned_quantity: plannedQty,
    planned_yield_quantity: plannedQty,
    actual_yield_quantity: actualQty,
    plan_id: selectedPlanId,
    production_plan_id: selectedPlanId,
    currency,
    notes,
  };
};

export const buildFoodManufacturingPlanPayload = ({
  branch_id,
  recipe_id,
  produced_product_id = null,
  source_stock_area = "back",
  destination_stock_area = "back",
  planned_quantity,
  required_date = "",
  planned_for = "",
  notes = "",
  status = "draft",
}) => {
  const planDate = planned_for || required_date || null;

  return {
    branch_id: cleanId(branch_id),
    recipe_id: cleanId(recipe_id),
    produced_product_id: cleanId(produced_product_id),
    source_stock_area: cleanStockArea(source_stock_area, "back"),
    destination_stock_area: cleanStockArea(destination_stock_area, "back"),
    planned_quantity: Number(planned_quantity || 0),
    planned_for: planDate,
    required_date: planDate,
    notes,
    status,
  };
};

export const buildFoodManufacturingReportParams = ({
  branch_id = "",
  date_from = "",
  date_to = "",
  recipe_id = "",
  produced_product_id = "",
  source_stock_area = "",
  destination_stock_area = "",
  planned_quantity = "",
  per_page = 100,
} = {}) => ({
  branch_id: cleanId(branch_id),
  date_from,
  date_to,
  recipe_id: cleanId(recipe_id),
  produced_product_id: cleanId(produced_product_id),
  source_stock_area,
  destination_stock_area,
  planned_quantity,
  per_page,
});

export default {
  getFoodRecipes,
  getModifierRecipes,
  getUnlinkedFoodRecipes,
  createFoodRecipe,
  getFoodRecipe,
  updateFoodRecipe,
  deleteFoodRecipe,
  getFoodRecipeCost,
  downloadMenuItemRecipeCostReport,
  linkRecipeToMenuItem,

  getFoodManufacturingBatches,
  createFoodManufacturingBatch,
  getFoodManufacturingBatch,

  getFoodManufacturingProductionReport,
  downloadFoodManufacturingProductionExcel,
  downloadFoodManufacturingProductionPdf,

  getFoodManufacturingProductionPlan,
  downloadFoodManufacturingPlanningExcel,
  downloadFoodManufacturingPlanningPdf,

  saveFoodManufacturingProductionPlan,
  getFoodManufacturingSavedPlans,
  getFoodManufacturingProductionPlans,
  getFoodManufacturingSavedPlan,
  deleteFoodManufacturingSavedPlan,
  downloadFoodManufacturingSavedPlansExcel,
  downloadFoodManufacturingSavedPlansPdf,

  saveBlobResponse,

  buildFoodRecipePayload,
  buildFoodManufacturingBatchPayload,
  buildFoodManufacturingPlanPayload,
  buildFoodManufacturingReportParams,
};
