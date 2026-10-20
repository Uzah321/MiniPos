import React from "react";
import { CloudDownload, CloudUpload, Server } from "lucide-react";
import { useOptionalSyncStatus } from "../context/SyncStatusContext";

export const describeSyncStatus = ({
  status,
  initialLoading,
  contractAvailable,
  error,
}) => {
  if (initialLoading && !status) return { label: "Checking local node", tone: "checking" };
  if (contractAvailable === false) return { label: "Sync API unavailable", tone: "danger" };
  if (!status?.localBackendReachable || (error && !status)) {
    return { label: "Local service offline", tone: "danger" };
  }
  if (status?.deploymentMode === "cloud") return { label: "Cloud hub", tone: "success" };
  if (status?.deploymentMode === "standalone") return { label: "Standalone local", tone: "neutral" };
  if (status?.pairingRequired) return { label: "Device setup required", tone: "warning" };
  if (status?.tillSetupRequired) return { label: "Till setup required", tone: "warning" };
  if (status?.configurationPending) return { label: "Configuration pending", tone: "warning" };
  if (status?.sellReady === false) return { label: "POS not ready", tone: "warning" };
  if (status?.deadLetterEvents > 0) return { label: `${status.deadLetterEvents} sync failed`, tone: "danger" };
  if (status?.localOnly || !status?.cloudConfigured) return { label: "Local only", tone: "neutral" };
  if (status?.pendingTotal > 0) return { label: `${status.pendingTotal} pending`, tone: "warning" };
  if (status?.cloudReachable === false) return { label: "Cloud offline", tone: "warning" };
  if (status?.lastError) return { label: "Sync attention", tone: "danger" };
  return { label: "Synced", tone: "success" };
};

export const describeSyncDirections = ({ status, running = false } = {}) => {
  const describe = (key, label) => {
    const direction = status?.directions?.[key] || {};
    const inSync = direction.inSync === true && !running;
    const pending = Number(direction.pending || 0);
    const state = running ? "syncing" : String(direction.state || "unknown");
    const detail = inSync
      ? `${label} is fully acknowledged`
      : pending > 0
        ? `${label} has ${pending} pending`
        : `${label} is ${state === "unknown" ? "not yet verified" : state}`;

    return { key, label, inSync, pending, state, detail };
  };

  return [
    describe("posToCloud", "POS → Cloud"),
    describe("cloudToPos", "Cloud → POS"),
  ];
};

export default function SyncStatusBadge({ dark = false, onClick = null, className = "" }) {
  const sync = useOptionalSyncStatus();
  const descriptor = describeSyncStatus(sync || {});
  const showDirections = sync?.status?.deploymentMode === "edge" && sync?.status?.paired;
  const directions = showDirections ? describeSyncDirections(sync) : [];
  const directionsInSync = showDirections && directions.every((direction) => direction.inSync);
  const legacyInSync = descriptor.tone === "success";
  const overallAriaLabel = showDirections
    ? `Synchronization: ${directionsInSync ? "synced in both directions" : "not synced in both directions"}`
    : `Synchronization: ${legacyInSync ? "synced" : "not synced"}`;
  const successClass = dark ? "text-[#86EFAC]" : "text-[#117556]";
  const dangerClass = dark ? "text-[#FDA29B]" : "text-[#B42318]";
  const legacyContent = (
    <span
      className={legacyInSync ? successClass : dangerClass}
      aria-label={legacyInSync ? "Synchronization indicator: green" : "Synchronization indicator: red"}
    >
      <Server className="h-3.5 w-3.5" aria-hidden="true" />
    </span>
  );
  const directionalContent = showDirections && (
    <span className="inline-flex items-center gap-2" aria-label="Two-way synchronization status">
      {directions.map((direction) => {
        const DirectionIcon = direction.key === "posToCloud" ? CloudUpload : CloudDownload;
        const color = direction.inSync ? successClass : dangerClass;
        return (
          <span
            key={direction.key}
            className={`inline-flex items-center ${color}`}
            aria-label={`${direction.label}: ${direction.inSync ? "green" : "red"}`}
          >
            <DirectionIcon className="h-3.5 w-3.5" aria-hidden="true" />
          </span>
        );
      })}
    </span>
  );
  const classes = `inline-flex items-center gap-1.5 whitespace-nowrap ${className}`;
  const content = directionalContent || legacyContent;

  return onClick ? (
    <button type="button" className={classes} onClick={onClick} aria-label={overallAriaLabel}>
      {content}
    </button>
  ) : (
    <span className={classes} aria-label={overallAriaLabel}>
      {content}
    </span>
  );
}
