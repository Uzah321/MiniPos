import api from "../utils/apiBack";
import { companyHeaders } from "../utils/apiConfig";

const backOfficeConfig = (extra = {}) => ({
  ...extra,
  headers: {
    ...companyHeaders({ workspace: "back-office" }),
    ...(extra.headers || {}),
  },
  salexaWorkspace: "back-office",
});

const responseData = async (request) => (await request).data;

export const listAccessAssignments = (params = {}) => responseData(
  api.get("/api/auth-admin/access-assignments", backOfficeConfig({ params })),
);

export const grantAccessAssignment = (payload) => responseData(
  api.post("/api/auth-admin/access-assignments", payload, backOfficeConfig()),
);

export const revokeAccessAssignment = (assignmentId, payload = {}) => responseData(
  api.post(
    `/api/auth-admin/access-assignments/${encodeURIComponent(assignmentId)}/revoke`,
    payload,
    backOfficeConfig(),
  ),
);

export const listAuthenticationPolicies = (params = {}) => responseData(
  api.get("/api/auth-admin/policies", backOfficeConfig({ params })),
);

export const getEffectiveAuthenticationPolicy = (params) => responseData(
  api.get("/api/auth-admin/policies/effective", backOfficeConfig({ params })),
);

export const publishAuthenticationPolicy = (payload) => responseData(
  api.post("/api/auth-admin/policies", payload, backOfficeConfig()),
);

export const disableAuthenticationPolicy = (policyId) => responseData(
  api.post(
    `/api/auth-admin/policies/${encodeURIComponent(policyId)}/disable`,
    {},
    backOfficeConfig(),
  ),
);
