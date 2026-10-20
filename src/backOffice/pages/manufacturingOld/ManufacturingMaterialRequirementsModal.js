import React, { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import { getMaterialRequirementsReport } from "../../../services/ManufacturingService";
import { ActionButton, DataTable, LoadingPill, Notice, PageShell, StatusBadge, Td, getBranchId, getErrorMessage, normalizeRows, qtyNumber, useNotice } from "./ManufacturingShared";

export default function ManufacturingMaterialRequirementsModal({ open, onClose, currentUser = null }) {
  const branchId = getBranchId(currentUser);
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const { notice, showNotice } = useNotice();

  const loadRows = async () => {
    try {
      setLoading(true);
      const res = await getMaterialRequirementsReport({ branch_id: branchId || undefined, per_page: 100 });
      setRows(normalizeRows(res));
    } catch (error) { showNotice(getErrorMessage(error, "Failed to load material requirements.")); }
    finally { setLoading(false); }
  };

  useEffect(() => { if (open) loadRows(); }, [open]);

  if (!open) return null;
  return (
    <FullScreenModal open={open} onClose={onClose} title="MATERIAL REQUIREMENTS" subtitle="Required, available, reserved and shortage quantities">
      <Notice notice={notice} />
      <LoadingPill loading={loading} />
      <PageShell title="Material Requirements" subtitle="Material requirements generated from production plans." actions={<ActionButton onClick={loadRows} label="Refresh" icon={RefreshCw} primary />}>
        <DataTable columns={["Product", "Required", "Available", "Reserved", "Shortage", "Status"]} emptyText="No material requirements found.">
          {rows.map((row) => <tr key={row.id} className="border-b border-[#E9EDED]"><Td strong>{row.product?.name || row.product_name || "-"}</Td><Td right>{qtyNumber(row.required_quantity)}</Td><Td right>{qtyNumber(row.available_quantity)}</Td><Td right>{qtyNumber(row.reserved_quantity)}</Td><Td right>{qtyNumber(row.shortage_quantity)}</Td><Td><StatusBadge status={row.status} /></Td></tr>)}
        </DataTable>
      </PageShell>
    </FullScreenModal>
  );
}
