// src/services/HospitalityMonthEndService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos";

const normalizeBooleanForLaravel = (value) => {
  if (typeof value === "boolean") return value ? 1 : 0;
  return value;
};

const normalizePayload = (payload = {}) => {
  const normalized = {};

  Object.entries(payload || {}).forEach(([key, value]) => {
    if (value === undefined || value === null || value === "") return;
    normalized[key] = normalizeBooleanForLaravel(value);
  });

  return normalized;
};

const downloadBlob = async (
  url,
  params = {},
  fallbackName = "hospitality-month-end-report",
  mime = "application/octet-stream"
) => {
  const res = await api.get(url, {
    params: normalizePayload(params),
    headers: companyHeaders(),
    responseType: "blob",
  });

  const contentDisposition =
    res.headers?.["content-disposition"] ||
    res.headers?.["Content-Disposition"] ||
    "";

  const match = contentDisposition.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)["']?/i);
  const filename = match?.[1] ? decodeURIComponent(match[1]) : fallbackName;
  const blob = new Blob([res.data], { type: res.data?.type || mime });
  const objectUrl = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = objectUrl;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(objectUrl);

  return true;
};

const uploadForm = async (url, formData, config = {}) => {
  const res = await api.post(url, formData, {
    headers: {
      ...companyHeaders(),
      "Content-Type": "multipart/form-data",
    },
    ...config,
  });

  return res.data;
};

/*
|--------------------------------------------------------------------------
| Back Stock Month End Stock Take
|--------------------------------------------------------------------------
*/

export const getBackStockMonthEndSnapshot = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-takes/month-end/back-stock/snapshot`, {
    params: normalizePayload(params),
    headers: companyHeaders(),
  });

  return res.data;
};

export const downloadBackStockMonthEndBalanceSnapshot = async (params = {}) => {
  return downloadBlob(
    `${BASE}/stock-takes/month-end/back-stock/download-balance-snapshot`,
    params,
    "back-stock-month-end-balance-snapshot.csv",
    "text/csv"
  );
};

export const downloadBackStockMonthEndCountSheet = async (params = {}) => {
  return downloadBlob(
    `${BASE}/stock-takes/month-end/back-stock/download-trading-count-sheet`,
    params,
    "back-stock-month-end-count-sheet.csv",
    "text/csv"
  );
};

export const beginBackStockMonthEndStockTake = async (payload = {}) => {
  const res = await api.post(
    `${BASE}/stock-takes/month-end/back-stock/begin-trading`,
    normalizePayload(payload),
    { headers: companyHeaders() }
  );

  return res.data;
};

export const uploadBackStockMonthEndCountSheet = async (file, payload = {}, config = {}) => {
  const formData = new FormData();

  formData.append("file", file);

  Object.entries(payload || {}).forEach(([key, value]) => {
    if (value === undefined || value === null || value === "") return;
    formData.append(key, String(normalizeBooleanForLaravel(value)));
  });

  return uploadForm(
    `${BASE}/stock-takes/month-end/back-stock/upload-trading-count-sheets`,
    formData,
    config
  );
};

export const getBackStockMonthEndStockTake = async (id) => {
  const res = await api.get(`${BASE}/stock-takes/month-end/back-stock/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

/*
|--------------------------------------------------------------------------
| Hospitality Month End
|--------------------------------------------------------------------------
*/

export const getHospitalityMonthEndPreflight = async (params = {}) => {
  const res = await api.get(`${BASE}/month-end/hospitality/preflight`, {
    params: normalizePayload(params),
    headers: companyHeaders(),
  });

  return res.data;
};

export const getHospitalityMonthEndLivePreview = async (params = {}) => {
  const res = await api.get(`${BASE}/month-end/hospitality/live-preview`, {
    params: normalizePayload(params),
    headers: companyHeaders(),
  });

  return res.data;
};


export const getHospitalityMonthEndProgress = async (runKey) => {
  const res = await api.get(`${BASE}/month-end/hospitality/progress/${runKey}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const runHospitalityMonthEnd = async (payload = {}, config = {}) => {
  const res = await api.post(
    `${BASE}/month-end/hospitality/run`,
    normalizePayload(payload),
    {
      headers: companyHeaders(),
      ...config,
    }
  );

  return res.data;
};

export const getHospitalityMonthEndReports = async (params = {}) => {
  const res = await api.get(`${BASE}/month-end/hospitality/reports`, {
    params: normalizePayload(params),
    headers: companyHeaders(),
  });

  return res.data;
};

export const getHospitalityMonthEndReport = async (id) => {
  const res = await api.get(`${BASE}/month-end/hospitality/reports/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const downloadHospitalityMonthEndCsv = async (id, params = {}) => {
  return downloadBlob(
    `${BASE}/month-end/hospitality/reports/${id}/csv`,
    params,
    "hospitality-month-end-report.csv",
    "text/csv"
  );
};

export const downloadHospitalityMonthEndReport = async (id, format = "csv", params = {}) => {
  const type = String(format || "csv").toLowerCase();

  if (type === "csv" || type === "excel" || type === "xlsx") {
    return downloadHospitalityMonthEndCsv(id, params);
  }

  throw new Error("Only CSV export is available for Month End until PDF/Excel backend endpoints are added.");
};

export default {
  getBackStockMonthEndSnapshot,
  downloadBackStockMonthEndBalanceSnapshot,
  downloadBackStockMonthEndCountSheet,
  beginBackStockMonthEndStockTake,
  uploadBackStockMonthEndCountSheet,
  getBackStockMonthEndStockTake,
  getHospitalityMonthEndPreflight,
  getHospitalityMonthEndLivePreview,
  getHospitalityMonthEndProgress,
  runHospitalityMonthEnd,
  getHospitalityMonthEndReports,
  getHospitalityMonthEndReport,
  downloadHospitalityMonthEndCsv,
  downloadHospitalityMonthEndReport,
};
