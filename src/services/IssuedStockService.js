import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

export const getIssuedStock = async (params = {}) => {
  const response = await api.get("/api/pos/issued-stock", {
    params,
    headers: companyHeaders(),
  });

  return response.data;
};

export const getIssuedStockDetail = async (issueId, params = {}) => {
  const response = await api.get(`/api/pos/issued-stock/${encodeURIComponent(issueId)}`, {
    params,
    headers: companyHeaders(),
  });

  return response.data;
};

export const downloadIssuedStockPdf = (issueId, params = {}, fallbackName = "issued-stock.pdf") =>
  downloadBackendReport({
    client: api,
    url: `/api/pos/issued-stock/${encodeURIComponent(issueId)}/pdf`,
    params,
    headers: companyHeaders(),
    fallbackName,
    extension: "pdf",
    mimeType: "application/pdf",
  });
