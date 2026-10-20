import api from "../utils/apiBack";

const BASE = "/api/pos/kitchen-print-jobs";
const FRONT_OFFICE_REQUEST = Object.freeze({ salexaWorkspace: "front-office" });

const unwrap = (response) => response?.data?.data ?? response?.data ?? response;

export const getKitchenPrintJobs = async (params = {}) => {
  const response = await api.get(BASE, { ...FRONT_OFFICE_REQUEST, params });
  return unwrap(response);
};

export const acknowledgeKitchenPrintJob = async (jobId, status, errorMessage = null) => {
  const response = await api.post(
    `${BASE}/${encodeURIComponent(jobId)}/acknowledge`,
    {
      status,
      ...(errorMessage ? { error_message: String(errorMessage).slice(0, 1000) } : {}),
    },
    FRONT_OFFICE_REQUEST,
  );
  return unwrap(response);
};

export const retryKitchenPrintJob = async (jobId) => {
  const response = await api.post(
    `${BASE}/${encodeURIComponent(jobId)}/retry`,
    {},
    FRONT_OFFICE_REQUEST,
  );
  return unwrap(response);
};

