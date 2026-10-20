import api from "../utils/apiBack";

const companyHeaders = () => ({ "X-Company-Domain": window.location.hostname });
const unwrap = (res) => res?.data?.data ?? res?.data ?? res;

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
  if (!clean) return `closing-stock-movement-report.${extension}`;
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

export const getClosingStockDays = async (params = {}) => {
  const res = await api.get("/api/pos/reports/closing-stock-movements/closings", {
    params,
    headers: companyHeaders(),
  });
  return unwrap(res);
};

export const getClosingStockMovementReport = async (params = {}) => {
  const res = await api.get("/api/pos/reports/closing-stock-movements/preview", {
    params,
    headers: companyHeaders(),
  });
  return unwrap(res);
};

export const downloadClosingStockMovementExcel = async (params = {}, filename = null) => {
  const res = await api.get("/api/pos/reports/closing-stock-movements/excel", {
    params,
    responseType: "blob",
    headers: companyHeaders(),
  });
  const fallback = filename || "closing-stock-movement-report.xlsx";
  downloadBlob(
    res.data,
    ensureExtension(getFilenameFromHeaders(res, fallback), "xlsx"),
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );
};

export const downloadClosingStockMovementPdf = async (params = {}, filename = null) => {
  const res = await api.get("/api/pos/reports/closing-stock-movements/pdf", {
    params,
    responseType: "blob",
    headers: companyHeaders(),
  });
  const fallback = filename || "closing-stock-movement-report.pdf";
  downloadBlob(res.data, ensureExtension(getFilenameFromHeaders(res, fallback), "pdf"), "application/pdf");
};
