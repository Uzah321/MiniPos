// src/services/TillService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const getTillHeaders = () => {
  if (!window.location.pathname.includes("/front")) return companyHeaders();

  const tillToken = localStorage.getItem("salexa_till_token");

  return {
    ...companyHeaders(),
    ...(tillToken ? { "X-Till-Token": tillToken } : {}),
  };
};

// =============================
// TILLS API
// =============================

export const getTills = async (params = {}) => {
  const res = await api.get("/api/pos/tills", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getTill = async (tillId) => {
  const res = await api.get(`/api/pos/tills/${tillId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createTill = async (payload) => {
  const res = await api.post("/api/pos/tills", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateTill = async (tillId, payload) => {
  const res = await api.put(`/api/pos/tills/${tillId}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deleteTill = async (tillId) => {
  const res = await api.delete(`/api/pos/tills/${tillId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const activateTill = async (tillId, payload = {}) => {
  const res = await api.post(`/api/pos/tills/${tillId}/activate`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createStandaloneTillActivationCode = async (tillId, payload = {}) => {
  const res = await api.post(`/api/pos/tills/${tillId}/activation-code`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const unregisterTill = async (tillId) => {
  const res = await api.post(
    `/api/pos/tills/${tillId}/unregister`,
    {},
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const resolveCurrentTill = async () => {
  const res = await api.get("/api/pos/tills/current/resolve", {
    headers: getTillHeaders(),
  });

  return res.data;
};

export const generateTillCode = async (branchId) => {
  const res = await api.get("/api/pos/tills/generate-code", {
    params: {
      branch_id: branchId,
    },
    headers: companyHeaders(),
  });

  return res.data;
};

// =============================
// LOCAL TILL STORAGE
// =============================

export const saveLocalTill = (tillData = {}) => {
  const hierarchy = tillData.hierarchy || {};
  const shop = hierarchy.shop || tillData.shop || {};
  const till = hierarchy.till || tillData.till || tillData;
  const values = {
    salexa_till_token: tillData.till_token || till.till_token || till.terminal_credential,
    salexa_till_id: till.id || tillData.id || tillData.till_id,
    salexa_till_code: till.till_code || till.code || tillData.till_code,
    salexa_till_name: till.till_name || till.name || tillData.till_name,
    salexa_till_branch_id: shop.id || till.shop_id || till.branch_id || tillData.shop_id || tillData.branch_id,
    salexa_till_shop_id: shop.id || till.shop_id || till.branch_id || tillData.shop_id || tillData.branch_id,
    salexa_till_shop_name: shop.name || shop.shop_name || shop.branch_name || tillData.shop_name || tillData.branch_name,
    salexa_till_complex_id: hierarchy.complex?.id || tillData.complex_id,
    salexa_till_complex_name: hierarchy.complex?.name || tillData.complex_name,
    salexa_till_brand_id: hierarchy.brand?.id || tillData.brand_id,
    salexa_till_brand_name: hierarchy.brand?.name || tillData.brand_name,
    salexa_till_brand_logo: tillData.branding?.tenant_logo || hierarchy.brand?.logo || hierarchy.brand?.image || tillData.brand_logo,
    salexa_till_company_id: hierarchy.company?.id || tillData.company_id,
    salexa_till_company_name: hierarchy.company?.name || tillData.company_name,
    salexa_till_country_id: hierarchy.country?.id || tillData.country_id,
    salexa_till_country_name: hierarchy.country?.name || tillData.country_name,
    salexa_till_device_label: tillData.device_label,
  };

  Object.entries(values).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      localStorage.setItem(key, String(value));
    }
  });

  window.dispatchEvent(new Event("salexa_till_updated"));
};

export const getLocalTill = () => {
  return {
    till_token: localStorage.getItem("salexa_till_token") || "",
    id: localStorage.getItem("salexa_till_id") || "",
    till_code: localStorage.getItem("salexa_till_code") || "",
    till_name: localStorage.getItem("salexa_till_name") || "",
    branch_id: localStorage.getItem("salexa_till_branch_id") || "",
    shop_id: localStorage.getItem("salexa_till_shop_id") || localStorage.getItem("salexa_till_branch_id") || "",
    shop_name: localStorage.getItem("salexa_till_shop_name") || "",
    complex_id: localStorage.getItem("salexa_till_complex_id") || "",
    complex_name: localStorage.getItem("salexa_till_complex_name") || "",
    brand_id: localStorage.getItem("salexa_till_brand_id") || "",
    brand_name: localStorage.getItem("salexa_till_brand_name") || "",
    brand_logo: localStorage.getItem("salexa_till_brand_logo") || "",
    company_id: localStorage.getItem("salexa_till_company_id") || "",
    company_name: localStorage.getItem("salexa_till_company_name") || "",
    country_id: localStorage.getItem("salexa_till_country_id") || "",
    country_name: localStorage.getItem("salexa_till_country_name") || "",
    device_label: localStorage.getItem("salexa_till_device_label") || "",
  };
};

export const saveLocalTillDeviceLabel = (value) => {
  const deviceLabel = String(value || "").trim();

  if (deviceLabel) {
    localStorage.setItem("salexa_till_device_label", deviceLabel);
  } else {
    localStorage.removeItem("salexa_till_device_label");
  }

  window.dispatchEvent(new Event("salexa_till_updated"));
  return deviceLabel;
};

export const clearLocalTill = () => {
  [
    "salexa_till_token",
    "salexa_till_id",
    "salexa_till_code",
    "salexa_till_name",
    "salexa_till_branch_id",
    "salexa_till_shop_id",
    "salexa_till_shop_name",
    "salexa_till_complex_id",
    "salexa_till_complex_name",
    "salexa_till_brand_id",
    "salexa_till_brand_name",
    "salexa_till_brand_logo",
    "salexa_till_company_id",
    "salexa_till_company_name",
    "salexa_till_country_id",
    "salexa_till_country_name",
    "salexa_till_device_label",
  ].forEach((key) => localStorage.removeItem(key));

  window.dispatchEvent(new Event("salexa_till_updated"));
};

export const getLocalTillDisplay = () => {
  const till = getLocalTill();

  const tillCode = till?.till_code || "";
  const tillName = till?.till_name || "";

  if (!tillCode && !tillName) {
    return {
      connected: false,
      label: "CONNECTING",
    };
  }

  return {
    connected: true,
    label: till.device_label || (tillCode && tillName ? `${tillCode} - ${tillName}` : tillCode || tillName),
  };
};
