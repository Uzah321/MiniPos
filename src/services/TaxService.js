// src/services/TaxService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos";

export const getTaxes = async (params = {}) => {
  const res = await api.get(`${BASE}/taxes`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getTaxDropdown = async (params = {}) => {
  const res = await api.get(`${BASE}/taxes/dropdown`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getTax = async (taxId) => {
  const res = await api.get(`${BASE}/taxes/${taxId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createTax = async (payload) => {
  const res = await api.post(`${BASE}/taxes`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateTax = async (taxId, payload) => {
  const res = await api.put(`${BASE}/taxes/${taxId}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deleteTax = async (taxId) => {
  const res = await api.delete(`${BASE}/taxes/${taxId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getProductTaxes = async (productId, params = {}) => {
  const res = await api.get(`${BASE}/products/${productId}/taxes`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const syncProductTaxes = async (productId, payload) => {
  const res = await api.post(`${BASE}/products/${productId}/taxes/sync`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const syncManyProductTaxes = async (payload) => {
  const res = await api.post(`${BASE}/products/taxes/sync-many`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deleteProductTax = async (productId, productTaxId) => {
  const res = await api.delete(
    `${BASE}/products/${productId}/taxes/${productTaxId}`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const previewProductTax = async (productId, payload) => {
  const res = await api.post(`${BASE}/products/${productId}/taxes/preview`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};
