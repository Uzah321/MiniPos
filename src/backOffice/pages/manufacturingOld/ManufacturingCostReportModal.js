import React, { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import { getProductionCostReport } from "../../../services/ManufacturingService";
import { ActionButton, DataTable, LoadingPill, MetricCard, Notice, PageShell, Panel, Td, getBranchId, getErrorMessage, getPayloadData, moneyNumber, normalizeRows, qtyNumber, today, useNotice } from "./ManufacturingShared";

export default function ManufacturingCostReportModal({ open, onClose, currentUser = null }) {
  const branchId = getBranchId(currentUser);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const { notice, showNotice } = useNotice();
  const loadReport = async () => {
    try { setLoading(true); const res = await getProductionCostReport({ branch_id: branchId || undefined, date_from: today(), date_to: today() }); setReport(getPayloadData(res)); }
    catch (error) { showNotice(getErrorMessage(error, "Failed to load production cost report.")); }
    finally { setLoading(false); }
  };
  useEffect(() => { if (open) loadReport(); }, [open]);
  if (!open) return null;
  const rows = normalizeRows(report?.recent_production);
  return <FullScreenModal open={open} onClose={onClose} title="PRODUCTION COST REPORT" subtitle="Material, total and variance costing">
    <Notice notice={notice} /><LoadingPill loading={loading} />
    <PageShell title="Production Cost Report" subtitle="Cost report from reports/production-costs." actions={<ActionButton onClick={loadReport} label="Refresh" icon={RefreshCw} primary />}>
      <div className="grid gap-3 md:grid-cols-4">
        <MetricCard title="Material Cost" value={moneyNumber(report?.material_cost ?? 0)} />
        <MetricCard title="Actual Total Cost" value={moneyNumber(report?.actual_total_cost ?? 0)} />
        <MetricCard title="Planned Total Cost" value={moneyNumber(report?.planned_total_cost ?? 0)} />
        <MetricCard title="Cost Variance" value={moneyNumber(report?.cost_variance_amount ?? 0)} />
      </div>
      <Panel title="Cost Rows">
        <DataTable columns={["Production No", "Product", "Qty", "Total Cost", "Status"]} emptyText="No costing rows found.">
          {rows.map((row) => <tr key={row.id} className="border-b border-[#E9EDED]"><Td>{row.production_order_code}</Td><Td strong>{row.product_name || "-"}</Td><Td right>{qtyNumber(row.completed_quantity)}</Td><Td right>{moneyNumber(row.total_cost)}</Td><Td>{row.status}</Td></tr>)}
        </DataTable>
      </Panel>
    </PageShell>
  </FullScreenModal>;
}
