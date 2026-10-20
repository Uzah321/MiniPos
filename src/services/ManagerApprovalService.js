// src/services/ManagerApprovalService.js

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

const approvalHeaders = (branchId) => ({
  ...companyHeaders(),
  ...(branchId || getBranchId() ? { "X-Branch-Id": branchId || getBranchId() } : {}),
});

const BASE = "/api/pos";

export const verifyManagerApproval = async (payload = {}) => {
  const branchId = payload.branch_id || payload?.metadata?.branch_id || getBranchId();

  const res = await api.post(
    `${BASE}/manager-approvals/verify`,
    {
      ...payload,
      ...(branchId ? { branch_id: branchId } : {}),
      metadata: {
        ...(payload.metadata || {}),
        ...(branchId ? { branch_id: branchId } : {}),
      },
    },
    {
      headers: approvalHeaders(branchId),
    }
  );

  return res.data;
};
