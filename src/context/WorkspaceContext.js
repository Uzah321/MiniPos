import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { getWorkspaceBootstrap } from "../services/WorkspaceService";

const WorkspaceContext = createContext(null);
const MODE_KEY = "salexa_business_mode";

const readStoredBranch = (workspace) => {
  const userKey = workspace === "front-office" ? "front_user" : "back_user";

  try {
    const user = JSON.parse(localStorage.getItem(userKey) || "null");
    return user?.selectedShopId
      || user?.selectedShop?.id
      || user?.shop_id
      || user?.selectedBranchId
      || user?.selectedBranch?.id
      || user?.branch_id
      || null;
  } catch {
    return null;
  }
};

const platformWorkspace = {
  company: null,
  branch: null,
  user: null,
  operation: { business_mode: "platform" },
  capabilities: {},
  devices: {},
};

export function WorkspaceProvider({
  children,
  workspace = "back-office",
  platformOnly = false,
}) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const refresh = useCallback(async (branchId = null) => {
    if (platformOnly) {
      setData(platformWorkspace);
      setError("");
      setLoading(false);
      return platformWorkspace;
    }

    setLoading(true);
    try {
      const payload = await getWorkspaceBootstrap(
        branchId || readStoredBranch(workspace),
        { workspace },
      );
      sessionStorage.setItem(MODE_KEY, "hospitality");
      setData(payload);
      setError("");
      return payload;
    } catch (requestError) {
      setError(requestError?.displayMessage || requestError?.message || "Workspace configuration could not be loaded.");
      throw requestError;
    } finally {
      setLoading(false);
    }
  }, [platformOnly, workspace]);

  useEffect(() => {
    if (platformOnly) {
      setData(platformWorkspace);
      setError("");
      setLoading(false);
      return undefined;
    }

    refresh().catch(() => {});

    const handleWorkspaceChange = (event) => {
      const activeBranchId = readStoredBranch(workspace);
      const changedBranchId = event?.detail?.branchId;

      if (
        changedBranchId != null &&
        String(changedBranchId) !== String(activeBranchId)
      ) {
        return;
      }

      refresh(activeBranchId).catch(() => {});
    };
    window.addEventListener("salexa_workspace_changed", handleWorkspaceChange);
    return () => window.removeEventListener("salexa_workspace_changed", handleWorkspaceChange);
  }, [platformOnly, refresh, workspace]);

  const value = useMemo(() => {
    const mode = platformOnly ? "platform" : "hospitality";
    const hierarchy = data?.hierarchy || {};
    const shop = hierarchy.shop || data?.shop || data?.branch || null;
    return {
      workspace,
      data,
      hierarchy,
      country: hierarchy.country || data?.country || null,
      company: hierarchy.company || data?.company || null,
      brand: hierarchy.brand || data?.brand || null,
      complex: hierarchy.complex || data?.complex || null,
      shop,
      // Compatibility alias for report/services that still name a shop a branch.
      branch: shop,
      till: hierarchy.till || data?.till || null,
      user: data?.user || null,
      operation: data?.operation || {},
      capabilities: data?.capabilities || {},
      devices: data?.devices || {},
      authentication: data?.authentication || {},
      branding: data?.branding || {},
      menu: data?.menu || { items: [], categories: [] },
      configurationVersion: data?.configuration_version ?? null,
      mode,
      isHospitality: mode !== "platform",
      loading,
      error,
      refresh,
    };
  }, [data, error, loading, platformOnly, refresh, workspace]);

  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>;
}

export const useWorkspace = () => {
  const context = useContext(WorkspaceContext);
  if (!context) throw new Error("useWorkspace must be used inside WorkspaceProvider");
  return context;
};

export const useOptionalWorkspace = () => useContext(WorkspaceContext);
