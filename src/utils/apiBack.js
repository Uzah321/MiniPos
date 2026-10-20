// src/utils/apiBack.js

import axios from "axios";
import {
  API_BASE_URL,
  companyHeaders,
  getLocalTillIdentity,
  getSavedCompanyCode,
  isFrontOfficeWorkspace,
  normalizeCompanyCode,
  tokenForWorkspace,
} from "./apiConfig";
import { isSuperAdministrator } from "./workspaceAccess";

export const LICENSE_BLOCKED_EVENT = "salexa:license-blocked";
export const LICENSE_LOGOUT_STORAGE_KEY = "salexa_license_logout";

const LICENSE_FAILURE_CODES = new Set([
  "COMPANY_INACTIVE",
  "LICENSE_EXPIRED",
  "LICENSE_INVALID",
  "LICENSE_MISSING",
  "LICENSE_INACTIVE",
  "LICENSE_NOT_STARTED",
  "LICENSE_NOT_ACTIVE",
  "LICENSE_NOT_FOUND",
  "LICENSE_REQUIRED",
  "LICENSE_REVOKED",
  "LICENSE_SUSPENDED",
  "LICENSE_TAMPERED",
  "NO_ACTIVE_LICENSE",
]);

const parseStoredUser = (key) => {
  try {
    return JSON.parse(localStorage.getItem(key) || "null");
  } catch (_) {
    return null;
  }
};

const currentWorkspaceUser = (workspace = null) => {
  const isFrontOffice = isFrontOfficeWorkspace(workspace);
  return parseStoredUser(isFrontOffice ? "front_user" : "back_user");
};

export const getLicenseFailure = (error) => {
  const response = error?.response;
  const payload = response?.data || {};
  const license = payload?.license || payload?.data?.license || {};
  const code = String(payload?.code || license?.code || "").toUpperCase();

  // HTTP 423 is also used for operational locks such as Day End in progress
  // and a shift sales cutoff. Only an explicit license failure code may end a
  // user session; status alone is never proof that the license is blocked.
  if (!LICENSE_FAILURE_CODES.has(code)) {
    return null;
  }

  return {
    ...license,
    code: code || "LICENSE_INVALID",
    message: payload?.message || license?.message || "Company license access is blocked.",
  };
};

export const isPosLoginRequest = (error) =>
  String(error?.config?.url || "")
    .split("?")[0]
    .replace(/\/+$/, "")
    .endsWith("/pos-auth/login");

export const isAuthenticationSessionFailure = (error) => {
  if (Number(error?.response?.status || 0) !== 401) return false;

  // Edge synchronization uses machine/terminal credentials, not the cashier's
  // bearer session. A sync 401 can never prove that the logged-in user expired.
  // Never let a background status/run request sign a cashier out mid-sale.
  const requestPath = String(error?.config?.url || "").split("?")[0].toLowerCase();
  if (requestPath.includes("/pos/sync/")) return false;

  const payload = error?.response?.data || {};
  const code = String(payload?.code || "").trim().toUpperCase();
  const message = String(payload?.message || "").trim().toLowerCase();

  return [
    "AUTH_TOKEN_EXPIRED",
    "AUTH_TOKEN_INVALID",
    "AUTH_TOKEN_REVOKED",
    "UNAUTHENTICATED",
  ].includes(code) || message === "unauthenticated." || message === "unauthenticated";
};

export const clearWorkspaceAuthentication = ({ broadcast = true, failure = null } = {}) => {
  [
    { tokenKey: "front_authToken", userKey: "front_user" },
    { tokenKey: "back_authToken", userKey: "back_user" },
  ].forEach(({ tokenKey, userKey }) => {
    if (isSuperAdministrator(parseStoredUser(userKey))) return;

    localStorage.removeItem(tokenKey);
    localStorage.removeItem(userKey);
  });

  if (broadcast) {
    localStorage.setItem(
      LICENSE_LOGOUT_STORAGE_KEY,
      JSON.stringify({
        code: failure?.code || "LICENSE_INVALID",
        at: Date.now(),
      })
    );
  }

  window.dispatchEvent(
    new CustomEvent(LICENSE_BLOCKED_EVENT, {
      detail: failure || { code: "LICENSE_INVALID" },
    })
  );
};

export const clearAuthenticationForWorkspace = (workspace = null) => {
  const isFrontOffice = isFrontOfficeWorkspace(workspace);
  localStorage.removeItem(isFrontOffice ? "front_authToken" : "back_authToken");
  localStorage.removeItem(isFrontOffice ? "front_user" : "back_user");
};

const redirectToDeploymentLogin = () => {
  const path = window.location.pathname.replace(/\/+$/, "");

  if (path !== "/" && path !== "") {
    window.location.replace("/");
  }
};

if (typeof window !== "undefined" && !window.__salexaLicenseStorageListener) {
  window.__salexaLicenseStorageListener = true;
  window.addEventListener("storage", (event) => {
    if (event.key !== LICENSE_LOGOUT_STORAGE_KEY || !event.newValue) return;
    if (isSuperAdministrator(currentWorkspaceUser())) return;

    clearWorkspaceAuthentication({ broadcast: false });
    redirectToDeploymentLogin();
  });
}

const backOfficeBranchId = () => {
  try {
    const user = JSON.parse(localStorage.getItem("back_user") || "null");
    return user?.selectedBranchId || user?.selectedBranch?.id || user?.branch_id || null;
  } catch (_) {
    return null;
  }
};

const getHeader = (headers, name) => {
  if (!headers) return undefined;
  if (typeof headers.get === "function") return headers.get(name);

  const key = Object.keys(headers).find(
    (candidate) => String(candidate).toLowerCase() === String(name).toLowerCase(),
  );
  return key ? headers[key] : undefined;
};

const setHeader = (headers, name, value) => {
  if (!headers || value === undefined || value === null || value === "") return;
  if (typeof headers.set === "function") {
    headers.set(name, value);
    return;
  }
  headers[name] = value;
};

const deleteHeader = (headers, name) => {
  if (!headers) return;
  if (typeof headers.delete === "function") {
    headers.delete(name);
    return;
  }

  Object.keys(headers).forEach((candidate) => {
    if (String(candidate).toLowerCase() === String(name).toLowerCase()) {
      delete headers[candidate];
    }
  });
};

/**
 * Leave multipart boundaries to the browser.
 *
 * This Axios instance has a JSON default because almost every ServeNest
 * request is JSON. Axios treats FormData as JSON when that inherited header is
 * still present, which makes Laravel receive an empty upload. Removing every
 * caller/default Content-Type here lets the browser add the required
 * `multipart/form-data; boundary=...` value consistently.
 */
export const applyRequestPayloadHeaders = (config = {}) => {
  const isFormData = typeof FormData !== "undefined" && config.data instanceof FormData;
  if (!isFormData) return config;

  config.headers = config.headers || {};
  deleteHeader(config.headers, "Content-Type");

  return config;
};

export const applyRequestIdentityHeaders = (config = {}) => {
  config.headers = config.headers || {};

  const workspace = config.salexaWorkspace || null;
  const isFrontOffice = isFrontOfficeWorkspace(workspace);
  const till = getLocalTillIdentity();
  const explicitCompanyCode = normalizeCompanyCode(getHeader(config.headers, "X-Company-Code"));
  const explicitTillToken = getHeader(config.headers, "X-Till-Token");

  // Company Management is the only surface available to a platform owner who
  // deliberately signed in without choosing a tenant. Never let a stale
  // browser company, domain, branch or till silently scope those requests.
  if (config.salexaUnscopedPlatform === true) {
    [
      "X-Company-Code",
      "X-Company-Domain",
      "X-Branch-ID",
      "X-Till-Token",
      "X-Till-Id",
      "X-Till-Code",
    ].forEach((name) => deleteHeader(config.headers, name));
    return config;
  }

  // Back Office sessions are tenant-scoped by the authenticated server token.
  // Never revive the legacy browser company code: it both leaks tenant
  // identity into login and could silently move a management request into a
  // stale company. Front Office continues to identify its paired till below.
  if (!isFrontOffice) {
    [
      "X-Company-Code",
      "X-Company-Domain",
      "X-Till-Token",
      "X-Till-Id",
      "X-Till-Code",
    ].forEach((name) => deleteHeader(config.headers, name));
    return config;
  }

  // Credential recovery has to verify a candidate till token before it is
  // trusted or persisted. This narrowly-scoped probe is the only Front Office
  // request allowed to use an explicit till token instead of the stored one.
  if (isFrontOffice && config.salexaLocalCredentialProbe === true) {
    deleteHeader(config.headers, "X-Company-Code");
    deleteHeader(config.headers, "X-Company-Domain");
    deleteHeader(config.headers, "X-Till-Id");
    deleteHeader(config.headers, "X-Till-Code");
    if (explicitTillToken) setHeader(config.headers, "X-Till-Token", explicitTillToken);
    else deleteHeader(config.headers, "X-Till-Token");
    return config;
  }

  if (isFrontOffice && till.till_token) {
    deleteHeader(config.headers, "X-Company-Code");
    deleteHeader(config.headers, "X-Company-Domain");
    setHeader(config.headers, "X-Till-Token", till.till_token);
    setHeader(config.headers, "X-Till-Id", till.till_id);
    setHeader(config.headers, "X-Till-Code", till.till_code);
    return config;
  }

  deleteHeader(config.headers, "X-Till-Token");
  deleteHeader(config.headers, "X-Till-Id");
  deleteHeader(config.headers, "X-Till-Code");

  const companyCode = explicitCompanyCode || getSavedCompanyCode();
  if (companyCode) {
    deleteHeader(config.headers, "X-Company-Domain");
    setHeader(config.headers, "X-Company-Code", companyCode);
    return config;
  }

  deleteHeader(config.headers, "X-Company-Code");
  const fallbackHeaders = companyHeaders({ workspace });
  setHeader(
    config.headers,
    "X-Company-Domain",
    getHeader(config.headers, "X-Company-Domain") || fallbackHeaders["X-Company-Domain"],
  );

  return config;
};

// Configure the API origin through runtime config or REACT_APP_API_BASE_URL.
// const baseURL = 'http://192.168.100.182:8080/';

//  const baseURL = 'http://192.168.1.228:8080/';
const apiBack = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

apiBack.interceptors.request.use(
  (config) => {
    const requestedWorkspace = config.salexaWorkspace || null;
    const token = tokenForWorkspace(requestedWorkspace);

    // A till registration identifies a selling terminal, not a Back Office
    // management workstation. Never scope management requests to whichever
    // till may happen to be stored in this browser.
    const isFrontOffice = isFrontOfficeWorkspace(requestedWorkspace);
    const managementBranchId = isFrontOffice || config.salexaUnscopedPlatform === true
      ? null
      : backOfficeBranchId();

    applyRequestPayloadHeaders(config);
    applyRequestIdentityHeaders(config);

    if (token && config.servenestBootstrapAuthorization !== true) {
      setHeader(config.headers, "Authorization", `Bearer ${token}`);
    }

    if (managementBranchId && !getHeader(config.headers, "X-Branch-ID")) {
      setHeader(config.headers, "X-Branch-ID", managementBranchId);
    }

    return config;
  },
  (error) => Promise.reject(error)
);

apiBack.interceptors.response.use(
  (response) => response,
  (error) => {
    const requestedWorkspace = error?.config?.salexaWorkspace || null;
    const licenseFailure = getLicenseFailure(error);
    const loginRequest = isPosLoginRequest(error);
    const responseData = error?.response?.data;
    const workspaceTokenMismatch = error?.response?.status === 403
      && String(responseData?.code || "").toUpperCase() === "WORKSPACE_TOKEN_MISMATCH";

    if (licenseFailure && !loginRequest && !isSuperAdministrator(currentWorkspaceUser(requestedWorkspace))) {
      clearWorkspaceAuthentication({ failure: licenseFailure });
      redirectToDeploymentLogin();
    }

    // A 403 workspace policy mismatch means the route/request classification
    // is wrong; it does not prove the login has expired. Preserve both sessions
    // so a single shared-screen mistake cannot sign an operator out mid-sale.
    if (workspaceTokenMismatch && !responseData?.message) {
      responseData.message = "This screen is not available in the current workspace.";
    }

    if (
      isAuthenticationSessionFailure(error)
      && error?.config?.salexaLocalCredentialProbe !== true
      && !String(error?.config?.url || "").includes("/pos-auth/login")
    ) {
      clearAuthenticationForWorkspace(requestedWorkspace);
      if (window.location.pathname !== "/") {
        window.location.replace("/");
      }
    }

    let message = "Request failed.";

    if (responseData?.message) {
      message = responseData.message;
    } else if (responseData?.error) {
      message = responseData.error;
    } else if (responseData?.errors && typeof responseData.errors === "object") {
      message = Object.values(responseData.errors).flat().join("\n");
    } else if (responseData?.data && typeof responseData.data === "object") {
      message = Object.values(responseData.data).flat().join("\n");
    } else if (error?.message) {
      message = error.message;
    }

    error.displayMessage = message;

    return Promise.reject(error);
  }
);

export default apiBack;
