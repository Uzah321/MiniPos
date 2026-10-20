// src/services/ManufacturingService.js

import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos/manufacturing";

const cleanId = (id, name = "ID") => {
  if (!id) throw new Error(`${name} is required.`);
  return id;
};

const isEmptyValue = (value) =>
  value === undefined || value === null || value === "";

const cleanParams = (params = {}) => {
  const output = {};

  Object.entries(params || {}).forEach(([key, value]) => {
    if (isEmptyValue(value)) return;

    if (Array.isArray(value)) {
      const list = value
        .map((item) => String(item || "").trim())
        .filter(Boolean);

      if (!list.length) return;

      // Laravel handles comma strings reliably with explode() or request string parsing.
      // This also avoids axios array serialization differences between environments.
      output[key] = list.join(",");
      return;
    }

    output[key] = value;
  });

  return output;
};

const requestConfig = (params = {}) => ({
  params: cleanParams(params),
  headers: companyHeaders(),
});

const postConfig = () => ({
  headers: companyHeaders(),
});

const idListParam = (ids = []) =>
  Array.isArray(ids)
    ? ids.map((id) => String(id || "").trim()).filter(Boolean)
    : String(ids || "")
        .split(",")
        .map((id) => id.trim())
        .filter(Boolean);

/*
|--------------------------------------------------------------------------
| Reports / Dashboard
|--------------------------------------------------------------------------
| Matches your current Laravel routes and also supports the enterprise
| reporting routes added for consolidated production, comparison, staff costs,
| flexible labour allocation and activity ledgers.
|
| Removed direct calls to missing routes:
| - /reports/bom-costs
| - /reports/production-cost-summary
|--------------------------------------------------------------------------
*/

export const getManufacturingReports = async (params = {}) => {
  const res = await api.get(`${BASE}/reports`, requestConfig(params));
  return res.data;
};

const exportReportKey = (report) => ({
  production_summary: "production-summary",
  production_costs: "production-costs",
  material_usage: "material-usage",
  material_requirements: "material-requirements",
  shortage_alerts: "shortage-alerts",
  finished_goods: "finished-goods",
  wip: "work-in-progress",
  wastage: "wastage",
  variance: "variance",
  accounting_ledger: "accounting-ledger",
  plan_history: "plan-history",
  plan_comparison: "plan-comparison",
}[report] || report);

export const downloadManufacturingReport = async (report, format, params = {}) => {
  const type = String(format || "pdf").toLowerCase();
  const key = exportReportKey(report);
  return downloadBackendReport({
    client: api,
    url: `${BASE}/reports/${key}/export/${type}`,
    params: cleanParams(params),
    headers: companyHeaders(),
    fallbackName: `${key}-report.${type}`,
    extension: type,
    mimeType: type === "pdf" ? "application/pdf" : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};

export const downloadEnterpriseManufacturingActivity = async (format, params = {}) =>
  downloadManufacturingReport("enterprise-activity", format, params);

export const getManufacturingDashboard = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/dashboard`, requestConfig(params));
  return res.data;
};

export const getManufacturingDashboardFromReports = getManufacturingDashboard;

export const getProductionSummaryReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/production-summary`, requestConfig(params));
  return res.data;
};

export const getProductionCostReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/production-costs`, requestConfig(params));
  return res.data;
};

export const getMaterialRequirementsReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/material-requirements`, requestConfig(params));
  return res.data;
};

export const getMaterialShortageAlertsReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/material-shortage-alerts`, requestConfig(params));
  return res.data;
};

export const getMaterialUsageReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/material-usage`, requestConfig(params));
  return res.data;
};

export const getFinishedGoodsReceivedReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/finished-goods-received`, requestConfig(params));
  return res.data;
};

export const getWorkInProgressReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/work-in-progress`, requestConfig(params));
  return res.data;
};

export const getWastageReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/wastage`, requestConfig(params));
  return res.data;
};

export const getProductionVarianceReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/variance`, requestConfig(params));
  return res.data;
};

export const getProductionAccountingLedgerReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/accounting-ledger`, requestConfig(params));
  return res.data;
};

export const getProductionPlanHistoryReport = async (planId, params = {}) => {
  const id = cleanId(planId, "Production plan ID");
  const res = await api.get(`${BASE}/reports/plans/${id}/history`, requestConfig(params));
  return res.data;
};

export const getManyProductionPlanHistoryReports = async (planIds = [], params = {}) => {
  const ids = idListParam(planIds);
  if (!ids.length) throw new Error("At least one production plan must be selected.");

  const results = await Promise.all(
    ids.map((planId) => getProductionPlanHistoryReport(planId, params))
  );

  return {
    message: "Production plan histories fetched successfully.",
    data: {
      plan_ids: ids,
      reports: results.map((result) => result?.data || result),
    },
  };
};

/* Enterprise report endpoints. These require the enterprise backend routes. */
export const getEnterpriseDashboardReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/enterprise-dashboard`, requestConfig(params));
  return res.data;
};

export const getConsolidatedProductionReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/consolidated-production`, requestConfig(params));
  return res.data;
};

export const getDirectIndirectCostsReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/direct-indirect-costs`, requestConfig(params));
  return res.data;
};

export const getStaffCostsReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/staff-costs`, requestConfig(params));
  return res.data;
};

export const getProductionActivityReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/production-activity`, requestConfig(params));
  return res.data;
};

export const getProductionOrderActivityReport = async (orderId, params = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.get(`${BASE}/reports/orders/${id}/activity`, requestConfig(params));
  return res.data;
};

export const getProductionPlanComparisonReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/production-plan-comparison`, requestConfig(params));
  return res.data;
};

export const getConsolidatedPlanHistoryReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/consolidated-plan-history`, requestConfig(params));
  return res.data;
};

export const getSalaryAllocationReport = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/salary-allocation`, requestConfig(params));
  return res.data;
};

/* Compatibility aliases only. They no longer hit missing backend routes. */
export const getProductionCostSummaryReport = getProductionCostReport;
export const getBomCostReport = getProductionCostReport;

/*
|--------------------------------------------------------------------------
| Recipes / BOM
|--------------------------------------------------------------------------
*/

export const getProductionRecipes = async (params = {}) => {
  const res = await api.get(`${BASE}/recipes`, requestConfig(params));
  return res.data;
};

export const getProductionRecipe = async (recipeId) => {
  const id = cleanId(recipeId, "Recipe ID");
  const res = await api.get(`${BASE}/recipes/${id}`, { headers: companyHeaders() });
  return res.data;
};

export const createProductionRecipe = async (payload = {}) => {
  const res = await api.post(`${BASE}/recipes`, payload, postConfig());
  return res.data;
};

export const updateProductionRecipe = async (recipeId, payload = {}) => {
  const id = cleanId(recipeId, "Recipe ID");
  const res = await api.put(`${BASE}/recipes/${id}`, payload, postConfig());
  return res.data;
};

export const saveProductionRecipe = async (payload = {}) => {
  if (payload?.id) return updateProductionRecipe(payload.id, payload);
  return createProductionRecipe(payload);
};

export const deleteProductionRecipe = async (recipeId) => {
  const id = cleanId(recipeId, "Recipe ID");
  const res = await api.delete(`${BASE}/recipes/${id}`, { headers: companyHeaders() });
  return res.data;
};

/*
|--------------------------------------------------------------------------
| Production Plans
|--------------------------------------------------------------------------
*/

export const getProductionPlans = async (params = {}) => {
  const res = await api.get(`${BASE}/plans`, requestConfig(params));
  return res.data;
};

export const getProductionPlan = async (planId) => {
  const id = cleanId(planId, "Production plan ID");
  const res = await api.get(`${BASE}/plans/${id}`, { headers: companyHeaders() });
  return res.data;
};

export const createProductionPlan = async (payload = {}) => {
  const res = await api.post(`${BASE}/plans`, payload, postConfig());
  return res.data;
};

export const saveProductionPlan = async (payload = {}) => {
  if (payload?.id) {
    throw new Error("Updating production plans is not available because the backend has no PUT /plans/{id} route.");
  }
  return createProductionPlan(payload);
};

export const approveProductionPlan = async (planId, payload = {}) => {
  const id = cleanId(planId, "Production plan ID");
  const res = await api.post(`${BASE}/plans/${id}/approve`, payload, postConfig());
  return res.data;
};

export const calculateProductionPlanMaterials = async (planId, payload = {}) => {
  const id = cleanId(planId, "Production plan ID");
  const res = await api.post(`${BASE}/plans/${id}/calculate-materials`, payload, postConfig());
  return res.data;
};

export const convertProductionPlanToOrders = async (planId, payload = {}) => {
  const id = cleanId(planId, "Production plan ID");
  const res = await api.post(`${BASE}/plans/${id}/convert-to-orders`, payload, postConfig());
  return res.data;
};

export const cancelProductionPlan = async (planId, payload = {}) => {
  const id = cleanId(planId, "Production plan ID");
  const res = await api.post(`${BASE}/plans/${id}/cancel`, payload, postConfig());
  return res.data;
};

/*
|--------------------------------------------------------------------------
| Production Orders
|--------------------------------------------------------------------------
*/

export const getProductionOrders = async (params = {}) => {
  const res = await api.get(`${BASE}/orders`, requestConfig(params));
  return res.data;
};

export const getProductionOrder = async (orderId) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.get(`${BASE}/orders/${id}`, { headers: companyHeaders() });
  return res.data;
};

export const createProductionOrder = async (payload = {}) => {
  const res = await api.post(`${BASE}/orders`, payload, postConfig());
  return res.data;
};

export const saveProductionOrder = async (payload = {}) => {
  if (payload?.id) {
    throw new Error("Updating production orders is not available because the backend has no PUT /orders/{id} route.");
  }
  return createProductionOrder(payload);
};

export const approveProductionOrder = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/approve`, payload, postConfig());
  return res.data;
};

export const reserveProductionMaterials = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/reserve-materials`, payload, postConfig());
  return res.data;
};

export const issueProductionMaterials = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/issue-materials`, payload, postConfig());
  return res.data;
};

export const startProductionOrder = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/start`, payload, postConfig());
  return res.data;
};

export const addProductionOverhead = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/add-overhead`, payload, postConfig());
  return res.data;
};

export const addProductionCost = addProductionOverhead;

export const addFlexibleProductionCost = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");

  const costingMethod = payload.costing_method || payload.allocation_method || "manual";
  const normalisedPayload = {
    ...payload,
    costing_method: costingMethod,
    allocation_method: payload.allocation_method || costingMethod,
  };

  const res = await api.post(`${BASE}/orders/${id}/add-overhead`, normalisedPayload, postConfig());
  return res.data;
};

export const returnProductionMaterial = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/return-material`, payload, postConfig());
  return res.data;
};

export const recordProductionWastage = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/record-wastage`, payload, postConfig());
  return res.data;
};

export const completeProductionOrder = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/complete`, payload, postConfig());
  return res.data;
};

export const cancelProductionOrder = async (orderId, payload = {}) => {
  const id = cleanId(orderId, "Production order ID");
  const res = await api.post(`${BASE}/orders/${id}/cancel`, payload, postConfig());
  return res.data;
};

/*
|--------------------------------------------------------------------------
| Client-side calculation helpers for enterprise modals
|--------------------------------------------------------------------------
*/

export const calculateFlexibleCostAmount = (payload = {}) => {
  const method = payload.costing_method || payload.allocation_method || "manual";

  const amount = Number(payload.amount || 0);
  const quantity = Number(payload.quantity || 0);
  const rate = Number(payload.rate || 0);
  const hours = Number(payload.hours || payload.production_hours || 0);
  const hourlyRate = Number(payload.hourly_rate || payload.rate || 0);
  const monthlySalary = Number(payload.monthly_salary || 0);
  const monthlyWorkingHours = Number(payload.monthly_working_hours || 0);
  const allocationPercent = Number(payload.allocation_percent || 0);

  if (method === "hourly_rate") return hours * hourlyRate;
  if (method === "quantity_rate") return quantity * rate;
  if (method === "monthly_salary_hours") {
    if (!monthlySalary || !monthlyWorkingHours || !hours) return 0;
    return (monthlySalary / monthlyWorkingHours) * hours;
  }
  if (method === "monthly_salary_percent") {
    if (!monthlySalary || !allocationPercent) return 0;
    return monthlySalary * (allocationPercent / 100);
  }

  return amount;
};

/*
|--------------------------------------------------------------------------
| Convenience aliases
|--------------------------------------------------------------------------
*/

export const getRecipeRegister = getProductionRecipes;
export const getPlanRegister = getProductionPlans;
export const getOrderRegister = getProductionOrders;

export const getProductionReport = getProductionSummaryReport;
export const getCostReport = getProductionCostReport;
export const getCostSummaryReport = getProductionCostReport;
export const getBomCostingReport = getProductionCostReport;
export const getMaterialUsage = getMaterialUsageReport;
export const getWastage = getWastageReport;
export const getVarianceReport = getProductionVarianceReport;

export const getFinishedGoodsReport = getFinishedGoodsReceivedReport;
export const getWipReport = getWorkInProgressReport;
export const getShortageAlertsReport = getMaterialShortageAlertsReport;
export const getAccountingLedgerReport = getProductionAccountingLedgerReport;
export const getPlanHistoryReport = getProductionPlanHistoryReport;
export const getPlanComparisonReport = getProductionPlanComparisonReport;
export const getConsolidatedHistoryReport = getConsolidatedPlanHistoryReport;
export const getEnterpriseDashboard = getEnterpriseDashboardReport;

// Dashboard compatibility alias used by ManufacturingReportsModal.js
export const getManufacturingDashboardReport = getEnterpriseDashboardReport;

// Enterprise naming compatibility aliases used by older command center files
export const getEnterpriseManufacturingDashboard = getEnterpriseDashboardReport;
export const getDirectIndirectCostReport = getDirectIndirectCostsReport;
export const getStaffProductionCostReport = getStaffCostsReport;
export const getProductionActivityLedgerReport = getProductionActivityReport;
