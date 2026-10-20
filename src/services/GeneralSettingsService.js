import api from '../utils/apiBack';

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos";

const getActiveWorkspaceBranchId = () => {
  const isFrontOffice = window.location.pathname.includes("/front");
  const storageKey = isFrontOffice ? "front_user" : "back_user";

  try {
    const user = JSON.parse(localStorage.getItem(storageKey) || "null");
    return user?.selectedBranchId || user?.selectedBranch?.id || user?.branch_id || null;
  } catch {
    return null;
  }
};

// src/services/AuthenticationService.js

export const getBranchSettings = async (branchId) => {
  const res = await api.get(`/api/pos/branches/${branchId}/settings`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateBranchSetting = async (branchId, settingKey, payload) => {
  const res = await api.put(
    `/api/pos/branches/${branchId}/settings/${settingKey}`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const resetBranchSetting = async (branchId, settingKey) => {
  const res = await api.delete(
    `/api/pos/branches/${branchId}/settings/${settingKey}`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const syncBranchSettings = async (branchId, settings = []) => {
  const res = await api.put(
    `/api/pos/branches/${branchId}/settings-sync`,
    { settings },
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

// SHIFT CONFIGURATION SERVICES

export const getShiftConfigurations = async (params = {}) => {
  const res = await api.get("/api/pos/shift-configurations", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const createShiftConfiguration = async (payload) => {
  const res = await api.post("/api/pos/shift-configurations", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getShiftConfiguration = async (shiftConfigurationId) => {
  const res = await api.get(
    `/api/pos/shift-configurations/${shiftConfigurationId}`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const updateShiftConfiguration = async (
  shiftConfigurationId,
  payload
) => {
  const res = await api.put(
    `/api/pos/shift-configurations/${shiftConfigurationId}`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const deleteShiftConfiguration = async (shiftConfigurationId) => {
  const res = await api.delete(
    `/api/pos/shift-configurations/${shiftConfigurationId}`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const getCurrentShiftConfiguration = async (params = {}) => {
  const res = await api.get("/api/pos/shift-configurations/current", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const detectCurrentShift = async (payload) => {
  const res = await api.post(
    "/api/pos/shift-configurations/detect-current-shift",
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

// LICENSE SERVICES

export const getCompanyLicenses = async (companyId) => {
  const res = await api.get(`/api/pos/companies/${companyId}/licenses`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getLicenseStatus = async () => {
  const res = await api.get(`/api/pos/license/status`, {
    headers: companyHeaders(),
  });
  const payload = res.data || {};

  if (!payload.server_date && res.headers?.date) {
    const serverDate = new Date(res.headers.date);

    if (!Number.isNaN(serverDate.getTime())) {
      payload.server_date = serverDate.toISOString().slice(0, 10);
    }
  }

  return payload;
};

export const renewLicenseOffline = async (payload) => {
  const res = await api.post(`/api/pos/licenses/renew-offline`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};





const boolValue = (value) =>
  value === true ||
  value === 1 ||
  value === "1" ||
  String(value || "").toLowerCase() === "true" ||
  String(value || "").toLowerCase() === "yes";

const normalizeSetting = (setting, fallbackKey) => ({
  ...(setting || {}),
  setting_key: setting?.setting_key || fallbackKey,
  is_effectively_enabled:
    boolValue(setting?.is_active ?? true) &&
    boolValue(
      setting?.setting_value ??
        setting?.value ??
        setting?.enabled ??
        setting?.is_enabled ??
        setting?.is_effectively_enabled ??
        false
    ),
});

export const getBarcodeScanSetting = async (params = {}) => {
  const res = await api.get(`${BASE}/general-settings/barcode-scan`, {
    params,
    headers: companyHeaders(),
  });

  return normalizeSetting(res.data?.data || res.data || {}, "barcode_scan_enabled");
};
export const getSalesScreenLayoutSetting = async (params = {}) => {
  const branchId = params.branch_id || getActiveWorkspaceBranchId();
  const response = await api.get(`${BASE}/general-settings/sales-screen-layout`, {
    params: {
      ...params,
      ...(branchId ? { branch_id: branchId } : {}),
    },
    headers: companyHeaders(),
  });

  return response.data?.data || response.data;
};

export const updateSalesScreenLayoutSetting = async (payload) => {
  const response = await api.post(
   `${BASE}/general-settings/sales-screen-layout`,
    payload
  );

  return response.data?.data || response.data;
};
export const getTouchKeyboardSetting = async (params = {}) => {
  const res = await api.get(`${BASE}/general-settings/touch-keyboard`, {
    params,
    headers: companyHeaders(),
  });

  return normalizeSetting(res.data?.data || res.data || {}, "touch_keyboard_enabled");
};

export const getGeneralSettingByKey = async ({
  setting_key,
  module = null,
  branch_id = null,
} = {}) => {
  const res = await api.get(`${BASE}/general-settings/show`, {
    params: {
      setting_key,
      module,
      branch_id,
    },
    headers: companyHeaders(),
  });

  return res.data?.data || res.data || null;
};
