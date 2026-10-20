import api from "../utils/apiBack";

const companyHeaders = () => ({ "X-Company-Domain": window.location.hostname });
const unwrap = (res) => res?.data?.data ?? res?.data ?? res;

const getFilenameFromHeaders = (res, fallbackName) => {
  const disposition = res?.headers?.["content-disposition"] || res?.headers?.["Content-Disposition"];
  if (!disposition) return fallbackName;

  const utf8Match = disposition.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8Match?.[1]) return decodeURIComponent(utf8Match[1].replace(/["']/g, ""));

  const normalMatch = disposition.match(/filename="?([^";]+)"?/i);
  if (normalMatch?.[1]) return normalMatch[1].replace(/["']/g, "");

  return fallbackName;
};

const ensureExtension = (filename, extension) => {
  const clean = String(filename || "").trim();
  const ext = String(extension || "").replace(".", "");
  if (!clean) return `daily-stock-reconciliation.${ext}`;
  return clean.toLowerCase().endsWith(`.${ext.toLowerCase()}`) ? clean : `${clean}.${ext}`;
};

const downloadBlob = (blob, filename, mimeType) => {
  const fileBlob = new Blob([blob], { type: mimeType });
  const url = window.URL.createObjectURL(fileBlob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  link.setAttribute("download", filename);
  link.style.display = "none";

  document.body.appendChild(link);
  link.click();

  setTimeout(() => {
    link.remove();
    window.URL.revokeObjectURL(url);
  }, 200);
};

const menuParams = (params = {}) => {
  const next = { ...params };
  if (Array.isArray(next.menu_product_ids)) next.menu_product_ids = next.menu_product_ids.join(",");
  return next;
};

export const getCurrentTradingDay = async (params = {}) => {
  const res = await api.get("/api/pos/trading-day/current", { params, headers: companyHeaders() });
  return unwrap(res);
};

export const runTradingDayEnd = async (payload = {}) => {
  const res = await api.post("/api/pos/trading-day/day-end", payload, { headers: companyHeaders() });
  return res?.data ?? res;
};

export const rollbackTradingDayEnd = async (payload = {}) => {
  const res = await api.post("/api/pos/trading-day/day-end/rollback", payload, { headers: companyHeaders() });
  return res?.data ?? res;
};

export const getLiveMenuRecipeDsr = async (params = {}) => {
  const res = await api.get("/api/pos/reports/menu-recipe-daily-stock-reconciliation/live", {
    params: menuParams(params),
    headers: companyHeaders(),
  });
  return unwrap(res);
};

export const getSavedMenuRecipeDsrReports = async (params = {}) => {
  const res = await api.get("/api/pos/reports/menu-recipe-daily-stock-reconciliation", {
    params,
    headers: companyHeaders(),
  });
  return res?.data ?? res;
};

export const getSavedMenuRecipeDsrReport = async (reportId) => {
  const res = await api.get(`/api/pos/reports/menu-recipe-daily-stock-reconciliation/${reportId}`, {
    headers: companyHeaders(),
  });
  return unwrap(res);
};

export const downloadMenuRecipeDsrExcel = async (reportId, filename = null, params = {}) => {
  const res = await api.get(`/api/pos/reports/menu-recipe-daily-stock-reconciliation/${reportId}/excel`, {
    params: menuParams(params),
    responseType: "blob",
    headers: companyHeaders(),
  });
  const fallback = filename || `closed-dsr-${reportId}.xls`;
  downloadBlob(res.data, ensureExtension(getFilenameFromHeaders(res, fallback), "xls"), "application/vnd.ms-excel;charset=utf-8");
};

export const downloadMenuRecipeDsrPdf = async (reportId, filename = null, params = {}) => {
  const res = await api.get(`/api/pos/reports/menu-recipe-daily-stock-reconciliation/${reportId}/pdf`, {
    params: menuParams(params),
    responseType: "blob",
    headers: companyHeaders(),
  });
  const fallback = filename || `closed-dsr-${reportId}.pdf`;
  downloadBlob(res.data, ensureExtension(getFilenameFromHeaders(res, fallback), "pdf"), "application/pdf");
};

export const downloadLiveMenuRecipeDsrExcel = async (params = {}, filename = null) => {
  const res = await api.get("/api/pos/reports/menu-recipe-daily-stock-reconciliation/live/excel", {
    params: menuParams(params),
    responseType: "blob",
    headers: companyHeaders(),
  });
  const tradingDate = params?.trading_date || "current";
  const fallback = filename || `live-dsr-${tradingDate}.xls`;
  downloadBlob(res.data, ensureExtension(getFilenameFromHeaders(res, fallback), "xls"), "application/vnd.ms-excel;charset=utf-8");
};

export const downloadLiveMenuRecipeDsrPdf = async (params = {}, filename = null) => {
  const res = await api.get("/api/pos/reports/menu-recipe-daily-stock-reconciliation/live/pdf", {
    params: menuParams(params),
    responseType: "blob",
    headers: companyHeaders(),
  });
  const tradingDate = params?.trading_date || "current";
  const fallback = filename || `live-dsr-${tradingDate}.pdf`;
  downloadBlob(res.data, ensureExtension(getFilenameFromHeaders(res, fallback), "pdf"), "application/pdf");
};
