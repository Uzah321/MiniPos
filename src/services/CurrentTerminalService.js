import api from "../utils/apiBack";

const FRONT_OFFICE_REQUEST = Object.freeze({
  salexaWorkspace: "front-office",
});

export const getCurrentTerminalSettings = async () => {
  const response = await api.get("/api/pos/tills/current/settings", {
    ...FRONT_OFFICE_REQUEST,
  });

  return response.data;
};

export const updateCurrentTerminalSettings = async (deviceLabel) => {
  const response = await api.patch(
    "/api/pos/tills/current/settings",
    { device_label: String(deviceLabel || "").trim() || null },
    { ...FRONT_OFFICE_REQUEST },
  );

  return response.data;
};

export const getCurrentTerminalPrinters = async () => {
  const response = await api.get("/api/pos/tills/current/printers", {
    ...FRONT_OFFICE_REQUEST,
  });

  return response.data;
};

export const createCurrentTerminalPrinter = async (payload) => {
  const response = await api.post(
    "/api/pos/tills/current/printers",
    payload,
    { ...FRONT_OFFICE_REQUEST },
  );

  return response.data;
};

export const updateCurrentTerminalPrinter = async (printerId, payload) => {
  const response = await api.put(
    `/api/pos/tills/current/printers/${encodeURIComponent(printerId)}`,
    payload,
    { ...FRONT_OFFICE_REQUEST },
  );

  return response.data;
};

export const deleteCurrentTerminalPrinter = async (printerId) => {
  const response = await api.delete(
    `/api/pos/tills/current/printers/${encodeURIComponent(printerId)}`,
    { ...FRONT_OFFICE_REQUEST },
  );

  return response.data;
};

export const getCurrentTerminalQzCertificate = async () => {
  const response = await api.get("/api/pos/tills/current/qz-certificate", {
    ...FRONT_OFFICE_REQUEST,
  });

  return response.data;
};

export const saveCurrentTerminalQzCertificate = async (payload = {}) => {
  const formData = new FormData();

  if (payload.name !== undefined && payload.name !== null) {
    formData.append("name", String(payload.name));
  }

  if (payload.notes !== undefined && payload.notes !== null) {
    formData.append("notes", String(payload.notes));
  }

  if (payload.is_active !== undefined && payload.is_active !== null) {
    formData.append("is_active", payload.is_active ? "1" : "0");
  }

  if (payload.certificate) formData.append("certificate", payload.certificate);
  if (payload.private_key) formData.append("private_key", payload.private_key);

  const response = await api.post(
    "/api/pos/tills/current/qz-certificate",
    formData,
    {
      ...FRONT_OFFICE_REQUEST,
      headers: { "Content-Type": "multipart/form-data" },
    },
  );

  return response.data;
};

export const deleteCurrentTerminalQzCertificate = async () => {
  const response = await api.delete(
    "/api/pos/tills/current/qz-certificate",
    { ...FRONT_OFFICE_REQUEST },
  );

  return response.data;
};

export const getCurrentTerminalKitchenOutput = async () => {
  const response = await api.get("/api/pos/tills/current/kitchen-output", {
    ...FRONT_OFFICE_REQUEST,
  });

  return response.data;
};

export const updateCurrentTerminalKitchenOutput = async (payload) => {
  const response = await api.put(
    "/api/pos/tills/current/kitchen-output",
    {
      mode: payload?.mode || "kitchen_printer",
      auto_print: Boolean(payload?.auto_print),
      copies: Math.max(1, Math.min(5, Number(payload?.copies || 1))),
    },
    { ...FRONT_OFFICE_REQUEST },
  );

  return response.data;
};
