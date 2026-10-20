import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos/qz-certificates";

/**
 * Get paginated QZ certificates.
 *
 * params example:
 * {
 *   branch_id: "...",
 *   till_id: "...",
 *   is_active: 1,
 *   page: 1,
 *   per_page: 50
 * }
 */
export const getQzCertificates = async (params = {}) => {
  const res = await api.get(BASE, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

/**
 * Get single QZ certificate by id.
 */
export const getQzCertificate = async (id) => {
  if (!id) {
    throw new Error("QZ certificate id is required");
  }

  const res = await api.get(`${BASE}/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

/**
 * Create/upload QZ certificate.
 *
 * payload example:
 * {
 *   branch_id: "...",
 *   till_id: "...",
 *   name: "Till 1 Certificate",
 *   certificate: File,
 *   private_key: File,
 *   is_active: true,
 *   notes: "Main till"
 * }
 */
export const createQzCertificate = async (payload = {}) => {
  const formData = buildQzCertificateFormData(payload);

  const res = await api.post(BASE, formData, {
    headers: {
      ...companyHeaders(),
      "Content-Type": "multipart/form-data",
    },
  });

  return res.data;
};

/**
 * Update QZ certificate.
 *
 * Because file uploads are involved, backend route uses:
 * POST /api/qz-certificates/{id}
 */
export const updateQzCertificate = async (id, payload = {}) => {
  if (!id) {
    throw new Error("QZ certificate id is required");
  }

  const formData = buildQzCertificateFormData(payload);

  const res = await api.post(`${BASE}/${id}`, formData, {
    headers: {
      ...companyHeaders(),
      "Content-Type": "multipart/form-data",
    },
  });

  return res.data;
};

/**
 * Delete QZ certificate.
 */
export const deleteQzCertificate = async (id) => {
  if (!id) {
    throw new Error("QZ certificate id is required");
  }

  const res = await api.delete(`${BASE}/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

/**
 * Build multipart/form-data for upload/update.
 */
const buildQzCertificateFormData = (payload = {}) => {
  const formData = new FormData();

  appendIfPresent(formData, "branch_id", payload.branch_id);
  appendIfPresent(formData, "till_id", payload.till_id);
  appendIfPresent(formData, "name", payload.name);
  appendIfPresent(formData, "notes", payload.notes);

  if (payload.is_active !== undefined && payload.is_active !== null) {
    formData.append("is_active", payload.is_active ? "1" : "0");
  }

  if (payload.certificate) {
    formData.append("certificate", payload.certificate);
  }

  if (payload.private_key) {
    formData.append("private_key", payload.private_key);
  }

  return formData;
};

const appendIfPresent = (formData, key, value) => {
  if (value !== undefined && value !== null && value !== "") {
    formData.append(key, value);
  }
};