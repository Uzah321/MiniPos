// src/services/PrinterService.js

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

export const getPrinters = async (params = {}) => {
  const res = await api.get("/api/pos/printers", {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPrinter = async (printerId) => {
  const res = await api.get(`/api/pos/printers/${printerId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createPrinter = async (payload) => {
  const res = await api.post("/api/pos/printers", payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updatePrinter = async (printerId, payload) => {
  const res = await api.put(`/api/pos/printers/${printerId}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deletePrinter = async (printerId) => {
  const res = await api.delete(`/api/pos/printers/${printerId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const resolvePrinterForPrinting = async (params = {}) => {
  const res = await api.get("/api/pos/printers/resolve", {
    params,
    headers: tillHeaders(),
  });

  return res.data;
};
