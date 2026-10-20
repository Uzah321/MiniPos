// src/services/SalesReportService.js

import api from "../utils/apiBack";
import { downloadBackendReport, normalizeBackendReportParams } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const reportBase = () => window.location.pathname.includes("/front")
  ? "/api/pos"
  : "/api/pos/management";



export const getProfitAndLossReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/profit-loss`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getSalesDashboard = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/sales-dashboard`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};



export const getSalesReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/sales`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getSaleDrilldown = async (saleId) => {
  const res = await api.get(`${reportBase()}/sales/${saleId}/drilldown`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPaymentReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/payments`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getProductSalesReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/products`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getProductDrilldown = async (productId, params = {}) => {
  const res = await api.get(`${reportBase()}/reports/products/${productId}/drilldown`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getCashierSalesReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/cashiers`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getBranchTurnoverReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/branch-turnover`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getShiftSalesReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/shifts`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getShiftSalesDrilldown = async (shiftSessionId, params = {}) => {
  const res = await api.get(`${reportBase()}/reports/shifts/${shiftSessionId}/sales`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};



export const getDayEndsReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/day-ends`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getDailySalesReport = async (params = {}) => {
  const res = await api.get(`${reportBase()}/reports/daily-sales`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

// Append to: src/services/SalesReportService.js

const salesReportDownloadCompanyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const salesReportExportEndpoint = (reportKey, fileFormat) => {
  const key = String(reportKey || "dashboard").trim();
  const format = String(fileFormat || "excel").toLowerCase() === "xlsx" ? "excel" : String(fileFormat || "excel").toLowerCase();
  const endpoints = {
    dashboard: "sales-dashboard",
    "daily-sales": "daily-sales",
    "sales-list": "sales",
    payments: "payments",
    products: "products",
    cashiers: "cashiers",
    "branch-turnover": "branch-turnover",
    "shift-sales": "shifts",
    "day-ends": "day-ends",
    "profit-loss": "profit-loss",
  };
  const resource = endpoints[key];
  if (!resource) throw new Error(`Downloads are not available for ${key}.`);
  return `${reportBase()}/reports/${resource}/export/${format}`;
};

export const downloadSalesReportExport = async (reportKey = "dashboard", format = "excel", params = {}) => {
  const fileFormat = String(format || "excel").toLowerCase();
  const extension = fileFormat === "pdf" ? "pdf" : "xlsx";

  return downloadBackendReport({
    client: api,
    url: salesReportExportEndpoint(reportKey, fileFormat),
    params: normalizeBackendReportParams(params),
    headers: salesReportDownloadCompanyHeaders(),
    fallbackName: `${String(reportKey || "sales-report")}.${extension}`,
    extension,
    mimeType: fileFormat === "pdf"
      ? "application/pdf"
      : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};
