// // src/services/CashupService.js

// import api from "../utils/apiBack";

// const companyHeaders = () => ({
//   "X-Company-Domain": window.location.hostname,
// });

// const BASE = "/api/pos";

// export const getCashupHistory = async (params = {}) => {
//   const res = await api.get(`${BASE}/cashups`, {
//     params,
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const getShiftCompliance = async () => {
//   const res = await api.get(`${BASE}/shift-compliance`, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const getActiveShiftSummary = async () => {
//   const res = await api.get(`${BASE}/cashup/active-shift-summary`, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const recordCashDrawerMovement = async (payload = {}) => {
//   const res = await api.post(`${BASE}/cash-drawer-movements`, payload, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const cashupShift = async (payload = {}) => {
//   const res = await api.post(`${BASE}/cashup`, payload, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const endShift = async (payload = {}) => {
//   const res = await api.post(`${BASE}/cashup/end-shift`, payload, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// /**
//  * New standalone Cashout Report endpoint.
//  * This is the only function you need to add for the new report.
//  */
// export const getCashoutReport = async (params = {}) => {
//   const res = await api.get(`${BASE}/cashouts/report`, {
//     params,
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// // Append to: src/services/CashupService.js

// const cashupDownloadCompanyHeaders = () => ({
//   "X-Company-Domain": window.location.hostname,
// });

// const cashupExportParams = (params = {}) => {
//   const cleaned = {};
//   Object.entries(params || {}).forEach(([key, value]) => {
//     if (value === undefined || value === null || value === "") return;
//     cleaned[key] = value;
//   });
//   return cleaned;
// };

// const cashupSaveBlob = (blobData, headers = {}, fallbackName = "cashup-export.csv") => {
//   const contentDisposition =
//     headers?.["content-disposition"] ||
//     headers?.["Content-Disposition"] ||
//     "";

//   const match = contentDisposition.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)["']?/i);
//   const filename = match?.[1] ? decodeURIComponent(match[1]) : fallbackName;
//   const blob = new Blob([blobData], { type: blobData?.type || "application/octet-stream" });
//   const url = URL.createObjectURL(blob);
//   const link = document.createElement("a");

//   link.href = url;
//   link.download = filename;
//   document.body.appendChild(link);
//   link.click();
//   link.remove();
//   URL.revokeObjectURL(url);

//   return true;
// };

// const cashupDownloadExport = async (url, params = {}, fallbackName = "cashup-export.csv") => {
//   const response = await api.get(url, {
//     params: cashupExportParams(params),
//     headers: cashupDownloadCompanyHeaders(),
//     responseType: "blob",
//   });

//   return cashupSaveBlob(response.data, response.headers, fallbackName);
// };

// export const downloadCashupHistoryExport = async (format = "csv", params = {}) => {
//   const fileFormat = String(format || "csv").toLowerCase();
//   return cashupDownloadExport(
//     `/api/pos/cashup/history/export/${fileFormat}`,
//     params,
//     `cashup-history.${fileFormat === "xlsx" ? "xlsx" : "csv"}`
//   );
// };

// export const downloadCashoutReportExport = async (format = "csv", params = {}) => {
//   const fileFormat = String(format || "csv").toLowerCase();
//   return cashupDownloadExport(
//     `/api/pos/cashup/cashout-report/export/${fileFormat}`,
//     params,
//     `cashout-report.${fileFormat === "xlsx" ? "xlsx" : "csv"}`
//   );
// };

// export const downloadShiftComplianceExport = async (format = "csv", params = {}) => {
//   const fileFormat = String(format || "csv").toLowerCase();
//   return cashupDownloadExport(
//     `/api/pos/cashup/shift-compliance/export/${fileFormat}`,
//     params,
//     `shift-compliance.${fileFormat === "xlsx" ? "xlsx" : "csv"}`
//   );
// };

import api from "../utils/apiBack";
import { downloadBackendReport, normalizeBackendReportParams } from "../utils/BackendReportDownload";

const companyHeaders = () => ({ "X-Company-Domain": window.location.hostname });
const unwrap = (res) => res?.data?.data ?? res?.data ?? res;

const normalizeExportArgs = (formatOrParams = "excel", paramsOrFormat = {}) => {
  if (typeof formatOrParams === "string") {
    return {
      format: formatOrParams.toLowerCase(),
      params: normalizeBackendReportParams(paramsOrFormat),
    };
  }

  return {
    format: typeof paramsOrFormat === "string"
      ? paramsOrFormat.toLowerCase()
      : String(formatOrParams?.format || "excel").toLowerCase(),
    params: normalizeBackendReportParams(formatOrParams),
  };
};

const downloadFile = (url, params, fallback, ext, mimeType) =>
  downloadBackendReport({
    client: api,
    url,
    params,
    headers: companyHeaders(),
    fallbackName: fallback,
    extension: ext,
    mimeType,
  });

export const getActiveShiftSummary = async (params = {}) => {
  const res = await api.get("/api/pos/cashup/active-shift-summary", { params, headers: companyHeaders() });
  return unwrap(res);
};

export const cashupShift = async (payload = {}) => {
  const res = await api.post("/api/pos/cashup", payload, { headers: companyHeaders() });
  return unwrap(res);
};

export const endShift = async (payload = {}) => {
  const res = await api.post("/api/pos/cashup/end-shift", payload, { headers: companyHeaders() });
  return unwrap(res);
};

export const recordCashDrawerMovement = async (payload = {}) => {
  const res = await api.post("/api/pos/cash-drawer-movements", payload, { headers: companyHeaders() });
  return unwrap(res);
};

export const getCashupHistory = async (params = {}) => {
  const res = await api.get("/api/pos/cashups", { params, headers: companyHeaders() });
  return res?.data ?? res;
};

export const getCashupHistoryReport = getCashupHistory;

export const getCashoutReport = async (params = {}) => {
  const res = await api.get("/api/pos/cashouts/report", { params, headers: companyHeaders() });
  return res?.data ?? res;
};

export const getShiftCompliance = async (params = {}) => {
  const res = await api.get("/api/pos/shift-compliance", { params, headers: companyHeaders() });
  return unwrap(res);
};

export const getShiftReport = async (params = {}) => {
  const res = await api.get("/api/pos/shift-compliance/report", { params, headers: companyHeaders() });
  return res?.data ?? res;
};

export const downloadCashupHistoryExcel = (params = {}) =>
  downloadFile(
    "/api/pos/cashups/export/excel",
    params,
    "cashup-history-report.xlsx",
    "xlsx",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );

export const downloadCashupHistoryPdf = (params = {}) =>
  downloadFile("/api/pos/cashups/export/pdf", params, "cashup-history-report.pdf", "pdf", "application/pdf");

export const downloadCashoutReportExcel = (params = {}) =>
  downloadFile(
    "/api/pos/cashouts/report/export/excel",
    params,
    "cash-movement-report.xlsx",
    "xlsx",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );

export const downloadCashoutReportPdf = (params = {}) =>
  downloadFile("/api/pos/cashouts/report/export/pdf", params, "cash-movement-report.pdf", "pdf", "application/pdf");

export const downloadShiftReportExcel = (params = {}) =>
  downloadFile(
    "/api/pos/shift-compliance/report/export/excel",
    params,
    "shift-report.xlsx",
    "xlsx",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );

export const downloadShiftReportPdf = (params = {}) =>
  downloadFile("/api/pos/shift-compliance/report/export/pdf", params, "shift-report.pdf", "pdf", "application/pdf");

export const downloadCashupHistoryExport = (formatOrParams = "excel", paramsOrFormat = {}) => {
  const { format, params } = normalizeExportArgs(formatOrParams, paramsOrFormat);
  return format === "pdf" ? downloadCashupHistoryPdf(params) : downloadCashupHistoryExcel(params);
};

export const downloadCashoutReportExport = (formatOrParams = "excel", paramsOrFormat = {}) => {
  const { format, params } = normalizeExportArgs(formatOrParams, paramsOrFormat);
  return format === "pdf" ? downloadCashoutReportPdf(params) : downloadCashoutReportExcel(params);
};

export const downloadShiftComplianceExport = (formatOrParams = "excel", paramsOrFormat = {}) => {
  const { format, params } = normalizeExportArgs(formatOrParams, paramsOrFormat);
  return format === "pdf" ? downloadShiftReportPdf(params) : downloadShiftReportExcel(params);
};
