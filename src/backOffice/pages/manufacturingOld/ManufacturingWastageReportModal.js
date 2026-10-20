import React, { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import { getWastageReport } from "../../../services/ManufacturingService";
import { ActionButton, LoadingPill, MetricCard, Notice, PageShell, getBranchId, getErrorMessage, getPayloadData, moneyNumber, qtyNumber, today, useNotice } from "./ManufacturingShared";

export default function ManufacturingWastageReportModal({ open, onClose, currentUser = null }) {
  const branchId = getBranchId(currentUser);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const { notice, showNotice } = useNotice();
  const loadReport = async () => {
    try { setLoading(true); const res = await getWastageReport({ branch_id: branchId || undefined, date_from: today(), date_to: today() }); setReport(getPayloadData(res)); }
    catch (error) { showNotice(getErrorMessage(error, "Failed to load wastage report.")); }
    finally { setLoading(false); }
  };
  useEffect(() => { if (open) loadReport(); }, [open]);
  if (!open) return null;
  return <FullScreenModal open={open} onClose={onClose} title="WASTAGE REPORT" subtitle="Actual waste quantity and related summary">
    <Notice notice={notice} /><LoadingPill loading={loading} />
    <PageShell title="Wastage Report" subtitle="Backend currently exposes wastage through production summary fields." actions={<ActionButton onClick={loadReport} label="Refresh" icon={RefreshCw} primary />}>
      <div className="grid gap-3 md:grid-cols-4">
        <MetricCard title="Waste Qty" value={qtyNumber(report?.actual_waste_quantity ?? 0)} />
        <MetricCard title="Wastage Field" value={moneyNumber(report?.wastage_cost ?? 0)} />
        <MetricCard title="Reject Qty" value={qtyNumber(report?.actual_reject_quantity ?? 0)} />
        <MetricCard title="Produced Qty" value={qtyNumber(report?.produced_quantity ?? 0)} />
      </div>
    </PageShell>
  </FullScreenModal>;
}
