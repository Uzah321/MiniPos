// src/services/PriceLevelOverrideService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const getBranchId = () => {
  try {
    return (
      localStorage.getItem("selectedBranchId") ||
      localStorage.getItem("branch_id") ||
      JSON.parse(localStorage.getItem("selectedBranch") || "{}")?.id ||
      ""
    );
  } catch {
    return "";
  }
};

const branchHeaders = (branchId) => ({
  ...companyHeaders(),
  ...(branchId || getBranchId() ? { "X-Branch-Id": branchId || getBranchId() } : {}),
});

const BASE = "/api/pos";

export const getPriceLevelOverrideConfig = async (params = {}) => {
  const branchId = params.branch_id || getBranchId();

  const res = await api.get(`${BASE}/price-level-overrides/config`, {
    params: { ...params, ...(branchId ? { branch_id: branchId } : {}) },
    headers: branchHeaders(branchId),
  });

  return res.data;
};

export const getProductOverridePriceLevels = async (productId, params = {}) => {
  const branchId = params.branch_id || getBranchId();

  const res = await api.get(`${BASE}/price-level-overrides/products/${productId}/levels`, {
    params: { ...params, ...(branchId ? { branch_id: branchId } : {}) },
    headers: branchHeaders(branchId),
  });

  return res.data;
};

export const applyPriceLevelOverride = async (payload = {}) => {
  const branchId = payload.branch_id || getBranchId();

  const res = await api.post(
    `${BASE}/price-level-overrides/apply`,
    {
      ...payload,
      ...(branchId ? { branch_id: branchId } : {}),
      metadata: {
        ...(payload.metadata || {}),
        ...(branchId ? { branch_id: branchId } : {}),
      },
    },
    { headers: branchHeaders(branchId) }
  );

  return res.data;
};
