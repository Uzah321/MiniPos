import api from "../utils/apiBack";

const BASE = "/api/pos/stock-wastages";
const REPORT_BASE = "/api/pos/reports/stock-wastages";

const companyHeaders = () => ({ "X-Company-Domain": window.location.hostname });
const unwrap = (res) => res?.data?.data ?? res?.data ?? res;

const reportParams = (input = {}) => ({
  date_from: input.date_from,
  date_to: input.date_to,
  stock_area: input.stock_area || undefined,
  reason_code: input.reason_code || undefined,
  search: input.search || undefined,
  branch_id: input.branch_id || undefined,
});

const getFilenameFromHeaders = (res, fallbackName) => {
  const disposition = res?.headers?.["content-disposition"] || res?.headers?.["Content-Disposition"];
  if (!disposition) return fallbackName;
  const utf8Match = disposition.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8Match?.[1]) return decodeURIComponent(utf8Match[1].replace(/["']/g, ""));
  const normalMatch = disposition.match(/filename="?([^";]+)"?/i);
  return normalMatch?.[1] ? normalMatch[1].replace(/["']/g, "") : fallbackName;
};

const ensureExtension = (filename, ext) => {
  const clean = String(filename || "").trim();
  const extension = String(ext || "").replace(".", "");
  if (!clean) return `stock-wastage-report.${extension}`;
  return clean.toLowerCase().endsWith(`.${extension.toLowerCase()}`) ? clean : `${clean}.${extension}`;
};

const downloadBlob = (blob, filename, mimeType) => {
  const url = window.URL.createObjectURL(new Blob([blob], { type: mimeType }));
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

export const lookupWastageProducts = async (params = {}) => {
  const res = await api.get(`${BASE}/products`, { params, headers: companyHeaders() });
  return unwrap(res);
};

export const postStockWastage = async (payload) => {
  const res = await api.post(BASE, payload, { headers: companyHeaders() });
  return unwrap(res);
};

export const getStockWastageReport = async (filters = {}) => {
  const res = await api.get(`${REPORT_BASE}/preview`, { params: reportParams(filters), headers: companyHeaders() });
  return unwrap(res);
};

export const downloadStockWastageExcel = async (filters = {}, filename = null) => {
  const res = await api.get(`${REPORT_BASE}/excel`, {
    params: reportParams(filters),
    responseType: "blob",
    headers: companyHeaders(),
  });
  const fallback = filename || "stock-wastage-report.xlsx";
  downloadBlob(
    res.data,
    ensureExtension(getFilenameFromHeaders(res, fallback), "xlsx"),
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );
};

export const downloadStockWastagePdf = async (filters = {}, filename = null) => {
  const res = await api.get(`${REPORT_BASE}/pdf`, {
    params: reportParams(filters),
    responseType: "blob",
    headers: companyHeaders(),
  });
  const fallback = filename || "stock-wastage-report.pdf";
  downloadBlob(res.data, ensureExtension(getFilenameFromHeaders(res, fallback), "pdf"), "application/pdf");
};
