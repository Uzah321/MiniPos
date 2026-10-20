// src/services/SystemClockService.js

import api from "../utils/apiBack";
import { companyHeaders } from "../utils/apiConfig";

const BASE = "/api/pos";

export const checkSystemClock = async ({ workspace = null, companyCode = null } = {}) => {
  const res = await api.get(`${BASE}/system/clock-check`, {
    headers: companyHeaders({ workspace, companyCode }),
    salexaWorkspace: workspace,
  });

  return res.data;
};
