// src/services/TaxReportService.js

import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos/reports/tax";

export const getEnterpriseTaxSummary = async (params = {}) => {
  const res = await api.get(`${BASE}/summary`, { params, headers: companyHeaders() });
  return res.data;
};

export const getEnterpriseTaxByTax = async (params = {}) => {
  const res = await api.get(`${BASE}/by-tax`, { params, headers: companyHeaders() });
  return res.data;
};

export const getEnterpriseTaxDetails = async (params = {}) => {
  const res = await api.get(`${BASE}/details`, { params, headers: companyHeaders() });
  return res.data;
};

export const getZimraReceiptRegister = async (params = {}) => {
  const res = await api.get(`${BASE}/zimra-receipts`, { params, headers: companyHeaders() });
  return res.data;
};

export const getZimraReceiptDetail = async (receiptId) => {
  const res = await api.get(`${BASE}/zimra-receipts/${receiptId}`, { headers: companyHeaders() });
  return res.data;
};

export const downloadEnterpriseTaxReport = async (report, format, params = {}) => {
  const normalizedFormat = String(format || "xlsx").toLowerCase();
  const extension = normalizedFormat === "excel" ? "xlsx" : normalizedFormat;
  const mimeType = extension === "pdf"
    ? "application/pdf"
    : extension === "csv"
    ? "text/csv;charset=utf-8"
    : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

  return downloadBackendReport({
    client: api,
    url: `${BASE}/export/${extension}`,
    params: { ...params, report },
    headers: companyHeaders(),
    fallbackName: `enterprise-tax-${report || "overview"}.${extension}`,
    extension,
    mimeType,
  });
};
