// src/services/PaymentTypeService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

// =============================
// PAYMENT TYPES
// =============================

export const getPaymentTypes = async (params = {}) => {
  const res = await api.get("/api/pos/payment-types", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPaymentType = async (paymentTypeId) => {
  const res = await api.get(`/api/pos/payment-types/${paymentTypeId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createPaymentType = async (payload) => {
  const res = await api.post("/api/pos/payment-types", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updatePaymentType = async (paymentTypeId, payload) => {
  const res = await api.put(`/api/pos/payment-types/${paymentTypeId}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deletePaymentType = async (paymentTypeId) => {
  const res = await api.delete(`/api/pos/payment-types/${paymentTypeId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};