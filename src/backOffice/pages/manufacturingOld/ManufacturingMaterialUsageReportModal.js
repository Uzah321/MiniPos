import React, { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import { getMaterialUsageReport } from "../../../services/ManufacturingService";
import { ActionButton, LoadingPill, MetricCard, Notice, PageShell, getBranchId, getErrorMessage, getPayloadData, moneyNumber, qtyNumber, today, useNotice } from "./ManufacturingShared";

export default function ManufacturingMaterialUsageReportModal({ open, onClose, currentUser = null }) {
  const branchId = getBranchId(currentUser);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const { notice, showNotice } = useNotice();
  const loadReport = async () => {
    try { setLoading(true); const res = await getMaterialUsageReport({ branch_id: branchId || undefined, date_from: today(), date_to: today() }); setReport(getPayloadData(res)); }
    catch (error) { showNotice(getErrorMessage(error, "Failed to load material usage report.")); }
    finally { setLoading(false); }
  };
  useEffect(() => { if (open) loadReport(); }, [open]);
  if (!open) return null;
  return <FullScreenModal open={open} onClose={onClose} title="MATERIAL USAGE REPORT" subtitle="Raw material cost and usage summary">
    <Notice notice={notice} /><LoadingPill loading={loading} />
    <PageShell title="Material Usage" subtitle="Backend currently returns production summary for this endpoint." actions={<ActionButton onClick={loadReport} label="Refresh" icon={RefreshCw} primary />}>
      <div className="grid gap-3 md:grid-cols-4">
        <MetricCard title="Material Cost" value={moneyNumber(report?.material_cost ?? 0)} />
        <MetricCard title="Produced Qty" value={qtyNumber(report?.produced_quantity ?? 0)} />
        <MetricCard title="Actual Good Qty" value={qtyNumber(report?.actual_good_quantity ?? 0)} />
        <MetricCard title="Avg Cost / Unit" value={moneyNumber(report?.average_cost_per_unit ?? 0)} />
      </div>
    </PageShell>
  </FullScreenModal>;
}
