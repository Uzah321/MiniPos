import { useEffect, useState } from "react";
import { useOptionalWorkspace } from "../context/WorkspaceContext";
import { getWorkspaceBootstrap } from "../services/WorkspaceService";

const STORAGE_KEY = "salexa_business_mode";

const cachedMode = () => "hospitality";

export default function useBusinessMode() {
  const sharedWorkspace = useOptionalWorkspace();
  const [mode, setMode] = useState(cachedMode);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [workspace, setWorkspace] = useState(null);

  useEffect(() => {
    if (sharedWorkspace) return undefined;
    let active = true;
    const storedUser = localStorage.getItem(window.location.pathname.includes("/front") ? "front_user" : "back_user");
    let branchId = null;
    try {
      const user = storedUser ? JSON.parse(storedUser) : null;
      branchId = user?.selectedBranchId || user?.selectedBranch?.id || user?.branch_id || null;
    } catch {
      branchId = null;
    }

    getWorkspaceBootstrap(branchId)
      .then((payload) => {
        if (!active) return;
        const nextMode = "hospitality";
        sessionStorage.setItem(STORAGE_KEY, nextMode);
        setMode(nextMode);
        setWorkspace(payload);
        setError("");
      })
      .catch((requestError) => {
        if (active) setError(requestError?.displayMessage || requestError?.message || "Business mode could not be loaded.");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => { active = false; };
  }, [sharedWorkspace]);

  if (sharedWorkspace) {
    return {
      mode: sharedWorkspace.mode,
      isHospitality: sharedWorkspace.isHospitality,
      loading: sharedWorkspace.loading,
      error: sharedWorkspace.error,
      workspace: sharedWorkspace.data,
    };
  }

  return { mode, isHospitality: true, loading, error, workspace };
}
