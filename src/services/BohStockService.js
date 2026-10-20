import api from '../utils/apiBack';
import { downloadBackendReport } from '../utils/BackendReportDownload';

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});


export const getFrontStock = async (params = {}) => {
  const res = await api.get("/api/pos/stock/front", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getBackStock = async (params = {}) => {
  const res = await api.get("/api/pos/stock/back", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const downloadStockAreaRegister = (area, format = "pdf", params = {}) => {
  const safeArea = area === "back" ? "back" : "front";
  const normalizedFormat = String(format || "pdf").toLowerCase() === "excel" ? "xlsx" : String(format || "pdf").toLowerCase();

  return downloadBackendReport({
    client: api,
    url: `/api/pos/stock/${safeArea}/download/${normalizedFormat}`,
    params,
    headers: companyHeaders(),
    fallbackName: `${safeArea}-stock.${normalizedFormat}`,
    extension: normalizedFormat,
    mimeType: normalizedFormat === "pdf"
      ? "application/pdf"
      : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};

export const issueStockToFront = async (payload) => {
  const res = await api.post("/api/pos/stock/issue-to-front", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const issueBackStockEnterprise = async (payload = {}) => {
  const res = await api.post(`/api/pos/stock/issue-enterprise`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};
