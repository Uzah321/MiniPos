import React, { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import { getProductionSummaryReport } from "../../../services/ManufacturingService";
import { ActionButton, DataTable, LoadingPill, MetricCard, Notice, PageShell, Panel, Td, getBranchId, getErrorMessage, getPayloadData, moneyNumber, normalizeRows, qtyNumber, today, useNotice } from "./ManufacturingShared";

export default function ManufacturingProductionReportModal({ open, onClose, currentUser = null }) {
  const branchId = getBranchId(currentUser);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const { notice, showNotice } = useNotice();

  const loadReport = async () => {
    try {
      setLoading(true);
      const res = await getProductionSummaryReport({ branch_id: branchId || undefined, date_from: today(), date_to: today() });
      setReport(getPayloadData(res));
    } catch (error) { showNotice(getErrorMessage(error, "Failed to load production report.")); }
    finally { setLoading(false); }
  };

  useEffect(() => { if (open) loadReport(); }, [open]);
  if (!open) return null;
  const rows = normalizeRows(report?.recent_production);

  return <FullScreenModal open={open} onClose={onClose} title="PRODUCTION SUMMARY REPORT" subtitle="Daily manufacturing production performance">
    <Notice notice={notice} /><LoadingPill loading={loading} />
    <PageShell title="Production Summary" subtitle="Summary from reports/production-summary." actions={<ActionButton onClick={loadReport} label="Refresh" icon={RefreshCw} primary />}>
      <div className="grid gap-3 md:grid-cols-4">
        <MetricCard title="Orders" value={report?.orders ?? 0} />
        <MetricCard title="Completed" value={report?.completed_orders ?? 0} />
        <MetricCard title="Produced Qty" value={qtyNumber(report?.produced_quantity ?? 0)} />
        <MetricCard title="Avg Cost / Unit" value={moneyNumber(report?.average_cost_per_unit ?? 0)} />
      </div>
      <Panel title="Recent Production">
        <DataTable columns={["Production No", "Product", "Completed Qty", "Total Cost", "Status"]} emptyText="No production rows found.">
          {rows.map((row) => <tr key={row.id} className="border-b border-[#E9EDED]"><Td>{row.production_order_code}</Td><Td strong>{row.product_name || "-"}</Td><Td right>{qtyNumber(row.completed_quantity)}</Td><Td right>{moneyNumber(row.total_cost)}</Td><Td>{row.status}</Td></tr>)}
        </DataTable>
      </Panel>
    </PageShell>
  </FullScreenModal>;
}
