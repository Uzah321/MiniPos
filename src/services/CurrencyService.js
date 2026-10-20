// src/services/CurrencyService.js

import api from "../utils/apiBack";
import { isFrontOfficeWorkspace } from "../utils/apiConfig";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

// =============================
// CURRENCIES
// =============================

export const getCurrencies = async (params = {}) => {
  const res = await api.get("/api/pos/currencies", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getCurrency = async (currencyId) => {
  const res = await api.get(`/api/pos/currencies/${currencyId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createCurrency = async (payload) => {
  const res = await api.post("/api/pos/currencies", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateCurrency = async (currencyId, payload) => {
  const res = await api.put(`/api/pos/currencies/${currencyId}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deleteCurrency = async (currencyId) => {
  const res = await api.delete(`/api/pos/currencies/${currencyId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const addCurrencyRate = async (currencyId, payload) => {
  const res = await api.post(`/api/pos/currencies/${currencyId}/rates`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getBaseCurrencies = async (params = {}) => {
  const res = await api.get("/api/pos/currencies", {
    params: {
      ...params,
      is_active: true,
      is_base_currency: true,
      per_page: 200,
    },
    headers: companyHeaders(),
  });

  return res.data;
};

export const convertCurrency = async ({ from, to, amount }) => {
  const activeWorkspace = isFrontOfficeWorkspace() ? "front-office" : "back-office";
  const res = await api.post(
    "/api/pos/currencies/convert",
    {
      from,
      to,
      amount,
    },
    {
      salexaWorkspace: activeWorkspace,
      headers: companyHeaders(),
    }
  );

  return res.data;
};
