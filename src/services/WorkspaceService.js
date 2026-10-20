import api from "../utils/apiBack";
import { isFrontOfficeWorkspace } from "../utils/apiConfig";
import {
  mergeWorkspaceBootstrap,
  normalizeHospitalityBootstrap,
} from "../utils/hospitalityHierarchy";

const unwrap = (response) => response?.data?.data ?? response?.data ?? response ?? {};

export const getTerminalConfigurationBootstrap = async () => {
  const response = await api.get("/api/pos/configuration/bootstrap", {
    salexaWorkspace: "front-office",
  });

  return normalizeHospitalityBootstrap(unwrap(response));
};

export const getWorkspaceBootstrap = async (
  branchId = null,
  { workspace = null } = {},
) => {
  const resolvedWorkspace = workspace || (
    isFrontOfficeWorkspace() ? "front-office" : "back-office"
  );
  const response = await api.get("/api/pos/workspace/bootstrap", {
    params: branchId ? { branch_id: branchId } : {},
    salexaWorkspace: resolvedWorkspace,
  });
  const workspacePayload = unwrap(response);

  if (resolvedWorkspace !== "front-office") {
    return normalizeHospitalityBootstrap(workspacePayload);
  }

  // Front Office must never fall back to a company-wide product response. A
  // missing or invalid terminal bootstrap means the shop/brand/menu scope is
  // unknown, so the workspace intentionally fails closed.
  const terminalConfiguration = await getTerminalConfigurationBootstrap();
  return mergeWorkspaceBootstrap(workspacePayload, terminalConfiguration);
};
