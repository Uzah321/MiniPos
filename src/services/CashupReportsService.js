import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({ "X-Company-Domain": window.location.hostname });

export const getCashupHistoryReport = async (params = {}) => {
  const res = await api.get("/api/pos/cashups", { params, headers: companyHeaders() });
  return res?.data ?? res;
};

export const getCashoutReport = async (params = {}) => {
  const res = await api.get("/api/pos/cashouts/report", { params, headers: companyHeaders() });
  return res?.data ?? res;
};

export const getShiftReport = async (params = {}) => {
  const res = await api.get("/api/pos/shift-compliance/report", { params, headers: companyHeaders() });
  return res?.data ?? res;
};

const download = (url, params, fallback, ext, mimeType) =>
  downloadBackendReport({
    client: api,
    url,
    params,
    headers: companyHeaders(),
    fallbackName: fallback,
    extension: ext,
    mimeType,
  });

export const downloadCashupHistoryExcel = (params = {}) =>
  download(
    "/api/pos/cashups/export/excel",
    params,
    "cashup-history-report.xlsx",
    "xlsx",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );

export const downloadCashupHistoryPdf = (params = {}) =>
  download("/api/pos/cashups/export/pdf", params, "cashup-history-report.pdf", "pdf", "application/pdf");

export const downloadCashoutReportExcel = (params = {}) =>
  download(
    "/api/pos/cashouts/report/export/excel",
    params,
    "cash-movement-report.xlsx",
    "xlsx",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );

export const downloadCashoutReportPdf = (params = {}) =>
  download("/api/pos/cashouts/report/export/pdf", params, "cash-movement-report.pdf", "pdf", "application/pdf");

export const downloadShiftReportExcel = (params = {}) =>
  download(
    "/api/pos/shift-compliance/report/export/excel",
    params,
    "shift-report.xlsx",
    "xlsx",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );

export const downloadShiftReportPdf = (params = {}) =>
  download("/api/pos/shift-compliance/report/export/pdf", params, "shift-report.pdf", "pdf", "application/pdf");
