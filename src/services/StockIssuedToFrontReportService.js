import api from "../utils/apiBack";

const BASE = "/api/pos/reports/stock-issued-to-front";

const companyHeaders = () => ({ "X-Company-Domain": window.location.hostname });
const unwrap = (res) => res?.data?.data ?? res?.data ?? res;

// const buildParams = (input = {}) => ({
//   date_from: input.date_from,
//   date_to: input.date_to,
//   search: input.search || undefined,
//   branch_id: input.branch_id || undefined,
// });
const buildParams = (params = {}) => {
  const clean = {};
  Object.entries(params || {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") clean[key] = value;
  });
  return clean;
};

const downloadBlob = async (url, params, filename) => {
  const res = await api.get(url, {
    params: buildParams(params),
    headers: companyHeaders(),
    responseType: "blob",
  });

  const blobUrl = window.URL.createObjectURL(new Blob([res.data]));
  const link = document.createElement("a");
  link.href = blobUrl;
  link.setAttribute("download", filename);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(blobUrl);
};

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
  if (!clean) return `stock-issued-to-front-report.${extension}`;
  return clean.toLowerCase().endsWith(`.${extension.toLowerCase()}`) ? clean : `${clean}.${extension}`;
};

// const downloadBlob = (blob, filename, mimeType) => {
//   const url = window.URL.createObjectURL(new Blob([blob], { type: mimeType }));
//   const link = document.createElement("a");
//   link.href = url;
//   link.download = filename;
//   link.setAttribute("download", filename);
//   link.style.display = "none";
//   document.body.appendChild(link);
//   link.click();
//   setTimeout(() => {
//     link.remove();
//     window.URL.revokeObjectURL(url);
//   }, 200);
// };

// export const getStockIssuedToFrontReport = async (filters = {}) => {
//   const res = await api.get(`${BASE}/preview`, {
//     params: buildParams(filters),
//     headers: companyHeaders(),
//   });
//   return unwrap(res);
// };

// export const downloadStockIssuedToFrontExcel = async (filters = {}, filename = null) => {
//   const res = await api.get(`${BASE}/excel`, {
//     params: buildParams(filters),
//     responseType: "blob",
//     headers: companyHeaders(),
//   });
//   const fallback = filename || "stock-issued-to-front-report.xlsx";
//   downloadBlob(
//     res.data,
//     ensureExtension(getFilenameFromHeaders(res, fallback), "xlsx"),
//     "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
//   );
// };

// export const downloadStockIssuedToFrontPdf = async (filters = {}, filename = null) => {
//   const res = await api.get(`${BASE}/pdf`, {
//     params: buildParams(filters),
//     responseType: "blob",
//     headers: companyHeaders(),
//   });
//   const fallback = filename || "stock-issued-to-front-report.pdf";
//   downloadBlob(res.data, ensureExtension(getFilenameFromHeaders(res, fallback), "pdf"), "application/pdf");
// };



export const getStockIssuedToFrontReport = async (params = {}) => {
  const res = await api.get("/api/pos/stock/issue-enterprise/report", {
    params: buildParams(params),
    headers: companyHeaders(),
  });

  return unwrap(res);
};

export const downloadStockIssuedToFrontExcel = async (params = {}, filename = "enterprise-stock-issues.xlsx") => {
  return downloadBlob("/api/pos/stock/issue-enterprise/report/excel", params, filename);
};

export const downloadStockIssuedToFrontPdf = async (params = {}, filename = "enterprise-stock-issues.pdf") => {
  return downloadBlob("/api/pos/stock/issue-enterprise/report/pdf", params, filename);
};

export default {
  getStockIssuedToFrontReport,
  downloadStockIssuedToFrontExcel,
  downloadStockIssuedToFrontPdf,
};