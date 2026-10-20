// src/services/StockReportService.js

import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos/reports/stock";

export const downloadStockReport = (report, format = "pdf", params = {}) => {
  const normalizedFormat = String(format || "pdf").toLowerCase() === "excel" ? "xlsx" : String(format || "pdf").toLowerCase();

  return downloadBackendReport({
    client: api,
    url: `${BASE}/download/${report}/${normalizedFormat}`,
    params,
    headers: companyHeaders(),
    fallbackName: `${report}.${normalizedFormat}`,
    extension: normalizedFormat,
    mimeType: normalizedFormat === "pdf"
      ? "application/pdf"
      : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};

export const getStockDashboard = async (params = {}) => {
  const res = await api.get(`${BASE}/dashboard`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getCompanyStockBalance = async (params = {}) => {
  const res = await api.get(`${BASE}/company-balance`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getBranchStockBalance = async (params = {}) => {
  const res = await api.get(`${BASE}/branch-balance`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockBalance = async (params = {}) => {
  const res = await api.get(`${BASE}/balance`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockAsOfBalance = async (params = {}) => {
  const res = await api.get(`${BASE}/as-of`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockMovements = async (params = {}) => {
  const res = await api.get(`${BASE}/movements`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getProductStockLedger = async (productId, params = {}) => {
  const res = await api.get(`${BASE}/product-ledger/${productId}`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockReceivedReport = async (params = {}) => {
  const res = await api.get(`${BASE}/received`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockAdjustmentsReport = async (params = {}) => {
  const res = await api.get(`${BASE}/adjustments`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockValuationReport = async (params = {}) => {
  const res = await api.get(`${BASE}/valuation`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getLowStockReport = async (params = {}) => {
  const res = await api.get(`${BASE}/low-stock`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getOutOfStockReport = async (params = {}) => {
  const res = await api.get(`${BASE}/out-of-stock`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getNegativeStockReport = async (params = {}) => {
  const res = await api.get(`${BASE}/negative-stock`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockCategorySummary = async (params = {}) => {
  const res = await api.get(`${BASE}/category-summary`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockSupplierSummary = async (params = {}) => {
  const res = await api.get(`${BASE}/supplier-summary`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};
