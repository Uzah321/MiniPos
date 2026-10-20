// src/services/ProductBarcodePrintService.js

import api from "../utils/apiBack";

const BASE = "/api/pos";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const branchHeaders = (branchId) => ({
  ...companyHeaders(),
  ...(branchId ? { "X-Branch-Id": branchId } : {}),
});

export const searchProductBarcodes = async (params = {}) => {
  const res = await api.get(`${BASE}/product-barcodes/search`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const resolveBarcodePrinter = async (params = {}) => {
  const res = await api.get(`${BASE}/product-barcodes/resolve-printer`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const prepareBarcodePrint = async (payload = {}) => {
  const res = await api.post(`${BASE}/product-barcodes/prepare-print`, payload, {
    headers: branchHeaders(payload.branch_id),
  });

  return res.data;
};

export const prepareBarcodePrintPayload = prepareBarcodePrint;
