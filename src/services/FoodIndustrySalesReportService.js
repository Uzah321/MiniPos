import api from "../utils/apiBack";

const BASE = "/api/pos/reports/food-industry-sales";
const companyHeaders = () => ({ "X-Company-Domain": window.location.hostname });
const unwrap = (res) => res?.data?.data ?? res?.data ?? res;

const cleanParams = (input = {}) => ({
  date_from: input.date_from,
  date_to: input.date_to,
  business_date: input.business_date || undefined,
  branch_id: input.branch_id || undefined,
  till_id: input.till_id || undefined,
  cashier_id: input.cashier_id || undefined,
  shift_session_id: input.shift_session_id || undefined,
  currency_code: input.currency_code || undefined,
  currency_codes: Array.isArray(input.currency_codes) && input.currency_codes.length ? input.currency_codes : undefined,
  category_id: input.category_id || undefined,
  menu_product_id: input.menu_product_id || undefined,
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
  if (!clean) return `food-industry-sales-report.${extension}`;
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



export const getFoodIndustrySalesBusinessDates = async (params = {}) => {
  const res = await api.get(`${BASE}/business-dates`, { params: cleanParams(params), headers: companyHeaders() });
  return unwrap(res);
};

export const getFoodIndustrySalesCurrencies = async (params = {}) => {
  const res = await api.get(`${BASE}/currencies`, { params: cleanParams(params), headers: companyHeaders() });
  return unwrap(res);
};

export const getFoodIndustrySalesTurnover = async (params = {}) => {
  const res = await api.get(`${BASE}/turnover`, { params: cleanParams(params), headers: companyHeaders() });
  return unwrap(res);
};

export const getFoodIndustryDailySales = async (params = {}) => {
  const res = await api.get(`${BASE}/daily`, { params: cleanParams(params), headers: companyHeaders() });
  return unwrap(res);
};

export const getFoodIndustryCategorySales = async (params = {}) => {
  const res = await api.get(`${BASE}/category-sales`, { params: cleanParams(params), headers: companyHeaders() });
  return unwrap(res);
};

export const getFoodIndustryMenuProfitability = async (params = {}) => {
  const res = await api.get(`${BASE}/menu-profitability`, { params: cleanParams(params), headers: companyHeaders() });
  return unwrap(res);
};


export const downloadFoodIndustryDailySalesExcel = async (params = {}, filename = null) => {
  const res = await api.get(`${BASE}/daily/excel`, {
    params: cleanParams(params),
    responseType: "blob",
    headers: companyHeaders(),
  });
  downloadBlob(
    res.data,
    ensureExtension(getFilenameFromHeaders(res, filename || "daily-sales-report.xlsx"), "xlsx"),
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );
};

export const downloadFoodIndustryDailySalesPdf = async (params = {}, filename = null) => {
  const res = await api.get(`${BASE}/daily/pdf`, {
    params: cleanParams(params),
    responseType: "blob",
    headers: companyHeaders(),
  });
  downloadBlob(
    res.data,
    ensureExtension(getFilenameFromHeaders(res, filename || "daily-sales-report.pdf"), "pdf"),
    "application/pdf"
  );
};

export const downloadFoodIndustrySalesExcel = async (params = {}, filename = null) => {
  const res = await api.get(`${BASE}/excel`, { params: cleanParams(params), responseType: "blob", headers: companyHeaders() });
  downloadBlob(
    res.data,
    ensureExtension(getFilenameFromHeaders(res, filename || "food-industry-sales-report.xlsx"), "xlsx"),
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  );
};

export const downloadFoodIndustrySalesPdf = async (params = {}, filename = null) => {
  const res = await api.get(`${BASE}/pdf`, { params: cleanParams(params), responseType: "blob", headers: companyHeaders() });
  downloadBlob(res.data, ensureExtension(getFilenameFromHeaders(res, filename || "food-industry-sales-report.pdf"), "pdf"), "application/pdf");
};
