// import api from '../utils/apiBack';



// /**
//  * Logs in a user via username/password or a policy-controlled login code.
//  *
//  * @param {string|null} email - User email (if using email login)
//  * @param {string|null} password - User password (if using email login)
//  * @param {string|null} code - policy-controlled POS login code
//  * @param {number} isFrontOrBack - 1 = Front Office, 2 = Back Office
//  */
// const loginUser = async (username, password, branchId ) => {
//   try {
//     const payload = {};

 
//       payload.username = username;      // username or email
//       payload.password = password;
//       payload.branch_id = branchId;
    
//     const response = await api.post(
//       "/api/pos-auth/login",
//       payload,
//       {
//        headers: {
//         "X-Company-Domain": window.location.hostname, // <-- important
//       },
//       }
//     );

//     // return JSON body directly for convenience
//     return response.data;
//   } catch (error) {
//     if (error.response?.data?.message) {
//       throw new Error(error.response.data.message);
//     } else if (error.request) {
//       throw new Error("Network error. Please check your connection.");
//     } else {
//       throw new Error(error.message || "Login failed.");
//     }
//   }
// };

// const getAuthorisedCompany = async () => {
//   try {
//     const response = await api.get("/api/pos/company", {
//       headers: {
//         "X-Company-Domain": window.location.hostname, // <-- important
//       },
//     });

//     console.log("Response for category", response.data);
//     return response.data;
//   } catch (error) {
//     console.error("Error fetching product:", error);
//     throw error;
//   }
// };

// const logout = async () => {
//   try {
//     const response = await api.post("/api/pos-auth/logout", {
//       headers: {
//         "X-Company-Domain": window.location.hostname, // <-- important
//       },
//     });

//     console.log("Response for category", response.data);
//     return response.data;
//   } catch (error) {
//     console.error("Error fetching product:", error);
//     throw error;
//   }
// };

// const companyHeaders = () => ({
//   "X-Company-Domain": window.location.hostname,
// });

// /* =========================
//    ROLES CRUD
// ========================= */

// export const getRoles = async (params = {}) => {
//   const res = await api.get("/api/pos/roles", {
//     params,
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const getRole = async (id) => {
//   const res = await api.get(`/api/pos/roles/${id}`, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const createRole = async (payload) => {
//   const res = await api.post("/api/pos/roles", payload, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const updateRole = async (id, payload) => {
//   const res = await api.put(`/api/pos/roles/${id}`, payload, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const deleteRole = async (id) => {
//   const res = await api.delete(`/api/pos/roles/${id}`, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// /* =========================
//    PERMISSIONS
// ========================= */

// export const getPermissions = async (params = {}) => {
//   const res = await api.get("/api/pos/permissions", {
//     params,
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// /* =========================
//    ROLE PERMISSIONS CRUD
// ========================= */

// export const getRolePermissions = async (roleId) => {
//   const res = await api.get(`/api/pos/roles/${roleId}/permissions`, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const assignRolePermission = async (roleId, permissionId) => {
//   const res = await api.post(
//     `/api/pos/roles/${roleId}/permissions`,
//     { permission_id: permissionId },
//     {
//       headers: companyHeaders(),
//     }
//   );

//   return res.data;
// };

// export const syncRolePermissions = async (roleId, permissionIds = []) => {
//   const res = await api.put(
//     `/api/pos/roles/${roleId}/permissions/sync`,
//     { permission_ids: permissionIds },
//     {
//       headers: companyHeaders(),
//     }
//   );

//   return res.data;
// };

// export const removeRolePermission = async (roleId, permissionId) => {
//   const res = await api.delete(
//     `/api/pos/roles/${roleId}/permissions/${permissionId}`,
//     {
//       headers: companyHeaders(),
//     }
//   );

//   return res.data;
// };

// // src/services/AuthenticationService.js

// export const getPosUsers = async (params = {}) => {
//   const res = await api.get("/api/pos-users", {
//     params,
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const createPosUser = async (payload) => {
//   const res = await api.post("/api/pos-users", payload, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const getPosUser = async (posUserId) => {
//   const res = await api.get(`/api/pos-users/${posUserId}`, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const updatePosUser = async (posUserId, payload) => {
//   const res = await api.put(`/api/pos-users/${posUserId}`, payload, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };

// export const deletePosUser = async (posUserId) => {
//   const res = await api.delete(`/api/pos-users/${posUserId}`, {
//     headers: companyHeaders(),
//   });

//   return res.data;
// };







import api from '../utils/apiBack';
import { companyHeaders } from '../utils/apiConfig';
import { normalizeLoginMode as normalizeHospitalityLoginMode } from '../utils/hospitalityHierarchy';

const cleanLoginCode = (value) => String(value || '').trim().slice(0, 12);

/**
 * Logs in a POS user.
 *
 * Supported calls:
 *   loginUser({ login_mode: 'code', login_code: '1234', branch_id })
 *   loginUser({ login_mode: 'password', username, password, branch_id })
 *   loginUser(username, password, branchId) // old password-login signature
 */
const loginUser = async (usernameOrPayload, password = null, branchId = null) => {
  try {
    let payload;

    if (usernameOrPayload && typeof usernameOrPayload === 'object' && !Array.isArray(usernameOrPayload)) {
      const requestedMode = String(usernameOrPayload.login_mode || usernameOrPayload.mode || '').toLowerCase();
      const loginCode = cleanLoginCode(
        usernameOrPayload.login_code ?? usernameOrPayload.pin_code ?? usernameOrPayload.code
      );

      if (requestedMode === 'code' || loginCode) {
        payload = {
          login_mode: 'code',
          login_code: loginCode,
          branch_id: usernameOrPayload.branch_id ?? usernameOrPayload.branchId ?? branchId,
          workspace: usernameOrPayload.workspace,
          company_code: usernameOrPayload.company_code ?? usernameOrPayload.companyCode,
          device_name: usernameOrPayload.device_name ?? usernameOrPayload.deviceName,
          time: usernameOrPayload.time,
          opening_float: usernameOrPayload.opening_float,
          continue_shift_session_id: usernameOrPayload.continue_shift_session_id,
          force_new_shift: usernameOrPayload.force_new_shift,
        };
      } else {
        payload = {
          login_mode: 'password',
          username: usernameOrPayload.username ?? usernameOrPayload.email ?? usernameOrPayload.user_name ?? '',
          password: usernameOrPayload.password ?? password ?? '',
          branch_id: usernameOrPayload.branch_id ?? usernameOrPayload.branchId ?? branchId,
          workspace: usernameOrPayload.workspace,
          company_code: usernameOrPayload.company_code ?? usernameOrPayload.companyCode,
          device_name: usernameOrPayload.device_name ?? usernameOrPayload.deviceName,
          time: usernameOrPayload.time,
          opening_float: usernameOrPayload.opening_float,
          continue_shift_session_id: usernameOrPayload.continue_shift_session_id,
          force_new_shift: usernameOrPayload.force_new_shift,
        };
      }
    } else {
      payload = {
        login_mode: 'password',
        username: usernameOrPayload,
        password,
        branch_id: branchId,
      };
    }

    const requestWorkspace = payload.workspace;
    // Back Office identity is resolved from the submitted account. A tenant
    // code must never be supplied by, or leaked through, the login screen.
    // Only the platform Super Admin may send a server-issued company id after
    // choosing a company from the authenticated selection challenge.
    const requestCompanyCode = requestWorkspace === 'back-office'
      ? null
      : payload.company_code;
    const unscopedPlatform = requestWorkspace === 'back-office';

    Object.keys(payload).forEach((key) => {
      if (payload[key] === undefined || payload[key] === null || payload[key] === '') {
        delete payload[key];
      }
    });

    const requestPayload = { ...payload };
    delete requestPayload.company_code;

    const loginEndpoint = requestWorkspace === 'front-office'
      ? '/api/pos-auth/front-office/login'
      : requestWorkspace === 'back-office'
        ? '/api/pos-auth/back-office/login'
        : '/api/pos-auth/login';

    const response = await api.post(loginEndpoint, requestPayload, {
      headers: companyHeaders({
        workspace: requestWorkspace,
        companyCode: requestCompanyCode,
        unscopedPlatform,
      }),
      salexaWorkspace: requestWorkspace,
      salexaUnscopedPlatform: unscopedPlatform,
    });

    return response.data;
  } catch (error) {
    if (error.request) {
      error.displayMessage = error.response?.data?.message
        || (error.response ? error.message : 'Network error. Please check your connection.');
    }

    throw error;
  }
};

export const selectBackOfficeCompany = async ({
  selectionToken,
  companyId,
  branchId = null,
} = {}) => {
  const token = String(selectionToken || '').trim();
  const company = String(companyId || '').trim();

  if (!token || !company) {
    throw new Error('The secure company selection has expired. Sign in again.');
  }

  const requestPayload = { company_id: company };
  if (branchId) requestPayload.branch_id = branchId;

  const response = await api.post(
    '/api/pos-auth/back-office/select-company',
    requestPayload,
    {
      headers: {
        ...companyHeaders({ workspace: 'back-office', unscopedPlatform: true }),
        Authorization: `Bearer ${token}`,
      },
      salexaWorkspace: 'back-office',
      salexaUnscopedPlatform: true,
      servenestBootstrapAuthorization: true,
    },
  );

  return response.data;
};

const getAuthorisedCompany = async ({ workspace = null, companyCode = null } = {}) => {
  try {
    const response = await api.get('/api/pos/company', {
      headers: companyHeaders({ workspace, companyCode }),
      salexaWorkspace: workspace,
    });

    return response.data;
  } catch (error) {
    console.error('Error fetching company:', error);
    throw error;
  }
};

const logout = async () => {
  try {
    const response = await api.post('/api/pos-auth/logout', {}, {
      headers: companyHeaders(),
    });

    return response.data;
  } catch (error) {
    console.error('Error logging out:', error);
    throw error;
  }
};

/* =========================
   LOGIN SETTINGS
========================= */

export const getPosLoginModeSetting = async ({
  workspace = null,
  companyCode = null,
  includePolicy = false,
} = {}) => {
  const fallback = 'password';

  const normalizeCredentialPolicy = (source) => {
    const raw = source?.credential_policy?.login_code
      ?? source?.authentication?.credential_policy?.login_code
      ?? source?.configuration?.authentication?.credential_policy?.login_code
      ?? source?.data?.credential_policy?.login_code
      ?? source?.data?.authentication?.credential_policy?.login_code
      ?? source?.data?.configuration?.authentication?.credential_policy?.login_code
      ?? null;

    if (!raw || typeof raw !== 'object') return null;

    const minimumLength = Math.max(4, Math.min(12, Number(raw.minimum_length ?? 4) || 4));
    const maximumLength = Math.max(
      minimumLength,
      Math.min(12, Number(raw.maximum_length ?? 8) || 8),
    );

    return {
      minimum_length: minimumLength,
      maximum_length: maximumLength,
      numeric_only: raw.numeric_only !== false,
      reject_repeated_digits: raw.reject_repeated_digits === true,
      reject_sequences: raw.reject_sequences === true,
    };
  };

  const extractValue = (source) => {
    const payload = source?.data ?? source;

    if (!payload) return null;

    if (typeof payload === 'string') return payload;

    if (payload.login_mode !== undefined) return payload.login_mode;
    if (payload.authentication?.pos_login_mode !== undefined) return payload.authentication.pos_login_mode;
    if (payload.authentication?.login_mode !== undefined) return payload.authentication.login_mode;
    if (payload.configuration?.authentication?.pos_login_mode !== undefined) {
      return payload.configuration.authentication.pos_login_mode;
    }
    if (payload.data?.authentication?.pos_login_mode !== undefined) return payload.data.authentication.pos_login_mode;
    if (payload.data?.configuration?.authentication?.pos_login_mode !== undefined) {
      return payload.data.configuration.authentication.pos_login_mode;
    }
    if (payload.setting_value !== undefined) return payload.setting_value;
    if (payload.value !== undefined) return payload.value;
    if (payload.data?.login_mode !== undefined) return payload.data.login_mode;
    if (payload.data?.setting_value !== undefined) return payload.data.setting_value;
    if (payload.data?.value !== undefined) return payload.data.value;

    const rows = Array.isArray(payload) ? payload : Array.isArray(payload.data) ? payload.data : [];
    const row = rows.find((item) => item?.setting_key === 'pos_login_mode');

    return row?.login_mode ?? row?.setting_value ?? row?.value ?? null;
  };

  const normalize = (value) => {
    return normalizeHospitalityLoginMode(value);
  };

  const params = {};

  const attempts = [
    ...(workspace === 'front-office' ? [
      () => api.get('/api/pos/configuration/bootstrap', {
        headers: companyHeaders({ workspace: 'front-office' }),
        salexaWorkspace: 'front-office',
      }),
      () => api.get('/api/pos-auth/front-office/login-mode', {
        headers: companyHeaders({ workspace: 'front-office' }),
        salexaWorkspace: 'front-office',
      }),
    ] : []),
    () => api.get('/api/pos-auth/login-mode', {
      params,
      headers: companyHeaders({ workspace, companyCode }),
      salexaWorkspace: workspace,
    }),
    () => api.get('/api/general-settings', {
      params: { module: 'security', setting_key: 'pos_login_mode' },
      headers: companyHeaders({ workspace, companyCode }),
      salexaWorkspace: workspace,
    }),
    () => api.get('/api/general-settings/pos_login_mode', {
      params: { module: 'security' },
      headers: companyHeaders({ workspace, companyCode }),
      salexaWorkspace: workspace,
    }),
    () => api.get('/api/settings', {
      params: { module: 'security', setting_key: 'pos_login_mode' },
      headers: companyHeaders({ workspace, companyCode }),
      salexaWorkspace: workspace,
    }),
  ];

  for (const attempt of attempts) {
    try {
      const response = await attempt();
      const value = extractValue(response.data);
      if (value !== null && value !== undefined && value !== '') {
        const loginMode = normalize(value);
        if (!includePolicy) return loginMode;

        return {
          login_mode: loginMode,
          credential_policy: {
            login_code: normalizeCredentialPolicy(response.data),
          },
        };
      }
    } catch {
      // Try the next endpoint, then fall back to password mode.
    }
  }

  return includePolicy
    ? { login_mode: fallback, credential_policy: { login_code: null } }
    : fallback;
};

/* =========================
   ROLES CRUD
========================= */

export const getRoles = async (params = {}) => {
  const res = await api.get('/api/pos/roles', {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getRole = async (id) => {
  const res = await api.get(`/api/pos/roles/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const createRole = async (payload) => {
  const res = await api.post('/api/pos/roles', payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updateRole = async (id, payload) => {
  const res = await api.put(`/api/pos/roles/${id}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deleteRole = async (id) => {
  const res = await api.delete(`/api/pos/roles/${id}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

/* =========================
   PERMISSIONS
========================= */

export const getPermissions = async (params = {}) => {
  const res = await api.get('/api/pos/permissions', {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

/* =========================
   ROLE PERMISSIONS CRUD
========================= */

export const getRolePermissions = async (roleId) => {
  const res = await api.get(`/api/pos/roles/${roleId}/permissions`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const assignRolePermission = async (roleId, permissionId) => {
  const res = await api.post(
    `/api/pos/roles/${roleId}/permissions`,
    { permission_id: permissionId },
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const syncRolePermissions = async (roleId, permissionIds = []) => {
  const res = await api.put(
    `/api/pos/roles/${roleId}/permissions/sync`,
    { permission_ids: permissionIds },
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

export const removeRolePermission = async (roleId, permissionId) => {
  const res = await api.delete(
    `/api/pos/roles/${roleId}/permissions/${permissionId}`,
    {
      headers: companyHeaders(),
    }
  );

  return res.data;
};

// src/services/AuthenticationService.js

export const getPosUsers = async (params = {}) => {
  const res = await api.get('/api/pos-users', {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const createPosUser = async (payload) => {
  const res = await api.post('/api/pos-users', payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPosUser = async (posUserId) => {
  const res = await api.get(`/api/pos-users/${posUserId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const updatePosUser = async (posUserId, payload) => {
  const res = await api.put(`/api/pos-users/${posUserId}`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const deletePosUser = async (posUserId) => {
  const res = await api.delete(`/api/pos-users/${posUserId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export { loginUser, getAuthorisedCompany, logout };
