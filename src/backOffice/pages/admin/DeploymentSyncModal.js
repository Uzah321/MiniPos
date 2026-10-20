import React, { useEffect, useMemo, useRef, useState } from "react";
import { AlertTriangle, CheckCircle2, Clipboard, KeyRound, RefreshCw, ShieldCheck, Trash2 } from "lucide-react";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import SyncPairingForm from "../../../components/SyncPairingForm";
import SyncStatusBadge from "../../../components/SyncStatusBadge";
import { useSyncStatus } from "../../../context/SyncStatusContext";
import {
  getDeploymentMode,
  isDedicatedBackOffice,
  isDedicatedFrontOffice,
} from "../../../config/DeploymentConfig";
import {
  createSyncPairingCode,
  createTerminalActivationCode,
  isSyncContractUnavailable,
  listSyncNodes,
  revokeSyncNode,
} from "../../../services/SyncService";
import { getTills } from "../../../services/TillService";
import { getSavedCompanyCode } from "../../../utils/apiConfig";

const text = (value, fallback = "—") => value === null || value === undefined || value === "" ? fallback : String(value);
const formatDateTime = (value) => {
  if (!value) return "Never";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString("en-ZW");
};
const apiMessage = (error, fallback) =>
  error?.response?.data?.message || error?.displayMessage || error?.message || fallback;

const identityText = (name, code, fallback = "Not available") => {
  const cleanName = String(name || "").trim();
  const cleanCode = String(code || "").trim();
  if (cleanName && cleanCode) return `${cleanName} (${cleanCode})`;
  return cleanName || cleanCode || fallback;
};

export const posSetupDetails = ({ companyCode = "", pairing = null } = {}) => {
  const node = pairing?.node || {};
  const branch = node.branch || {};
  const till = node.till || {};
  const code = pairing?.pairing_code || pairing?.code || pairing?.token || "";
  const android = String(node.deployment_mode || node.target_platform || pairing?.target_platform || "edge").toLowerCase() === "android";

  return [
    pairing?.replacement_node_id
      ? `SERVENEST REVOKED ${android ? "ANDROID TERMINAL" : "POS SERVER"} REPLACEMENT — ONE-TIME`
      : pairing?.recovery_node_id
        ? `SERVENEST ${android ? "ANDROID TERMINAL" : "POS SERVER"} RE-PAIR — ONE-TIME`
        : `SERVENEST NEW ${android ? "ANDROID TERMINAL" : "POS SERVER"} PAIRING — ONE-TIME`,
    `Code type: ${android ? "Android direct-cloud terminal" : "POS server"} pairing/re-pair (contains a dot)`,
    `Company code: ${String(companyCode || "Unavailable")}`,
    `Branch: ${identityText(branch.branch_name || branch.name, branch.branch_code || branch.code)}`,
    `Till: ${identityText(till.till_name || till.name, till.till_code || till.code)}`,
    `${android ? "Android terminal" : "POS server"}: ${identityText(node.node_name || node.name, node.node_code || node.code)}`,
    `Pairing code: ${String(code || "Unavailable")}`,
    `Expires: ${String(pairing?.expires_at || "Unavailable")}`,
    `Cloud server: configured automatically on the ${android ? "Android app" : "physical POS"}`,
    `Enter this only on the ${android ? "Android Initial Setup" : "POS Server Pairing/Re-pair"} screen.`,
    "DO NOT enter this on Browser Terminal Activation; that screen requires a TERM- code.",
  ].join("\n");
};

export const terminalSetupDetails = ({ activation = null } = {}) => {
  const terminal = activation?.terminal || {};
  const node = activation?.node || {};
  const code = activation?.terminal_activation_code || "";

  return [
    "SERVENEST BROWSER TERMINAL ACTIVATION — ONE-TIME",
    "Code type: browser terminal activation (starts with TERM-)",
    `POS server: ${identityText(node.node_name || node.name, node.node_code || node.code)}`,
    `Till: ${identityText(terminal.till_name || terminal.name, terminal.till_code || terminal.code)}`,
    `Activation code: ${code || "Unavailable"}`,
    `Expires: ${activation?.expires_at || "Unavailable"}`,
    "Enter this only on the Browser Terminal Activation screen.",
    "DO NOT use this to pair or re-pair the POS server.",
  ].join("\n");
};

export const syncNodeActionState = (node = {}) => {
  const status = String(node.status || (node.revoked_at ? "revoked" : "active")).trim().toLowerCase();
  const platform = String(node.deployment_mode || node.target_platform || "edge").trim().toLowerCase();
  const nodeId = node.id || node.node_id;
  const branchId = node.branch_id || node.branch?.id;
  const tillId = node.till_id || node.till?.id;
  return {
    status,
    platform,
    canActivateBrowser: platform !== "android" && status === "active" && !node.revoked_at,
    canRepairServer: ["active", "suspended"].includes(status) && !node.revoked_at,
    canReplaceServer: status === "revoked" && Boolean(node.revoked_at && nodeId && branchId && tillId),
    canRevoke: status !== "revoked" && !node.revoked_at,
  };
};

export const extractPairingTills = (response) => {
  const candidates = [
    response?.data?.data,
    response?.data?.tills,
    response?.data,
    response?.tills,
    response,
  ];
  return candidates.find(Array.isArray) || [];
};

export const terminalOptionLabel = (till = {}) => {
  const code = String(till.till_code || till.code || "").trim();
  const name = String(till.till_name || till.name || "").trim();
  return [code, name].filter(Boolean).join(" · ") || String(till.id || "Unknown till");
};

export const findExplicitTerminal = (tills = [], tillId = "") => {
  const selectedId = String(tillId || "").trim();
  if (!selectedId) return null;
  return tills.find((till) => String(till?.id || "") === selectedId) || null;
};

export const terminalActivationConfirmation = ({ node = {}, till = {} } = {}) => (
  `Issue a one-time browser activation code for ${terminalOptionLabel(till)} on ${node.node_name || node.name || node.node_code || node.id || "this POS server"}? `
  + "The code will activate only this selected till."
);

const currentBranch = () => {
  try {
    const user = JSON.parse(localStorage.getItem("back_user") || "null");
    const branch = user?.selectedBranch || user?.branch || {};
    return {
      id: user?.selectedBranchId || branch?.id || user?.branch_id || "",
      name: branch?.branch_name || branch?.name || user?.branch_name || "",
    };
  } catch {
    return { id: "", name: "" };
  }
};

const IdentityCard = ({ label, value }) => (
  <div className="border border-[#D5DEDE] bg-[#F8FAFC] px-3 py-2.5">
    <div className="text-[9px] font-bold uppercase tracking-wide text-[#6F7E7D]">{label}</div>
    <div className="mt-1 truncate text-[12px] font-semibold text-[#054747]" title={text(value)}>{text(value)}</div>
  </div>
);

export default function DeploymentSyncModal({ open, onClose }) {
  const {
    status,
    contractAvailable,
    refresh,
  } = useSyncStatus();
  const deploymentMode = getDeploymentMode();
  const cloudManagement = isDedicatedBackOffice(deploymentMode);
  const edgeControl = isDedicatedFrontOffice(deploymentMode);
  const [nodes, setNodes] = useState([]);
  const [nodesLoading, setNodesLoading] = useState(false);
  const [managementAvailable, setManagementAvailable] = useState(null);
  const [managementError, setManagementError] = useState("");
  const [pairingCode, setPairingCode] = useState(null);
  const [terminalActivation, setTerminalActivation] = useState(null);
  const [nodeName, setNodeName] = useState("");
  const [targetPlatform, setTargetPlatform] = useState("edge");
  const [pairingTills, setPairingTills] = useState([]);
  const [selectedServerTillId, setSelectedServerTillId] = useState("");
  const [selectedBrowserTillId, setSelectedBrowserTillId] = useState("");
  const [tillsLoading, setTillsLoading] = useState(false);
  const [tillsError, setTillsError] = useState("");
  const [creatingCode, setCreatingCode] = useState(false);
  const [recoveringId, setRecoveringId] = useState(null);
  const [replacingId, setReplacingId] = useState(null);
  const [activatingNodeId, setActivatingNodeId] = useState(null);
  const [revokingId, setRevokingId] = useState(null);
  const [copyFeedback, setCopyFeedback] = useState("");
  const openRef = useRef(open);
  const secretRequestGenerationRef = useRef(0);
  const tillRequestGenerationRef = useRef(0);
  openRef.current = open;

  const beginSecretRequest = () => {
    secretRequestGenerationRef.current += 1;
    return secretRequestGenerationRef.current;
  };

  const canCommitSecretRequest = (generation) => (
    openRef.current && secretRequestGenerationRef.current === generation
  );

  const loadPairingTills = async ({ preserveBrowserSelection = true } = {}) => {
    if (!cloudManagement) return null;
    const requestGeneration = ++tillRequestGenerationRef.current;
    const branch = currentBranch();
    if (!branch.id) {
      setPairingTills([]);
      setSelectedServerTillId("");
      setSelectedBrowserTillId("");
      setTillsError("Select a Back Office branch before pairing a POS.");
      return [];
    }

    setTillsLoading(true);
    setTillsError("");
    try {
      const response = await getTills({ branch_id: branch.id, is_active: 1, per_page: 500 });
      const rows = extractPairingTills(response).filter((till) => till.is_active !== false && till.is_active !== 0);
      if (requestGeneration !== tillRequestGenerationRef.current) return null;
      setPairingTills(rows);
      setSelectedServerTillId((current) => (
        rows.some((till) => String(till.id) === String(current))
          ? current
          : rows.length === 1 ? String(rows[0].id) : ""
      ));
      setSelectedBrowserTillId((current) => (
        preserveBrowserSelection && rows.some((till) => String(till.id) === String(current))
          ? current
          : ""
      ));
      if (!rows.length) setTillsError("No active till is configured for this branch. Create one in Till Management first.");
      return rows;
    } catch (requestError) {
      if (requestGeneration !== tillRequestGenerationRef.current) return null;
      setPairingTills([]);
      setSelectedServerTillId("");
      setSelectedBrowserTillId("");
      setTillsError(apiMessage(requestError, "Active tills could not be loaded."));
      return null;
    } finally {
      if (requestGeneration === tillRequestGenerationRef.current) setTillsLoading(false);
    }
  };

  const loadNodes = async () => {
    if (!cloudManagement) return;
    setNodesLoading(true);
    setManagementError("");
    try {
      const branch = currentBranch();
      const rows = await listSyncNodes({
        per_page: 100,
        ...(branch.id ? { branch_id: branch.id } : {}),
      });
      setNodes(rows);
      setManagementAvailable(true);
    } catch (requestError) {
      if (isSyncContractUnavailable(requestError)) {
        setManagementAvailable(false);
      } else {
        setManagementError(apiMessage(requestError, "Paired devices could not be loaded."));
      }
    } finally {
      setNodesLoading(false);
    }
  };

  useEffect(() => {
    if (!open) {
      // Pairing codes are one-time administrative secrets. Do not retain or
      // redisplay them after this modal is closed.
      secretRequestGenerationRef.current += 1;
      setPairingCode(null);
      setTerminalActivation(null);
      setCopyFeedback("");
      setRecoveringId(null);
      setReplacingId(null);
      setActivatingNodeId(null);
      setSelectedBrowserTillId("");
      tillRequestGenerationRef.current += 1;
      return;
    }
    refresh();
    loadNodes();
    loadPairingTills({ preserveBrowserSelection: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, cloudManagement]);

  const createCode = async () => {
    const branch = currentBranch();
    const normalizedNodeName = nodeName.trim();
    if (!branch.id) {
      setManagementError("Select a Back Office branch before generating a pairing code.");
      return;
    }
    if (!normalizedNodeName) {
      setManagementError("Enter a clear name for the physical POS device.");
      return;
    }
    if (!selectedServerTillId) {
      setManagementError("Select the till that this physical POS will use.");
      return;
    }
    if (targetPlatform === "edge" && nodes.some((node) => String(node.deployment_mode || "edge").toLowerCase() === "edge") && !await requestEnterpriseConfirmation({
      title: "Create POS Server",
      message: "Create a separate POS server identity? This does not activate another browser, repair an existing server or replace a revoked server. Use the explicit action on the existing server row for those workflows.",
      confirmLabel: "Create server",
    })) return;

    setCreatingCode(true);
    setManagementError("");
    const requestGeneration = beginSecretRequest();
    try {
      const result = await createSyncPairingCode({
        branch_id: branch.id,
        till_id: selectedServerTillId,
        node_name: normalizedNodeName,
        target_platform: targetPlatform,
      });
      if (!canCommitSecretRequest(requestGeneration)) return;
      setPairingCode(result);
      setTerminalActivation(null);
      setCopyFeedback("");
      setManagementAvailable(true);
    } catch (requestError) {
      if (isSyncContractUnavailable(requestError)) setManagementAvailable(false);
      else setManagementError(apiMessage(requestError, "Pairing code could not be generated."));
    } finally {
      setCreatingCode(false);
    }
  };

  const createRecoveryCode = async (node) => {
    const nodeId = node?.id || node?.node_id;
    const branchId = node?.branch_id || node?.branch?.id || currentBranch().id;
    const tillId = node?.till_id || node?.till?.id || "";
    const nodeStatus = syncNodeActionState(node).status;
    const androidNode = String(node?.deployment_mode || node?.target_platform || "edge").toLowerCase() === "android";

    if (!nodeId || !branchId || !tillId) {
      setManagementError("Device recovery requires an existing node that is already bound to a till. Use the local recovery procedure for a legacy node with no till.");
      return;
    }
    if (!["active", "suspended"].includes(nodeStatus)) {
      setManagementError("Only an active or suspended node can receive a recovery code. Revoked nodes are permanently decommissioned.");
      return;
    }
    if (!await requestEnterpriseConfirmation({
      title: "Re-pair Device",
      message: `Re-pair ${node.node_name || node.name || node.node_code || (androidNode ? "this Android terminal" : "this POS server")}? This rotates only that device's sync credential. The existing credential remains active until recovery succeeds.`,
      confirmLabel: "Re-pair device",
    })) return;

    setRecoveringId(nodeId);
    setManagementError("");
    const requestGeneration = beginSecretRequest();
    try {
      const result = await createSyncPairingCode({
        branch_id: branchId,
        till_id: tillId,
        node_name: node.node_name || node.name || node.node_code || (androidNode ? "Recovered Android Terminal" : "Recovered POS"),
        target_platform: String(node.deployment_mode || node.target_platform || "edge").toLowerCase(),
        recovery_node_id: nodeId,
        recovery_authorized: true,
      });
      if (!canCommitSecretRequest(requestGeneration)) return;
      setPairingCode({ ...result, recovery_node_id: nodeId });
      setTerminalActivation(null);
      setCopyFeedback("");
      setManagementAvailable(true);
      await loadNodes();
    } catch (requestError) {
      if (canCommitSecretRequest(requestGeneration)) {
        if (isSyncContractUnavailable(requestError)) setManagementAvailable(false);
        else setManagementError(apiMessage(requestError, "Recovery code could not be generated."));
      }
    } finally {
      if (secretRequestGenerationRef.current === requestGeneration) setRecoveringId(null);
    }
  };

  const createReplacementCode = async (node) => {
    const nodeId = node?.id || node?.node_id;
    const branchId = node?.branch_id || node?.branch?.id || "";
    const tillId = node?.till_id || node?.till?.id || "";
    const actionState = syncNodeActionState(node);

    if (!actionState.canReplaceServer || !nodeId || !branchId || !tillId) {
      setManagementError("Replacement requires a revoked POS server with its original branch and till binding. Contact an administrator if this legacy row has no till.");
      return;
    }
    if (!await requestEnterpriseConfirmation({
      title: "Replace Revoked Server",
      message: `Replace revoked server ${node.node_name || node.name || node.node_code || nodeId}? This creates a new same-scope server identity and leaves the old node revoked. The local server must have zero unsent or failed sync events before it can accept the replacement code.`,
      confirmLabel: "Replace server",
    })) return;

    setReplacingId(nodeId);
    setManagementError("");
    const requestGeneration = beginSecretRequest();
    try {
      const result = await createSyncPairingCode({
        branch_id: branchId,
        till_id: tillId,
        node_name: node.node_name || node.name || node.node_code || "Replacement POS Server",
        target_platform: String(node.deployment_mode || node.target_platform || "edge").toLowerCase(),
        replacement_node_id: nodeId,
        replacement_authorized: true,
      });
      if (!canCommitSecretRequest(requestGeneration)) return;
      setPairingCode({ ...result, replacement_node_id: result?.replacement_node_id || nodeId });
      setTerminalActivation(null);
      setCopyFeedback("");
      setManagementAvailable(true);
      await loadNodes();
    } catch (requestError) {
      if (canCommitSecretRequest(requestGeneration)) {
        if (isSyncContractUnavailable(requestError)) setManagementAvailable(false);
        else setManagementError(apiMessage(requestError, "Revoked server replacement code could not be generated."));
      }
    } finally {
      if (secretRequestGenerationRef.current === requestGeneration) setReplacingId(null);
    }
  };

  const createTerminalCode = async (node) => {
    const nodeId = node?.id || node?.node_id;
    const nodeStatus = syncNodeActionState(node).status;
    const requestedTillId = String(selectedBrowserTillId || "").trim();
    if (!nodeId) {
      setManagementError("Select a valid paired POS server.");
      return;
    }
    if (nodeStatus !== "active") {
      setManagementError("Resume this POS server before activating another terminal.");
      return;
    }
    if (!requestedTillId) {
      setManagementError("Select the browser activation till explicitly, then try again.");
      return;
    }

    setActivatingNodeId(nodeId);
    setManagementError("");
    const requestGeneration = beginSecretRequest();
    try {
      // Re-read active tills immediately before issuing the one-time code so a
      // newly created terminal is available and a stale selection cannot fall
      // back to the POS server's original till.
      const currentTills = await loadPairingTills({ preserveBrowserSelection: true });
      if (!canCommitSecretRequest(requestGeneration)) return;
      const selectedTill = findExplicitTerminal(currentTills || [], requestedTillId);
      if (!selectedTill) {
        setManagementError("The selected till is no longer active for this branch. Refresh the till list and select it again.");
        return;
      }
      if (!await requestEnterpriseConfirmation({
        title: "Activate Terminal",
        message: terminalActivationConfirmation({ node, till: selectedTill }),
        confirmLabel: "Activate terminal",
      })) return;

      const result = await createTerminalActivationCode(nodeId, requestedTillId);
      if (!canCommitSecretRequest(requestGeneration)) return;
      if (String(result?.terminal?.id || "") !== requestedTillId) {
        const contractError = new Error("The cloud returned an activation for a different till. No code was displayed; refresh and try again.");
        contractError.displayMessage = contractError.message;
        throw contractError;
      }
      setTerminalActivation(result);
      setPairingCode(null);
      setSelectedBrowserTillId("");
      setCopyFeedback("");
    } catch (requestError) {
      if (canCommitSecretRequest(requestGeneration)) {
        setManagementError(apiMessage(requestError, "Terminal activation code could not be generated."));
      }
    } finally {
      if (secretRequestGenerationRef.current === requestGeneration) setActivatingNodeId(null);
    }
  };

  const revokeNode = async (node) => {
    const nodeId = node?.id || node?.node_id;
    const androidNode = String(node?.deployment_mode || node?.target_platform || "edge").toLowerCase() === "android";
    if (!nodeId || !await requestEnterpriseConfirmation({
      title: "Revoke Device",
      message: `Permanently revoke ${node.name || node.node_name || (androidNode ? "this Android terminal" : "this POS server")}? Revocation cannot be undone or re-paired. Use the re-pair action for recovery; revoke only a decommissioned device.`,
      confirmLabel: "Revoke device",
    })) return;
    setRevokingId(nodeId);
    setManagementError("");
    try {
      await revokeSyncNode(nodeId);
      await loadNodes();
    } catch (requestError) {
      setManagementError(apiMessage(requestError, "Device could not be revoked."));
    } finally {
      setRevokingId(null);
    }
  };

  const codeValue = pairingCode?.pairing_code || pairingCode?.code || pairingCode?.token || "";
  const companyCode = pairingCode?.company?.company_code
    || pairingCode?.company?.code
    || status?.company?.code
    || getSavedCompanyCode();
  const setupNode = pairingCode?.node || {};
  const setupIsAndroid = String(setupNode.deployment_mode || setupNode.target_platform || pairingCode?.target_platform || "edge").toLowerCase() === "android";
  const setupBranch = setupNode.branch || {};
  const setupTill = setupNode.till || {};
  const setupText = posSetupDetails({ companyCode, pairing: pairingCode });
  const terminalActivationCode = terminalActivation?.terminal_activation_code || "";
  const activationTerminal = terminalActivation?.terminal || {};
  const activationNode = terminalActivation?.node || {};
  const terminalSetupText = terminalSetupDetails({ activation: terminalActivation });
  const selectedBrowserTill = findExplicitTerminal(pairingTills, selectedBrowserTillId);
  const immutableIdentity = useMemo(() => ({
    company: status?.company?.name || status?.company?.code,
    branch: status?.branch?.name || status?.branch?.code,
    till: status?.till?.name || status?.till?.code,
    node: status?.node?.name || status?.node?.id,
  }), [status]);

  return (
    <EnterpriseFormModal
      open={open}
      onClose={onClose}
      busy={creatingCode || recoveringId !== null || replacingId !== null || activatingNodeId !== null || revokingId !== null}
      width="920px"
      title="DEPLOYMENT & SYNC"
      subtitle={`${deploymentMode.replaceAll("-", " ")} deployment · local API controlled`}
    >
      <div className="space-y-4">
        <div className="flex justify-end" aria-label="Synchronization indicators">
          <SyncStatusBadge />
        </div>

        {edgeControl && status?.paired && (
          <section className="border border-[#A9D8C5] bg-[#F0FAF6] p-4">
            <div className="mb-3 flex items-center gap-2 text-[11px] font-semibold text-[#117556]"><ShieldCheck className="h-4 w-4" /> Immutable device binding</div>
            <div className="grid gap-2 sm:grid-cols-4">
              <IdentityCard label="Company" value={immutableIdentity.company} />
              <IdentityCard label="Branch" value={immutableIdentity.branch} />
              <IdentityCard label="Till" value={immutableIdentity.till} />
              <IdentityCard label="Node" value={immutableIdentity.node} />
            </div>
          </section>
        )}

        {edgeControl && !status?.paired && contractAvailable !== false && (
          <section className="border border-[#CCD6D6] bg-white p-4">
            <div className="mb-3">
              <div className="text-[11px] font-semibold text-[#054747]">Pair this local POS node</div>
              <div className="mt-1 text-[10px] text-[#6F7E7D]">Use a one-time code generated by the cloud Back Office for the correct branch.</div>
            </div>
            <SyncPairingForm compact />
          </section>
        )}

        {cloudManagement && (
          <section className="border border-[#CCD6D6] bg-white">
            <header className="flex items-center justify-between border-b border-[#D7E0E0] bg-[#F8FAFC] px-4 py-3">
              <div>
                <div className="text-[11px] font-semibold text-[#054747]">POS device deployment</div>
                <div className="mt-0.5 text-[9px] text-[#6F7E7D]">Pair a web branch server or direct-cloud Android till, repair an existing device, or activate an additional browser. Each workflow uses a different one-time code.</div>
              </div>
            </header>

            <div className="border-b border-[#D7E0E0] bg-[#F8FAFC] px-4 py-3">
                <div className="text-[10px] font-bold uppercase tracking-wide text-[#006565]">1. Pair a new POS device</div>
              <p className="mt-1 text-[10px] leading-4 text-[#566868]">
                Choose <strong>Web POS server</strong> for browsers sharing a branch database, or <strong>Android direct cloud</strong> for one Android till with its own SQLite database. To add a browser to an existing server, use <strong>Activate Browser</strong> in step 2.
              </p>
            </div>
            <div className="grid gap-3 border-b border-[#D7E0E0] p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,1fr)_auto] lg:items-end">
              <label className="block">
                <span className="mb-1 block text-[9px] font-bold uppercase tracking-wide text-[#566868]">Device Type</span>
                <select aria-label="device type" value={targetPlatform} onChange={(event) => { setTargetPlatform(event.target.value); setManagementError(""); setPairingCode(null); }} className="h-9 w-full border border-[#B2C1C0] bg-white px-2 text-[11px] text-[#054747] outline-none focus:border-[#067C7C]">
                  <option value="edge">Web POS server</option>
                  <option value="android">Android direct cloud</option>
                </select>
              </label>
              <label className="block">
                <span className="mb-1 block text-[9px] font-bold uppercase tracking-wide text-[#566868]">Unique Device Name</span>
                <input value={nodeName} onChange={(event) => { setNodeName(event.target.value); setManagementError(""); }} placeholder={targetPlatform === "android" ? "e.g. Harare Tablet 01" : "e.g. Harare Branch Server"} className="h-9 w-full border border-[#B2C1C0] px-3 text-[11px] text-[#054747] outline-none focus:border-[#067C7C]" />
              </label>
              <div>
                <div className="mb-1 text-[9px] font-bold uppercase tracking-wide text-[#566868]">Selected Branch</div>
                <div className="flex h-9 items-center border border-[#D5DEDE] bg-[#F8FAFC] px-3 text-[11px] font-semibold text-[#054747]">{currentBranch().name || currentBranch().id || "No branch selected"}</div>
              </div>
              <label className="block">
                <span className="mb-1 block text-[9px] font-bold uppercase tracking-wide text-[#566868]">Assigned Till *</span>
                <select value={selectedServerTillId} onChange={(event) => { setSelectedServerTillId(event.target.value); setManagementError(""); }} disabled={tillsLoading} className="h-9 w-full border border-[#B2C1C0] bg-white px-2 text-[11px] text-[#054747] outline-none focus:border-[#067C7C] disabled:opacity-60">
                  <option value="">{tillsLoading ? "Loading tills…" : "Select till"}</option>
                  {pairingTills.map((till) => <option key={till.id} value={till.id}>{till.till_code ? `${till.till_code} · ` : ""}{till.till_name || till.name || till.id}</option>)}
                </select>
              </label>
              <button type="button" onClick={createCode} disabled={creatingCode || managementAvailable === false} className="h-9 border border-[#006161] bg-[#067C7C] px-4 text-[11px] font-semibold text-white disabled:opacity-50">
                {creatingCode ? "Generating…" : targetPlatform === "android" ? "Generate Android Code" : "Generate New Server Code"}
              </button>
            </div>
            {tillsError && <div className="mx-4 mt-3 border border-[#E7C77D] bg-[#FFF8E7] px-3 py-2 text-[10px] text-[#704C0D]">{tillsError}</div>}

            {codeValue && (
              <div className="m-4 border border-[#6ED4D3] bg-[#EFF7F7] p-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                  <div className="text-[9px] font-bold uppercase tracking-wide text-[#006565]">
                    {pairingCode?.replacement_node_id
                      ? `Revoked ${setupIsAndroid ? "Android terminal" : "POS server"} replacement code — not a browser code`
                      : pairingCode?.recovery_node_id
                        ? `${setupIsAndroid ? "Android terminal" : "POS server"} re-pair code — not a browser code`
                        : `New ${setupIsAndroid ? "Android terminal" : "POS server"} pairing code — not a browser code`}
                  </div>
                  <div className="mt-1 font-mono text-[18px] font-bold tracking-[0.16em] text-[#054747]">{codeValue}</div>
                  <div className="mt-1 text-[9px] text-[#6F7E7D]">Expires {formatDateTime(pairingCode?.expires_at)}</div>
                  </div>
                  <button type="button" onClick={() => navigator.clipboard?.writeText(codeValue)} className="flex h-9 items-center gap-2 border border-[#ACB8B8] bg-white px-3 text-[10px] font-semibold text-[#255858]"><Clipboard className="h-3.5 w-3.5" /> Copy code</button>
                </div>
                <div className="mt-3 border border-[#E7C77D] bg-[#FFF8E7] px-3 py-2 text-[10px] font-semibold leading-4 text-[#704C0D]">
                  Use on: {pairingCode?.replacement_node_id
                    ? "the revoked local server's Replace Revoked POS Server form; it must have no unsent or failed sync events"
                    : pairingCode?.recovery_node_id
                      ? `the same active or suspended ${setupIsAndroid ? "Android app's Initial Setup" : "POS server's Re-pair POS Server"} form`
                      : setupIsAndroid ? "the Android app's Initial Setup screen" : "a new POS server's Pair POS Server form"}. This code contains a dot. Do not enter it on Browser Terminal Activation, which accepts only a TERM- code.
                </div>
                <div className="mt-3 grid gap-2 border-t border-[#ADE5E4] pt-3 sm:grid-cols-2 lg:grid-cols-4">
                  <IdentityCard label="Company Code" value={companyCode} />
                  <IdentityCard label="Branch" value={identityText(setupBranch.branch_name || setupBranch.name, setupBranch.branch_code || setupBranch.code)} />
                  <IdentityCard label="Till" value={identityText(setupTill.till_name || setupTill.name, setupTill.till_code || setupTill.code)} />
                  <IdentityCard label={setupIsAndroid ? "Android Terminal" : "POS Server"} value={identityText(setupNode.node_name || setupNode.name, setupNode.node_code || setupNode.code)} />
                </div>
                <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                  <p className="text-[10px] text-[#255858]">
                    {pairingCode?.replacement_node_id
                      ? "This creates a new same-company, branch and till server identity; the old node remains revoked."
                      : setupIsAndroid
                        ? "This activates one Android installation for this company, branch and till. It syncs directly with the cloud."
                        : "This provisions or repairs the whole POS server. It does not activate an additional browser."}
                  </p>
                  <div className="flex items-center gap-2">
                    {copyFeedback && <span role="status" className="text-[9px] font-semibold text-[#117556]">{copyFeedback}</span>}
                    <button
                      type="button"
                      onClick={async () => {
                        try {
                          await navigator.clipboard?.writeText(setupText);
                          setCopyFeedback("Setup details copied");
                        } catch {
                          setCopyFeedback("Copy failed — copy the values shown");
                        }
                      }}
                      className="flex h-9 items-center gap-2 border border-[#006161] bg-[#067C7C] px-3 text-[10px] font-semibold text-white"
                    >
                      <Clipboard className="h-3.5 w-3.5" /> {setupIsAndroid ? "Copy Android Setup" : "Copy Server Setup"}
                    </button>
                  </div>
                </div>
              </div>
            )}

            {terminalActivationCode && (
              <div className="m-4 border border-[#7DC6C6] bg-[#F0FAF6] p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <div className="text-[9px] font-bold uppercase tracking-wide text-[#007474]">Browser terminal activation code — starts with TERM-</div>
                    <div className="mt-1 font-mono text-[18px] font-bold tracking-[0.12em] text-[#054747]">{terminalActivationCode}</div>
                    <div className="mt-1 text-[9px] text-[#6F7E7D]">Expires {formatDateTime(terminalActivation?.expires_at)} · usable once</div>
                  </div>
                  <button type="button" onClick={() => navigator.clipboard?.writeText(terminalActivationCode)} className="flex h-9 items-center gap-2 border border-[#82B9B8] bg-white px-3 text-[10px] font-semibold text-[#006969]"><Clipboard className="h-3.5 w-3.5" /> Copy code</button>
                </div>
                <div className="mt-3 border border-[#7DC6C6] bg-white px-3 py-2 text-[10px] font-semibold leading-4 text-[#006969]">
                  Use on: the Browser Terminal Activation screen on the additional browser. This code starts with TERM-. Do not use it to pair or re-pair the POS server.
                </div>
                <div className="mt-3 grid gap-2 border-t border-[#B9DECF] pt-3 sm:grid-cols-2">
                  <IdentityCard label="POS Server" value={identityText(activationNode.node_name || activationNode.name, activationNode.node_code || activationNode.code)} />
                  <IdentityCard label="Till" value={identityText(activationTerminal.till_name || activationTerminal.name, activationTerminal.till_code || activationTerminal.code)} />
                </div>
                <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                  <p className="text-[10px] text-[#255858]">This activates only the selected till in an additional browser connected to the same POS server.</p>
                  <button
                    type="button"
                    onClick={async () => {
                      try {
                        await navigator.clipboard?.writeText(terminalSetupText);
                        setCopyFeedback("Terminal setup copied");
                      } catch {
                        setCopyFeedback("Copy failed — copy the values shown");
                      }
                    }}
                    className="flex h-9 items-center gap-2 border border-[#007474] bg-[#069495] px-3 text-[10px] font-semibold text-white"
                  >
                    <Clipboard className="h-3.5 w-3.5" /> Copy Browser Activation
                  </button>
                </div>
              </div>
            )}

            <div className="grid gap-3 border-y border-[#D7E0E0] bg-[#F0FAF6] p-4 sm:grid-cols-[minmax(0,1fr)_minmax(220px,0.7fr)_auto] sm:items-end">
              <div>
                <div className="text-[10px] font-bold uppercase tracking-wide text-[#007474]">2. Activate an additional browser</div>
                <p className="mt-1 text-[10px] leading-4 text-[#566868]">
                  Choose the till for the new browser, then select <strong>Activate Browser</strong> on the correct active POS server below. Do not generate a new server pairing code.
                </p>
              </div>
              <label className="block">
                <span className="mb-1 block text-[9px] font-bold uppercase tracking-wide text-[#566868]">Browser Activation Till *</span>
                <select value={selectedBrowserTillId} onChange={(event) => { setSelectedBrowserTillId(event.target.value); setManagementError(""); }} disabled={tillsLoading} className="h-9 w-full border border-[#82B9B8] bg-white px-2 text-[11px] text-[#054747] outline-none focus:border-[#069495] disabled:opacity-60">
                  <option value="">{tillsLoading ? "Loading tills…" : "Select till for this browser"}</option>
                  {pairingTills.map((till) => <option key={till.id} value={till.id}>{till.till_code ? `${till.till_code} · ` : ""}{till.till_name || till.name || till.id}</option>)}
                </select>
                <span className="mt-1 block text-[9px] font-semibold text-[#006969]">
                  {selectedBrowserTill ? `Selected: ${terminalOptionLabel(selectedBrowserTill)}` : "No browser till selected"}
                </span>
              </label>
              <button
                type="button"
                onClick={() => loadPairingTills({ preserveBrowserSelection: true })}
                disabled={tillsLoading}
                className="inline-flex h-9 items-center gap-1.5 border border-[#82B9B8] bg-white px-3 text-[9px] font-semibold text-[#007474] disabled:opacity-50"
              >
                <RefreshCw className={`h-3.5 w-3.5 ${tillsLoading ? "animate-spin" : ""}`} /> Refresh Tills
              </button>
            </div>

            {managementAvailable === false ? (
              <div className="m-4 border border-[#E7C77D] bg-[#FFF8E7] px-3 py-2 text-[11px] text-[#704C0D]">Cloud node-management endpoints are not available on this API yet.</div>
            ) : managementError ? (
              <div className="m-4 border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[11px] text-[#B42318]">{managementError}</div>
            ) : (
              <div className="max-h-[260px] overflow-auto">
                <table className="w-full border-collapse text-left text-[10px]">
                  <thead className="sticky top-0 bg-[#EFF3F3] text-[#566868]"><tr><th className="px-3 py-2">Device</th><th className="px-3 py-2">Platform</th><th className="px-3 py-2">Branch</th><th className="px-3 py-2">Status</th><th className="px-3 py-2 text-right">Action</th></tr></thead>
                  <tbody>
                    {nodes.map((node) => {
                      const nodeId = node.id || node.node_id;
                      const actionState = syncNodeActionState(node);
                      const revoked = actionState.status === "revoked";
                      const suspended = actionState.status === "suspended";
                      const androidNode = actionState.platform === "android";
                      const online = node.is_online === true;
                      return (
                        <tr key={nodeId} className={`border-t border-[#DEE5E5] ${revoked ? "bg-[#FFF8F7]" : ""}`}>
                          <td className="px-3 py-2 font-semibold text-[#054747]">{node.name || node.node_name || node.device_label || nodeId}</td>
                          <td className="px-3 py-2">{androidNode ? "Android · direct cloud" : "Web · branch server"}</td>
                          <td className="px-3 py-2">{node.branch_name || node.branch?.name || node.branch?.branch_name || node.branch_code || node.branch?.branch_code || "—"}</td>
                          <td className="px-3 py-2">
                            <span
                              className={`inline-flex items-center ${revoked || suspended ? "text-[#B42318]" : "text-[#117556]"}`}
                              aria-label={`Device status: ${actionState.status || "unknown"}; ${online ? "online" : "offline"}`}
                            >
                              {revoked || suspended ? <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" /> : <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />}
                              <span className="ml-1">{revoked ? "Revoked" : suspended ? "Suspended" : online ? "Online" : "Offline"}</span>
                            </span>
                          </td>
                          <td className="px-3 py-2 text-right">
                            {revoked ? (
                              actionState.canReplaceServer ? (
                                <button
                                  type="button"
                                  onClick={() => createReplacementCode(node)}
                                  disabled={replacingId === nodeId}
                                  className="inline-flex h-7 items-center gap-1 border border-[#E7C77D] bg-[#FFF8E7] px-2 text-[9px] font-semibold text-[#704C0D] disabled:opacity-50"
                                  title={`Creates a new same-scope ${androidNode ? "Android terminal" : "server"} identity. The old node remains revoked.`}
                                >
                                  <RefreshCw className={`h-3 w-3 ${replacingId === nodeId ? "animate-spin" : ""}`} /> {replacingId === nodeId ? "Creating…" : androidNode ? "Replace Android" : "Replace Revoked Server"}
                                </button>
                              ) : (
                                <span className="inline-flex border border-[#FDA29B] bg-[#FEF3F2] px-2 py-1 text-[9px] font-semibold text-[#B42318]" title="This legacy revoked row is missing the original branch or till binding required for safe replacement.">
                                  Replacement unavailable — missing scope
                                </span>
                              )
                            ) : (
                              <div className="inline-flex gap-1">
                                {actionState.canActivateBrowser && (
                                  <button type="button" onClick={() => createTerminalCode(node)} disabled={activatingNodeId === nodeId || tillsLoading} className="inline-flex h-7 items-center gap-1 border border-[#87CECD] bg-white px-2 text-[9px] font-semibold text-[#007474] disabled:opacity-40"><KeyRound className="h-3 w-3" /> {activatingNodeId === nodeId ? "Creating…" : "Activate Browser"}</button>
                                )}
                                {actionState.canRepairServer && (
                                  <button type="button" onClick={() => createRecoveryCode(node)} disabled={recoveringId === nodeId} className="inline-flex h-7 items-center gap-1 border border-[#6ED4D3] bg-white px-2 text-[9px] font-semibold text-[#006565] disabled:opacity-40"><RefreshCw className={`h-3 w-3 ${recoveringId === nodeId ? "animate-spin" : ""}`} /> {androidNode ? "Re-pair Android" : "Re-pair Server"}</button>
                                )}
                                {actionState.canRevoke && (
                                  <button type="button" onClick={() => revokeNode(node)} disabled={revokingId === nodeId} className="inline-flex h-7 items-center gap-1 border border-[#E0A29D] bg-white px-2 text-[9px] font-semibold text-[#B42318] disabled:opacity-50"><Trash2 className="h-3 w-3" /> Revoke</button>
                                )}
                              </div>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                    {!nodesLoading && nodes.length === 0 && <tr><td colSpan="5" className="px-3 py-8 text-center text-[#6F7E7D]">No paired POS devices.</td></tr>}
                    {nodesLoading && <tr><td colSpan="5" className="px-3 py-8 text-center text-[#6F7E7D]">Loading devices…</td></tr>}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        )}
      </div>
    </EnterpriseFormModal>
  );
}
