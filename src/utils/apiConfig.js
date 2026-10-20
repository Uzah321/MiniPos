const trimTrailingSlash = (value = "") => String(value).replace(/\/+$/, "");

export const COMPANY_CODE_STORAGE_KEY = "salexa_company_code";
export const SYNC_LOCAL_CONTROL_STORAGE_KEY = "salexa_sync_local_control_token";

// Dedicated deployments should provide REACT_APP_API_BASE_URL (or runtime
// api_base_url). Same-origin is the only safe fallback; never infer a legacy
// product-specific path from the development port.
const inferredBaseUrl = typeof window !== "undefined" ? window.location.origin : "";
const runtimeConfiguration = typeof window !== "undefined"
  ? {
      ...(window.__SALEXA_RUNTIME_CONFIG__ || {}),
      ...(window.__SERVENEST_RUNTIME_CONFIG__ || {}),
    }
  : {};
const runtimeApiBaseUrl = runtimeConfiguration.api_base_url
  || runtimeConfiguration.apiBaseUrl
  || "";

export const API_BASE_URL = trimTrailingSlash(
  runtimeApiBaseUrl || process.env.REACT_APP_API_BASE_URL || inferredBaseUrl
);

export const normalizeCompanyCode = (value = "") =>
  String(value || "").trim().toUpperCase();

export const getSavedCompanyCode = () => {
  if (typeof window === "undefined") return "";
  return normalizeCompanyCode(localStorage.getItem(COMPANY_CODE_STORAGE_KEY));
};

export const saveCompanyCode = (value) => {
  const companyCode = normalizeCompanyCode(value);
  if (typeof window === "undefined") return companyCode;

  if (companyCode) {
    localStorage.setItem(COMPANY_CODE_STORAGE_KEY, companyCode);
  } else {
    localStorage.removeItem(COMPANY_CODE_STORAGE_KEY);
  }

  return companyCode;
};

export const getSyncLocalControlToken = () => (
  typeof window === "undefined"
    ? ""
    : localStorage.getItem(SYNC_LOCAL_CONTROL_STORAGE_KEY) || ""
);

export const saveSyncLocalControlToken = (value) => {
  const token = String(value || "").trim();
  if (typeof window === "undefined") return token;

  if (token) localStorage.setItem(SYNC_LOCAL_CONTROL_STORAGE_KEY, token);
  else localStorage.removeItem(SYNC_LOCAL_CONTROL_STORAGE_KEY);
  return token;
};

export const isFrontOfficeWorkspace = (workspace = null) => {
  if (workspace) return String(workspace).toLowerCase() === "front-office";
  return typeof window !== "undefined" && window.location.pathname.includes("/front");
};

export const getLocalTillIdentity = () => {
  if (typeof window === "undefined") return {};

  return {
    till_token: localStorage.getItem("salexa_till_token") || "",
    till_id: localStorage.getItem("salexa_till_id") || "",
    till_code: localStorage.getItem("salexa_till_code") || "",
    branch_id: localStorage.getItem("salexa_till_branch_id") || "",
    shop_id: localStorage.getItem("salexa_till_shop_id")
      || localStorage.getItem("salexa_till_branch_id")
      || "",
    brand_id: localStorage.getItem("salexa_till_brand_id") || "",
    complex_id: localStorage.getItem("salexa_till_complex_id") || "",
    company_id: localStorage.getItem("salexa_till_company_id") || "",
    country_id: localStorage.getItem("salexa_till_country_id") || "",
  };
};

export const companyHeaders = ({
  workspace = null,
  companyCode = null,
  unscopedPlatform = false,
} = {}) => {
  if (unscopedPlatform) return {};

  const frontOffice = isFrontOfficeWorkspace(workspace);
  const till = getLocalTillIdentity();

  if (frontOffice && till.till_token) {
    return {
      "X-Till-Token": till.till_token,
      ...(till.till_id ? { "X-Till-Id": till.till_id } : {}),
      ...(till.till_code ? { "X-Till-Code": till.till_code } : {}),
    };
  }

  const resolvedCompanyCode = normalizeCompanyCode(companyCode || getSavedCompanyCode());
  if (resolvedCompanyCode) {
    return { "X-Company-Code": resolvedCompanyCode };
  }

  return typeof window !== "undefined"
    ? { "X-Company-Domain": window.location.hostname }
    : {};
};

export const tokenForWorkspace = (workspace = null) => {
  const isFrontOffice = isFrontOfficeWorkspace(workspace);
  return localStorage.getItem(isFrontOffice ? "front_authToken" : "back_authToken");
};
