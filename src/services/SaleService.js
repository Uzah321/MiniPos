// src/services/SaleService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const tillHeaders = () => {
  if (!window.location.pathname.includes("/front")) return companyHeaders();

  const tillToken = localStorage.getItem("salexa_till_token");
  const tillId = localStorage.getItem("salexa_till_id");
  const tillCode = localStorage.getItem("salexa_till_code");

  return {
    ...companyHeaders(),
    ...(tillToken ? { "X-Till-Token": tillToken } : {}),
    ...(tillId ? { "X-Till-Id": tillId } : {}),
    ...(tillCode ? { "X-Till-Code": tillCode } : {}),
  };
};

export const createSale = async (payload) => {
  const res = await api.post("/api/pos/sales", payload, {
    headers: tillHeaders(),
  });

  return res.data;
};

export const getSale = async (saleId) => {
  const res = await api.get(`/api/pos/sales/${saleId}`, {
    headers: tillHeaders(),
  });

  return res.data;
};
