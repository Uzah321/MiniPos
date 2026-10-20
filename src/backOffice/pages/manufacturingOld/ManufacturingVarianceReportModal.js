import React, { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import { getProductionVarianceReport } from "../../../services/ManufacturingService";
import { ActionButton, DataTable, LoadingPill, Notice, PageShell, StatusBadge, Td, getBranchId, getErrorMessage, moneyNumber, normalizeRows, qtyNumber, today, useNotice } from "./ManufacturingShared";

export default function ManufacturingVarianceReportModal({ open, onClose, currentUser = null }) {
  const branchId = getBranchId(currentUser);
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const { notice, showNotice } = useNotice();
  const loadReport = async () => {
    try { setLoading(true); const res = await getProductionVarianceReport({ branch_id: branchId || undefined, date_from: today(), date_to: today(), per_page: 100 }); setRows(normalizeRows(res)); }
    catch (error) { showNotice(getErrorMessage(error, "Failed to load variance report.")); }
    finally { setLoading(false); }
  };
  useEffect(() => { if (open) loadReport(); }, [open]);
  if (!open) return null;
  return <FullScreenModal open={open} onClose={onClose} title="PRODUCTION VARIANCE REPORT" subtitle="Completed order yield and cost variance">
    <Notice notice={notice} /><LoadingPill loading={loading} />
    <PageShell title="Production Variance" subtitle="Completed production orders only." actions={<ActionButton onClick={loadReport} label="Refresh" icon={RefreshCw} primary />}>
      <DataTable columns={["Production No", "Product", "Planned Qty", "Good Qty", "Unit Cost", "Cost Variance", "Status"]} emptyText="No variance rows found.">
        {rows.map((row) => <tr key={row.id} className="border-b border-[#E9EDED]"><Td>{row.production_number}</Td><Td strong>{row.finished_product?.name || "-"}</Td><Td right>{qtyNumber(row.planned_output_quantity)}</Td><Td right>{qtyNumber(row.actual_good_quantity)}</Td><Td right>{moneyNumber(row.actual_unit_cost)}</Td><Td right>{moneyNumber(row.cost_variance_amount)}</Td><Td><StatusBadge status={row.status} /></Td></tr>)}
      </DataTable>
    </PageShell>
  </FullScreenModal>;
}
