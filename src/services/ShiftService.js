// src/services/ShiftService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

// Back Office shift lookups are management reports. They must never inherit
// the live POS terminal/till middleware.
const BASE = "/api/pos/management";

export const getShiftSessions = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/shift-sessions`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getShiftSession = async (shiftSessionId) => {
  const res = await api.get(`${BASE}/reports/shift-sessions/${shiftSessionId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getShiftSales = async (params = {}) => {
  const res = await api.get(`${BASE}/reports/shifts`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getShiftSalesDrilldown = async (shiftSessionId, params = {}) => {
  const res = await api.get(`${BASE}/reports/shifts/${shiftSessionId}/sales`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};
