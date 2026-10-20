// src/services/ReceiptRegisterService.js

import api from "../utils/apiBack";
import { isFrontOfficeWorkspace } from "../utils/apiConfig";

// This read-only register is shared by both workspaces. The common API client
// selects the matching login and installation identity for the current page.
const workspaceConfig = (overrides = {}) => ({
  salexaWorkspace: isFrontOfficeWorkspace() ? "front-office" : "back-office",
  ...overrides,
});

const BASE = "/api/pos";

export const getReceiptRegister = async (params = {}) => {
  const res = await api.get(`${BASE}/receipts/register`, {
    ...workspaceConfig(),
    params,
  });

  return res.data;
};

export const getReceiptRegisterSale = async (saleId) => {
  const res = await api.get(`${BASE}/receipts/register/${saleId}`, {
    ...workspaceConfig(),
  });

  return res.data;
};

export const prepareReceiptPdf = async (saleId) => {
  const res = await api.get(`${BASE}/receipts/register/${saleId}/pdf`, {
    ...workspaceConfig(),
  });

  return res.data;
};


export const fetchReceiptPdfBlobByUrl = async (pdfUrlOrSaleId) => {
  /*
   * Prefer the API blob endpoint by sale ID.
   * Absolute receipt URLs can fail with CORS/baseURL/network errors.
   */
  if (pdfUrlOrSaleId && !String(pdfUrlOrSaleId).startsWith("http")) {
    return fetchReceiptPdfFile(pdfUrlOrSaleId);
  }

  const res = await api.get(pdfUrlOrSaleId, {
    ...workspaceConfig(),
    responseType: "blob",
  });

  return res.data;
};


export const fetchReceiptPdfFile = async (saleId) => {
  const res = await api.get(`${BASE}/receipts/register/${saleId}/pdf-file`, {
    ...workspaceConfig(),
    responseType: "blob",
  });

  return res.data;
};
