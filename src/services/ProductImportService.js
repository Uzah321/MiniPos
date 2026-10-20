// src/services/ProductImportService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos";

const appendOptional = (form, key, value) => {
  if (value !== undefined && value !== null && String(value).trim() !== "") {
    form.append(key, value);
  }
};

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

const getStoredBranchId = () => {
  try {
    const user = JSON.parse(localStorage.getItem("back_user") || "{}");

    return (
      user?.selectedBranchId ||
      user?.selectedBranch?.id ||
      user?.branch_id ||
      user?.branch?.id ||
      localStorage.getItem("salexa_till_branch_id") ||
      localStorage.getItem("branch_id") ||
      localStorage.getItem("salexa_branch_id") ||
      ""
    );
  } catch {
    return (
      localStorage.getItem("salexa_till_branch_id") ||
      localStorage.getItem("branch_id") ||
      localStorage.getItem("salexa_branch_id") ||
      ""
    );
  }
};

const resolveBranchId = (payload = {}) => {
  return (
    payload.branch_id ||
    payload.branchId ||
    payload.selected_branch_id ||
    getStoredBranchId() ||
    ""
  );
};

const normalizeBooleanNumber = (value) => {
  if (value === true || value === 1 || value === "1" || value === "true") return "1";
  return "0";
};

export const importProducts = async (payload = {}) => {
  const {
    file,
    dry_run = 1,
    create_missing_categories = 0,
    create_missing_suppliers = 0,
    default_action = "upsert",
    default_currency = "",
  } = payload;

  const branchId = resolveBranchId(payload);

  if (!branchId) {
    throw new Error(
      "Branch is required for product import because stock, cost and prices are branch based."
    );
  }

  if (!file) {
    throw new Error("Product import file is required.");
  }

  const form = new FormData();

  /*
   * IMPORTANT:
   * The backend validates the upload field as "file".
   * Do not rename this to product_file/import_file.
   */
  form.append("file", file, file.name || "products.xlsx");

  form.append("branch_id", branchId);
  form.append("branchId", branchId);
  form.append("selected_branch_id", branchId);

  form.append("dry_run", normalizeBooleanNumber(dry_run));
  form.append("create_missing_categories", normalizeBooleanNumber(create_missing_categories));
  form.append("create_missing_suppliers", normalizeBooleanNumber(create_missing_suppliers));
  form.append("default_action", default_action);

  if (default_currency) {
    form.append("default_currency", default_currency);
  }

  /*
   * Do NOT manually set Content-Type: multipart/form-data.
   * Axios must set it itself with the boundary. If you set it manually,
   * Laravel may receive an empty request and say "file field is required".
   */
  const res = await api.post(`${BASE}/products/import`, form, {
    params: {
      branch_id: branchId,
    },
    headers: {
      ...companyHeaders(),
      "X-Branch-Id": branchId,
      Accept: "application/json",
    },
    transformRequest: [(data) => data],
  });

  return res.data;
};

export const downloadProductImportExcelTemplate = async () => {
  const res = await api.get(`${BASE}/products/import/template`, {
    params: {
      format: "xlsx",
    },
    responseType: "blob",
    headers: companyHeaders(),
  });

  downloadBlob(res.data, "servenest_product_import_template.xlsx");
};

export const downloadProductImportCsvTemplate = async () => {
  const res = await api.get(`${BASE}/products/import/template`, {
    params: {
      format: "csv",
    },
    responseType: "blob",
    headers: companyHeaders(),
  });

  downloadBlob(res.data, "servenest_product_import_template.csv");
};

export const getProductImportTemplateHeaders = async () => {
  const res = await api.get(`${BASE}/products/import/template-headers`, {
    headers: companyHeaders(),
  });

  return res.data;
};

const submitHospitalityWorkbook = async (action, payload = {}) => {
  const {
    file,
    brand_id: brandId,
    branch_id: branchId = "",
    currency = "",
    source_system: sourceSystem = "gaap",
    force = false,
  } = payload;

  if (!brandId) {
    throw new Error("Select a brand before importing the hospitality workbook.");
  }

  if (!file) {
    throw new Error("Select an Excel workbook first.");
  }

  const form = new FormData();
  form.append("file", file, file.name || "hospitality-bom.xlsx");
  form.append("brand_id", brandId);
  appendOptional(form, "branch_id", branchId);
  appendOptional(form, "currency", currency);
  appendOptional(form, "source_system", sourceSystem);

  if (action === "commit") {
    form.append("force", normalizeBooleanNumber(force));
  }

  const res = await api.post(`${BASE}/hospitality-imports/${action}`, form, {
    headers: {
      ...companyHeaders(),
      Accept: "application/json",
      ...(branchId ? { "X-Branch-Id": branchId } : {}),
    },
    transformRequest: [(data) => data],
  });

  return res.data;
};

export const getHospitalityImportScope = async () => {
  const res = await api.get(`${BASE}/hospitality-imports/scope`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const previewHospitalityWorkbook = (payload = {}) => (
  submitHospitalityWorkbook("preview", payload)
);

export const commitHospitalityWorkbook = (payload = {}) => (
  submitHospitalityWorkbook("commit", payload)
);
