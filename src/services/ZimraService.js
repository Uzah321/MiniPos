// src/services/ZimraService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos/zimra";

export const getZimraSettings = async () => {
  const res = await api.get(`${BASE}/settings`, { headers: companyHeaders() });
  return res.data;
};

export const updateZimraSettings = async (payload) => {
  const res = await api.put(`${BASE}/settings`, payload, { headers: companyHeaders() });
  return res.data;
};

export const getFiscalDevices = async (params = {}) => {
  const res = await api.get(`${BASE}/devices`, { params, headers: companyHeaders() });
  return res.data;
};

export const saveFiscalDevice = async (payload) => {
  const formData = new FormData();
  Object.entries(payload || {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null) formData.append(key, value);
  });
  const res = await api.post(`${BASE}/devices`, formData, {
    headers: { ...companyHeaders(), "Content-Type": "multipart/form-data" },
  });
  return res.data;
};

export const syncFiscalDeviceConfig = async (deviceId) => {
  const res = await api.post(`${BASE}/devices/${deviceId}/sync-config`, {}, { headers: companyHeaders() });
  return res.data;
};

export const getFiscalDeviceStatus = async (deviceId) => {
  const res = await api.get(`${BASE}/devices/${deviceId}/status`, { headers: companyHeaders() });
  return res.data;
};

export const getFiscalDays = async (params = {}) => {
  const res = await api.get(`${BASE}/fiscal-days`, { params, headers: companyHeaders() });
  return res.data;
};

export const openFiscalDay = async (payload) => {
  const res = await api.post(`${BASE}/fiscal-days/open`, payload, { headers: companyHeaders() });
  return res.data;
};

export const closeFiscalDay = async (fiscalDayId) => {
  const res = await api.post(`${BASE}/fiscal-days/${fiscalDayId}/close`, {}, { headers: companyHeaders() });
  return res.data;
};

export const pollFiscalDay = async (fiscalDayId) => {
  const res = await api.post(`${BASE}/fiscal-days/${fiscalDayId}/poll`, {}, { headers: companyHeaders() });
  return res.data;
};

export const getZimraReceipts = async (params = {}) => {
  const res = await api.get(`${BASE}/receipts`, { params, headers: companyHeaders() });
  return res.data;
};

export const retryZimraSale = async (saleId) => {
  const res = await api.post(`${BASE}/sales/${saleId}/retry`, {}, { headers: companyHeaders() });
  return res.data;
};
