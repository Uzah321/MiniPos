import api from '../utils/apiBack';
import { companyHeaders } from '../utils/apiConfig';

const tenantConfig = () => ({
  headers: companyHeaders({ workspace: "back-office" }),
  salexaWorkspace: "back-office",
});

const platformConfig = (extra = {}) => ({
  ...extra,
  headers: companyHeaders({
    workspace: "back-office",
    unscopedPlatform: true,
  }),
  salexaWorkspace: "back-office",
  salexaUnscopedPlatform: true,
});


// src/services/AuthenticationService.js

export const getResolvedCompanyBranches = async () => {
  const res = await api.get("/api/pos/branches", {
    ...tenantConfig(),
  });

  return res.data;
};

export const createResolvedCompanyBranch = async (payload) => {
  const res = await api.post("/api/pos/branches", payload, {
    ...tenantConfig(),
  });

  return res.data;
};

export const createCompanyBranch = async (companyId, payload) => {
  const res = await api.post(`/api/pos/companies/${companyId}/branches`, payload, {
    ...tenantConfig(),
  });

  return res.data;
};

export const updateBranch = async (branchId, payload) => {
  const res = await api.put(`/api/pos/branches/${branchId}`, payload, {
    ...tenantConfig(),
  });

  return res.data;
};

export const deleteBranch = async (branchId) => {
  const res = await api.delete(`/api/pos/branches/${branchId}`, {
    ...tenantConfig(),
  });

  return res.data;
};

export const createBranchContact = async (branchId, payload) => {
  const res = await api.post(`/api/pos/branches/${branchId}/contacts`, payload, {
    ...tenantConfig(),
  });

  return res.data;
};

export const updateBranchContact = async (contactId, payload) => {
  const res = await api.put(`/api/pos/branch-contacts/${contactId}`, payload, {
    ...tenantConfig(),
  });

  return res.data;
};

export const deleteBranchContact = async (contactId) => {
  const res = await api.delete(`/api/pos/branch-contacts/${contactId}`, {
    ...tenantConfig(),
  });

  return res.data;
};

// Platform administration endpoints. These routes intentionally sit outside
// the tenant-scoped /api/pos namespace and are restricted to super
// administrators by the API.
export const getPlatformCompanies = async (params = {}) => {
  const res = await api.get("/api/companies", platformConfig({ params }));

  return res.data;
};

export const getPlatformCompany = async (companyId) => {
  const res = await api.get(`/api/companies/${companyId}`, platformConfig());

  return res.data;
};

export const createPlatformCompany = async (payload) => {
  const res = await api.post("/api/companies", payload, platformConfig());

  return res.data;
};

export const updatePlatformCompany = async (companyId, payload) => {
  const multipart = typeof FormData !== "undefined" && payload instanceof FormData;
  if (multipart) payload.set("_method", "PUT");
  const res = multipart
    ? await api.post(`/api/companies/${companyId}`, payload, platformConfig())
    : await api.put(`/api/companies/${companyId}`, payload, platformConfig());

  return res.data;
};

export const deletePlatformCompany = async (companyId) => {
  const res = await api.delete(`/api/companies/${companyId}`, platformConfig());

  return res.data;
};

export const createPlatformCompanyBranch = async (companyId, payload) => {
  const res = await api.post(`/api/companies/${companyId}/branches`, payload, platformConfig());

  return res.data;
};

export const updatePlatformCompanyBranch = async (branchId, payload) => {
  const res = await api.put(`/api/company-branches/${branchId}`, payload, platformConfig());

  return res.data;
};

export const deletePlatformCompanyBranch = async (branchId) => {
  const res = await api.delete(`/api/company-branches/${branchId}`, platformConfig());

  return res.data;
};

export const getPlatformCompanyLicenses = async (companyId) => {
  const res = await api.get(`/api/companies/${companyId}/licenses`, platformConfig());

  return res.data;
};

export const renewPlatformCompanyLicense = async (companyId, payload) => {
  const res = await api.post(`/api/companies/${companyId}/licenses/renew`, payload, platformConfig());

  return res.data;
};
