// src/services/ReceiptActionService.js

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

const BASE = "/api/pos";

export const findReceiptForAction = async (invoiceNumber) => {
  const res = await api.get(`${BASE}/receipts/find`, {
    params: {
      invoice_number: invoiceNumber,
    },
    headers: getTillHeaders(),
  });

  return res.data;
};

export const reprintReceipt = async ({ invoice_number, sale_id } = {}) => {
  const res = await api.post(
    `${BASE}/receipts/reprint`,
    {
      invoice_number,
      sale_id,
    },
    {
      headers: getTillHeaders(),
    }
  );

  return res.data;
};

export const cancelReceipt = async (payload = {}) => {
  const res = await api.post(`${BASE}/receipts/cancel`, payload, {
    headers: getTillHeaders(),
  });

  return res.data;
};

export const getCancelledReceipts = async (params = {}) => {
  const res = await api.get(`${BASE}/receipts/cancelled`, {
    params,
    headers: getTillHeaders(),
  });

  return res.data;
};
