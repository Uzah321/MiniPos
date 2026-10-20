import api from "../utils/apiBack";
import { companyHeaders } from "../utils/apiConfig";

const platformConfig = (extra = {}) => ({
  ...extra,
  headers: companyHeaders({
    workspace: "back-office",
    unscopedPlatform: true,
  }),
  salexaWorkspace: "back-office",
  salexaUnscopedPlatform: true,
});

const requestData = async (request) => (await request).data;

const isFormData = (payload) => (
  typeof FormData !== "undefined" && payload instanceof FormData
);

const createRecord = (resource, payload) => requestData(
  api.post(`/api/enterprise/${resource}`, payload, platformConfig()),
);

const updateRecord = (resource, recordId, payload) => {
  if (isFormData(payload)) {
    payload.set("_method", "PATCH");
    return requestData(
      api.post(`/api/enterprise/${resource}/${recordId}`, payload, platformConfig()),
    );
  }

  return requestData(
    api.patch(`/api/enterprise/${resource}/${recordId}`, payload, platformConfig()),
  );
};

const deleteRecord = (resource, recordId) => requestData(
  api.delete(`/api/enterprise/${resource}/${recordId}`, platformConfig()),
);

export const getManagedHierarchyRecords = (resource, params = {}) => requestData(
  api.get(`/api/enterprise/${resource}`, platformConfig({ params })),
);

export const getManagedHierarchyRecord = (resource, recordId) => requestData(
  api.get(`/api/enterprise/${resource}/${recordId}`, platformConfig()),
);

export const createManagedHierarchyRecord = (resource, payload) => (
  createRecord(resource, payload)
);

export const updateManagedHierarchyRecord = (resource, recordId, payload) => (
  updateRecord(resource, recordId, payload)
);

export const deleteManagedHierarchyRecord = (resource, recordId) => (
  deleteRecord(resource, recordId)
);

export const getEnterpriseHierarchy = (params = {}) => requestData(
  api.get("/api/enterprise/hierarchy", platformConfig({ params })),
);

export const getTenantEnterpriseHierarchy = (params = {}) => requestData(
  api.get("/api/pos/enterprise/hierarchy", {
    params,
    headers: companyHeaders({ workspace: "back-office" }),
    salexaWorkspace: "back-office",
  }),
);

export const getEnterpriseCountries = (params = {}) => requestData(
  api.get("/api/enterprise/countries", platformConfig({ params })),
);

export const createEnterpriseCountry = (payload) => requestData(
  api.post("/api/enterprise/countries", payload, platformConfig()),
);

export const updateEnterpriseCountry = (countryId, payload) => requestData(
  api.put(`/api/enterprise/countries/${countryId}`, payload, platformConfig()),
);

export const deleteEnterpriseCountry = (countryId) => requestData(
  api.delete(`/api/enterprise/countries/${countryId}`, platformConfig()),
);

export const getCountryCompanies = (countryId, params = {}) => requestData(
  api.get(`/api/enterprise/countries/${countryId}/companies`, platformConfig({ params })),
);

export const assignCompanyToCountry = (countryId, companyId, payload = {}) => requestData(
  api.put(
    `/api/enterprise/countries/${countryId}/companies/${companyId}`,
    payload,
    platformConfig(),
  ),
);

export const getCompanyBrands = (companyId, params = {}) => requestData(
  api.get(`/api/enterprise/companies/${companyId}/brands`, platformConfig({ params })),
);

export const createEnterpriseBrand = (companyId, payload) => requestData(
  api.post(`/api/enterprise/companies/${companyId}/brands`, payload, platformConfig()),
);

export const updateEnterpriseBrand = (brandId, payload) => requestData(
  api.put(`/api/enterprise/brands/${brandId}`, payload, platformConfig()),
);

export const deleteEnterpriseBrand = (brandId) => requestData(
  api.delete(`/api/enterprise/brands/${brandId}`, platformConfig()),
);

export const getBrandComplexes = (brandId, params = {}) => requestData(
  api.get(`/api/enterprise/brands/${brandId}/complexes`, platformConfig({ params })),
);

export const createEnterpriseComplex = (brandId, payload) => requestData(
  api.post(`/api/enterprise/brands/${brandId}/complexes`, payload, platformConfig()),
);

export const updateEnterpriseComplex = (complexId, payload) => requestData(
  api.put(`/api/enterprise/complexes/${complexId}`, payload, platformConfig()),
);

export const deleteEnterpriseComplex = (complexId) => requestData(
  api.delete(`/api/enterprise/complexes/${complexId}`, platformConfig()),
);

export const getComplexShops = (complexId, params = {}) => requestData(
  api.get(`/api/enterprise/complexes/${complexId}/shops`, platformConfig({ params })),
);

export const createEnterpriseShop = (complexId, payload) => requestData(
  api.post(`/api/enterprise/complexes/${complexId}/shops`, payload, platformConfig()),
);

export const updateEnterpriseShop = (shopId, payload) => requestData(
  api.put(`/api/enterprise/shops/${shopId}`, payload, platformConfig()),
);

export const deleteEnterpriseShop = (shopId) => requestData(
  api.delete(`/api/enterprise/shops/${shopId}`, platformConfig()),
);

export const getShopTills = (shopId, params = {}) => requestData(
  api.get(`/api/enterprise/shops/${shopId}/tills`, platformConfig({ params })),
);

export const createEnterpriseTill = (shopId, payload) => requestData(
  api.post(`/api/enterprise/shops/${shopId}/tills`, payload, platformConfig()),
);

export const getShopMenuItems = (shopId, params = {}) => requestData(
  api.get(`/api/enterprise/shops/${shopId}/menu-items`, platformConfig({
    params: { channel: "POS", ...params },
  })),
);

export const updateShopMenuItemVisibility = (shopId, productId, payload) => requestData(
  api.put(
    `/api/enterprise/shops/${shopId}/menu-items/${productId}`,
    payload,
    platformConfig(),
  ),
);

