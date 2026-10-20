// src/services/DiscountService.js

import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const getBranchId = () => {
  try {
    return (
      localStorage.getItem("selectedBranchId") ||
      localStorage.getItem("branch_id") ||
      JSON.parse(localStorage.getItem("selectedBranch") || "{}")?.id ||
      ""
    );
  } catch {
    return "";
  }
};

const branchHeaders = (branchId) => ({
  ...companyHeaders(),
  ...(branchId || getBranchId() ? { "X-Branch-Id": branchId || getBranchId() } : {}),
});

const BASE = "/api/pos";

export const getDiscountTypes = async (params = {}) => {
  const res = await api.get(`${BASE}/discount-types`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const getDiscounts = async (params = {}) => {
  const res = await api.get(`${BASE}/discounts`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const saveDiscount = async (payload = {}) => {
  const res = payload.id
    ? await api.put(`${BASE}/discounts/${payload.id}`, payload, { headers: branchHeaders(payload.branch_id) })
    : await api.post(`${BASE}/discounts`, payload, { headers: branchHeaders(payload.branch_id) });

  return res.data;
};

export const deleteDiscount = async (id) => {
  const res = await api.delete(`${BASE}/discounts/${id}`, {
    headers: branchHeaders(),
  });

  return res.data;
};

export const getEligibleDiscounts = async (params = {}) => {
  const res = await api.get(`${BASE}/discounts/eligible/list`, {
    params: {
      ...params,
      branch_id: params.branch_id || getBranchId(),
    },
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const calculateDiscount = async (payload = {}) => {
  const res = await api.post(`${BASE}/discounts/calculate`, payload, {
    headers: branchHeaders(payload.branch_id),
  });

  return res.data;
};

export const getDiscountDashboard = async (params = {}) => {
  const res = await api.get(`${BASE}/discount-reports/dashboard`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const getDiscountReport = async (params = {}) => {
  const res = await api.get(`${BASE}/discount-reports/transactions`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const exportDiscountReport = async (params = {}, format = "xlsx") => {
  const normalizedFormat = String(format || "xlsx").toLowerCase();
  const extension = normalizedFormat === "excel" ? "xlsx" : normalizedFormat;
  const mimeType = extension === "pdf"
    ? "application/pdf"
    : extension === "csv"
    ? "text/csv;charset=utf-8"
    : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

  return downloadBackendReport({
    client: api,
    url: `${BASE}/discount-reports/export`,
    params: { ...params, format: extension },
    headers: branchHeaders(params.branch_id),
    fallbackName: `discount-report.${extension}`,
    extension,
    mimeType,
  });
};
