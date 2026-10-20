// src/services/StockTransferService.js

import api from "../utils/apiBack";

const BASE = "/api/pos";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const branchHeaders = (branchId) => ({
  ...companyHeaders(),
  ...(branchId ? { "X-Branch-Id": branchId } : {}),
});

const downloadBlob = (blob, filename) => {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  setTimeout(() => URL.revokeObjectURL(url), 1000);
};

export const getStockTransfers = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-transfers`, {
    params,
    headers: branchHeaders(params.from_branch_id || params.to_branch_id),
  });

  return res.data;
};

export const getStockTransfer = async (id) => {
  const res = await api.get(`${BASE}/stock-transfers/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createStockTransfer = async (payload = {}) => {
  const res = await api.post(`${BASE}/stock-transfers`, payload, {
    headers: branchHeaders(payload.from_branch_id),
  });

  return res.data;
};

export const updateStockTransfer = async (id, payload = {}) => {
  const res = await api.put(`${BASE}/stock-transfers/${id}`, payload, {
    headers: branchHeaders(payload.from_branch_id),
  });

  return res.data;
};

export const approveStockTransfer = async (id, payload = {}) => {
  const res = await api.post(`${BASE}/stock-transfers/${id}/approve`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const rejectStockTransfer = async (id, payload = {}) => {
  const res = await api.post(`${BASE}/stock-transfers/${id}/reject`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const dispatchStockTransfer = async (id, payload = {}) => {
  const res = await api.post(`${BASE}/stock-transfers/${id}/dispatch`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const receiveStockTransfer = async (id, payload = {}) => {
  const res = await api.post(`${BASE}/stock-transfers/${id}/receive`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const cancelStockTransfer = async (id, payload = {}) => {
  const res = await api.post(`${BASE}/stock-transfers/${id}/cancel`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const searchTransferProducts = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-transfer-products`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const getStockTransferDashboard = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-transfers/reports/dashboard`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockTransferReport = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-transfers/reports/transfers`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockTransferLineReport = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-transfers/reports/lines`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockTransferBranchReport = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-transfers/reports/branch`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getStockTransferAgingReport = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-transfers/reports/aging`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const exportStockTransferReport = async (params = {}) => {
  const format = params.format || "xlsx";
  const type = params.type || "transfers";

  const res = await api.get(`${BASE}/stock-transfers/reports/export`, {
    params,
    responseType: "blob",
    headers: companyHeaders(),
  });

  downloadBlob(res.data, `stock_transfer_${type}_report.${format}`);
};
