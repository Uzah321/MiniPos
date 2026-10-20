// src/backOffice/pages/manufacturing/ManufacturingEnterpriseCommandCenterModal.js

import React, { useEffect, useMemo, useState } from "react";
import {
  Activity,
  AlertTriangle,
  BarChart3,
  Calculator,
  ClipboardList,
  Download,
  Factory,
  FileSpreadsheet,
  FileText,
  Layers,
  PackageCheck,
  RefreshCw,
  Search,
  Truck,
  Users,
  Warehouse,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { getBaseCurrencies } from "../../../services/CurrencyService";
import {
  getAccountingLedgerReport,
  getFinishedGoodsReceivedReport,
  getMaterialRequirementsReport,
  getMaterialUsageReport,
  getProductionCostReport,
  getProductionOrders,
  getProductionSummaryReport,
  getProductionVarianceReport,
  getShortageAlertsReport,
  getWastageReport,
  getWipReport,
  getEnterpriseManufacturingDashboard,
  getConsolidatedProductionReport,
  getDirectIndirectCostReport,
  getStaffProductionCostReport,
  downloadEnterpriseManufacturingActivity,
} from "../../../services/ManufacturingService";

import {
  ActionButton,
  CostBar,
  GridTd,
  GridTh,
  LoadingPill,
  MetricCard,
  Notice,
  Panel,
  ReadinessLine,
  SectionTitle,
  SmallMetric,
  StatusBadge,
  getBranchId,
  getErrorMessage,
  moneyNumber,
  moneyWithCurrency,
  normalizeRows,
  numberValue,
  qtyNumber,
  safeDate,
  statusLabel,
  titleCase,
  useNotice,
} from "./ManufacturingShared";

const statusOptions = [
  ["", "All Statuses"],
  ["draft", "Draft"],
  ["approved", "Approved"],
  ["materials_reserved", "Materials Reserved"],
  ["materials_issued", "Materials Issued"],
  ["in_progress", "In Progress"],
  ["completed", "Completed"],
  ["cancelled", "Cancelled"],
];

const dataObj = (response) => response?.data?.data || response?.data || response || {};

const flattenRows = (response) => {
  const obj = dataObj(response);

  if (Array.isArray(obj)) return obj;
  if (Array.isArray(obj.data)) return obj.data;
  if (Array.isArray(obj.rows)) return obj.rows;
  if (Array.isArray(obj.orders)) return obj.orders;
  if (Array.isArray(obj.recent_orders)) return obj.recent_orders;
  if (Array.isArray(obj.recent_production)) return obj.recent_production;
  if (Array.isArray(obj.materials)) return obj.materials;
  if (Array.isArray(obj.requirements)) return obj.requirements;
  if (Array.isArray(obj.finished_goods)) return obj.finished_goods;
  if (Array.isArray(obj.wip)) return obj.wip;
  if (Array.isArray(obj.ledger)) return obj.ledger;
  if (Array.isArray(obj.entries)) return obj.entries;
  if (Array.isArray(obj.inputs)) return obj.inputs;
  if (Array.isArray(obj.outputs)) return obj.outputs;
  if (Array.isArray(obj.overheads)) return obj.overheads;

  return normalizeRows(response);
};

const getProductName = (row) =>
  row?.product_name ||
  row?.finished_product_name ||
  row?.finished_product ||
  row?.material_name ||
  row?.material ||
  row?.finished_product?.name ||
  row?.finishedProduct?.name ||
  row?.product?.name ||
  row?.metadata?.product_name ||
  row?.metadata?.finished_product_name ||
  "-";

const getProductCode = (row) =>
  row?.product_code ||
  row?.finished_product_code ||
  row?.material_code ||
  row?.finished_product?.code ||
  row?.finishedProduct?.code ||
  row?.product?.code ||
  row?.metadata?.product_code ||
  row?.metadata?.finished_product_code ||
  "-";

const getProductionNumber = (row) =>
  row?.production_order_code ||
  row?.production_number ||
  row?.production_order?.production_number ||
  row?.order?.production_number ||
  row?.plan_number ||
  row?.production_plan?.plan_number ||
  row?.plan?.plan_number ||
  "-";

const getCurrency = (row, fallback = "USD") =>
  row?.currency || row?.metadata?.currency || row?.product?.currency || fallback || "USD";

const directCost = (row) =>
  numberValue(row.direct_material_cost || row.material_cost || row.actual_raw_material_cost) +
  numberValue(row.direct_labour_cost || row.labour_cost || row.actual_labour_cost);

const indirectCost = (row) =>
  numberValue(row.indirect_overhead_cost || row.overhead_cost || row.actual_overhead_cost);

const totalCost = (row) =>
  numberValue(
    row.actual_total_cost ||
      row.total_cost ||
      row.planned_total_cost ||
      row.amount ||
      directCost(row) + indirectCost(row)
  );

const rowDate = (row) =>
  safeDate(
    row.completed_at ||
      row.created_at ||
      row.required_by ||
      row.transaction_date ||
      row.movement_date
  );

const buildParams = ({ branchId, dateFrom, dateTo, status, search }) => ({
  branch_id: branchId || undefined,
  date_from: dateFrom || undefined,
  date_to: dateTo || undefined,
  status: status || undefined,
  q: search?.trim() || undefined,
  per_page: 500,
});

export default function ManufacturingEnterpriseCommandCenterModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [baseCurrencies, setBaseCurrencies] = useState([]);
  const defaultCurrency = baseCurrencies?.[0]?.code || baseCurrencies?.[0]?.currency || "USD";

  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [activeTab, setActiveTab] = useState("executive");

  const [summary, setSummary] = useState({});
  const [orders, setOrders] = useState([]);
  const [costRows, setCostRows] = useState([]);
  const [materialRows, setMaterialRows] = useState([]);
  const [wasteRows, setWasteRows] = useState([]);
  const [wipRows, setWipRows] = useState([]);
  const [finishedRows, setFinishedRows] = useState([]);
  const [ledgerRows, setLedgerRows] = useState([]);
  const [varianceRows, setVarianceRows] = useState([]);
  const [shortageRows, setShortageRows] = useState([]);
  const [directIndirectRows, setDirectIndirectRows] = useState([]);
  const [staffRows, setStaffRows] = useState([]);

  const { notice, showNotice, closeNotice } = useNotice();

  const params = useMemo(
    () => buildParams({ branchId, dateFrom, dateTo, status, search }),
    [branchId, dateFrom, dateTo, status, search]
  );

  const loadCurrencies = async () => {
    try {
      const res = await getBaseCurrencies();
      setBaseCurrencies(normalizeRows(res));
    } catch (_) {
      setBaseCurrencies([]);
    }
  };

  const loadEnterpriseView = async () => {
    try {
      setLoading(true);

      const [
        summaryRes,
        orderRes,
        costRes,
        materialRes,
        wasteRes,
        wipRes,
        finishedRes,
        ledgerRes,
        varianceRes,
        shortageRes,
        directIndirectRes,
        staffRes,
      ] = await Promise.all([
        getEnterpriseManufacturingDashboard(params).catch(() => getProductionSummaryReport(params)),
        getProductionOrders(params),
        getProductionCostReport(params),
        getMaterialUsageReport(params),
        getWastageReport(params),
        getWipReport(params),
        getFinishedGoodsReceivedReport(params),
        getAccountingLedgerReport(params),
        getProductionVarianceReport(params),
        getShortageAlertsReport(params),
        getDirectIndirectCostReport(params).catch(() => ({ data: { rows: [] } })),
        getStaffProductionCostReport(params).catch(() => ({ data: { rows: [] } })),
      ]);

      setSummary(dataObj(summaryRes));
      setOrders(flattenRows(orderRes));
      setCostRows(flattenRows(costRes));
      setMaterialRows(flattenRows(materialRes));
      setWasteRows(flattenRows(wasteRes));
      setWipRows(flattenRows(wipRes));
      setFinishedRows(flattenRows(finishedRes));
      setLedgerRows(flattenRows(ledgerRes));
      setVarianceRows(flattenRows(varianceRes));
      setShortageRows(flattenRows(shortageRes));
      setDirectIndirectRows(flattenRows(directIndirectRes));
      setStaffRows(flattenRows(staffRes));
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load enterprise manufacturing view."),
        "error",
        "Enterprise View Failed"
      );
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    loadCurrencies();
    loadEnterpriseView();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const timer = window.setTimeout(loadEnterpriseView, 350);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateFrom, dateTo, status, search]);

  const metrics = useMemo(() => {
    const allRows = costRows.length ? costRows : orders;

    const plannedQty =
      numberValue(summary.planned_output_quantity) ||
      allRows.reduce((sum, row) => sum + numberValue(row.planned_output_quantity || row.planned_quantity), 0);

    const goodQty =
      numberValue(summary.actual_good_quantity) ||
      allRows.reduce(
        (sum, row) =>
          sum + numberValue(row.actual_good_quantity || row.completed_quantity || row.quantity_produced),
        0
      );

    const rejectQty =
      numberValue(summary.actual_reject_quantity) ||
      allRows.reduce((sum, row) => sum + numberValue(row.actual_reject_quantity || row.reject_quantity), 0);

    const wasteQty =
      numberValue(summary.actual_waste_quantity) ||
      wasteRows.reduce((sum, row) => sum + numberValue(row.waste_quantity || row.wasted_quantity || row.actual_waste_quantity), 0);

    const materialCost =
      numberValue(summary.direct_material_cost) ||
      costRows.reduce((sum, row) => sum + numberValue(row.direct_material_cost || row.material_cost || row.actual_raw_material_cost), 0);

    const labourCost =
      numberValue(summary.direct_labour_cost) ||
      costRows.reduce((sum, row) => sum + numberValue(row.direct_labour_cost || row.labour_cost || row.actual_labour_cost), 0);

    const overheadCost =
      numberValue(summary.indirect_overhead_cost) ||
      costRows.reduce((sum, row) => sum + numberValue(row.indirect_overhead_cost || row.overhead_cost || row.actual_overhead_cost), 0);

    const plannedCost =
      numberValue(summary.planned_total_cost) ||
      costRows.reduce((sum, row) => sum + numberValue(row.planned_total_cost), 0);

    const actualCost =
      numberValue(summary.actual_total_cost) || materialCost + labourCost + overheadCost;

    const shortageQty = shortageRows.reduce((sum, row) => sum + numberValue(row.shortage_quantity), 0);
    const requiredQty = shortageRows.reduce((sum, row) => sum + numberValue(row.required_quantity), 0);

    return {
      totalOrders: numberValue(summary.orders) || numberValue(summary.total_orders) || orders.length,
      completedOrders:
        numberValue(summary.completed_orders) || orders.filter((row) => row.status === "completed").length,
      inProgressOrders:
        numberValue(summary.in_progress_orders) || orders.filter((row) => row.status === "in_progress").length,
      plannedQty,
      goodQty,
      rejectQty,
      wasteQty,
      materialCost,
      labourCost,
      overheadCost,
      plannedCost,
      actualCost,
      variance: actualCost - plannedCost,
      unitCost: goodQty > 0 ? actualCost / goodQty : 0,
      yieldPercent: plannedQty > 0 ? (goodQty / plannedQty) * 100 : 0,
      lossPercent: plannedQty > 0 ? ((rejectQty + wasteQty) / plannedQty) * 100 : 0,
      shortageQty,
      requiredQty,
      shortagePercent: requiredQty > 0 ? (shortageQty / requiredQty) * 100 : 0,
    };
  }, [summary, orders, costRows, wasteRows, shortageRows]);

  const activityRows = useMemo(() => {
    const activities = [];

    costRows.forEach((row) => {
      activities.push({
        type: "Costing",
        date: rowDate(row),
        production: getProductionNumber(row),
        product: getProductName(row),
        status: row.status || row.order_status || "-",
        quantity: row.actual_good_quantity || row.planned_quantity || row.planned_output_quantity || "-",
        directCost: directCost(row),
        indirectCost: indirectCost(row),
        totalCost: totalCost(row),
        staff: row.staff_name || row.created_by_name || row.metadata?.staff_name || "-",
      });
    });

    materialRows.forEach((row) => {
      activities.push({
        type: "Material Movement",
        date: rowDate(row),
        production: getProductionNumber(row),
        product: getProductName(row),
        status: row.order_status || row.status || "-",
        quantity: row.net_consumed_quantity || row.issued_quantity || row.planned_quantity || "-",
        directCost: numberValue(row.total_cost),
        indirectCost: 0,
        totalCost: numberValue(row.total_cost),
        staff: row.staff_name || row.created_by_name || row.metadata?.staff_name || "-",
      });
    });

    wasteRows.forEach((row) => {
      activities.push({
        type: "Wastage / Loss",
        date: rowDate(row),
        production: getProductionNumber(row),
        product: getProductName(row),
        status: row.order_status || row.status || "-",
        quantity: row.waste_quantity || row.wasted_quantity || row.actual_waste_quantity || "-",
        directCost: numberValue(row.total_cost || row.waste_cost),
        indirectCost: 0,
        totalCost: numberValue(row.total_cost || row.waste_cost),
        staff: row.staff_name || row.created_by_name || row.metadata?.staff_name || "-",
      });
    });

    finishedRows.forEach((row) => {
      activities.push({
        type: "Finished Goods Received",
        date: rowDate(row),
        production: getProductionNumber(row),
        product: getProductName(row),
        status: row.order_status || row.status || "completed",
        quantity: row.quantity_received || row.quantity_produced || row.actual_good_quantity || "-",
        directCost: 0,
        indirectCost: 0,
        totalCost: numberValue(row.total_cost || row.actual_total_cost),
        staff: row.staff_name || row.created_by_name || row.metadata?.staff_name || "-",
      });
    });

    return activities.sort((a, b) => String(b.date).localeCompare(String(a.date)));
  }, [costRows, materialRows, wasteRows, finishedRows]);

  const consolidatedRows = useMemo(() => {
    const map = new Map();

    activityRows.forEach((row) => {
      const key = `${row.product}::${row.status}`;
      const current = map.get(key) || {
        product: row.product,
        status: row.status,
        activities: 0,
        quantity: 0,
        directCost: 0,
        indirectCost: 0,
        totalCost: 0,
      };

      current.activities += 1;
      current.quantity += numberValue(row.quantity);
      current.directCost += numberValue(row.directCost);
      current.indirectCost += numberValue(row.indirectCost);
      current.totalCost += numberValue(row.totalCost);

      map.set(key, current);
    });

    return Array.from(map.values()).sort((a, b) => b.totalCost - a.totalCost);
  }, [activityRows]);

  const downloadExcel = async () => {
    try {
      await downloadEnterpriseManufacturingActivity("xlsx", params);
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to download the enterprise manufacturing workbook."),
        "error",
        "Download Failed"
      );
    }
  };

  const downloadPdf = async () => {
    try {
      await downloadEnterpriseManufacturingActivity("pdf", params);
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to download the enterprise manufacturing PDF."),
        "error",
        "Download Failed"
      );
    }
  };

  const clearFilters = () => {
    setDateFrom("");
    setDateTo("");
    setStatus("");
    setSearch("");
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="ENTERPRISE MANUFACTURING COMMAND CENTER"
      subtitle="Executive costing, activity tracking, staff view, material control, WIP and consolidated production analytics"
    >
      <Notice notice={notice} onClose={closeNotice} />
      <LoadingPill loading={loading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
            <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
              <div className="flex items-center gap-2">
                <div className="text-[13px] font-semibold text-[#0C1B1B]">Enterprise control room</div>
                <div className="h-4 border-l border-[#AAB5B5]" />
                <div className="text-[10px] font-medium uppercase text-[#627474]">
                  Consolidates planning, execution, costing, wastage, WIP and ledger activity
                </div>
              </div>

              <div className="flex gap-1">
                <ActionButton onClick={loadEnterpriseView} label="Refresh" icon={RefreshCw} primary />
                <ActionButton onClick={downloadExcel} label="Excel" icon={FileSpreadsheet} />
                <ActionButton onClick={downloadPdf} label="PDF" icon={Download} />
                <button
                  type="button"
                  onClick={clearFilters}
                  className="h-7 border border-[#B9C3C3] bg-white px-3 text-[11px] font-semibold uppercase text-[#324443]"
                >
                  Clear
                </button>
              </div>
            </div>

            <div className="grid grid-cols-[160px_160px_190px_minmax(260px,1fr)] gap-2 px-2 py-2">
              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Date From</div>
                <input
                  type="date"
                  value={dateFrom}
                  onChange={(event) => setDateFrom(event.target.value)}
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                />
              </label>

              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Date To</div>
                <input
                  type="date"
                  value={dateTo}
                  onChange={(event) => setDateTo(event.target.value)}
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                />
              </label>

              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Status</div>
                <select
                  value={status}
                  onChange={(event) => setStatus(event.target.value)}
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                >
                  {statusOptions.map(([key, text]) => (
                    <option key={key} value={key}>{text}</option>
                  ))}
                </select>
              </label>

              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Search</div>
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                  <input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Production no, product, material, staff..."
                    className="h-8 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none"
                  />
                </div>
              </label>
            </div>
          </div>

          <div className="grid min-h-[112px] grid-cols-6 gap-2 border-b border-[#AAB5B5] bg-[#F7F8FA] p-2">
            <MetricCard title="Orders" value={metrics.totalOrders} icon={ClipboardList} />
            <MetricCard title="Completed" value={metrics.completedOrders} icon={PackageCheck} />
            <MetricCard title="Yield" value={`${metrics.yieldPercent.toFixed(1)}%`} icon={BarChart3} danger={metrics.yieldPercent > 0 && metrics.yieldPercent < 90} />
            <MetricCard title="Actual Cost" value={moneyWithCurrency(defaultCurrency, metrics.actualCost)} icon={Calculator} />
            <MetricCard title="Unit Cost" value={moneyWithCurrency(defaultCurrency, metrics.unitCost)} icon={Warehouse} />
            <MetricCard title="Shortage" value={qtyNumber(metrics.shortageQty)} icon={AlertTriangle} danger={metrics.shortageQty > 0} />
          </div>

          <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
            {[
              ["executive", "Executive"],
              ["activities", "Activity Ledger"],
              ["consolidated", "Consolidated"],
              ["staff", "Staff Costs"],
              ["direct_indirect", "Direct vs Indirect"],
              ["costs", "Cost Breakdown"],
              ["materials", "Materials / WIP"],
              ["governance", "Controls"],
            ].map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => setActiveTab(key)}
                className={`h-7 border border-b-0 px-4 text-[11px] font-semibold ${
                  activeTab === key
                    ? "border-[#31B44B] bg-[#31B44B] text-white"
                    : "border-[#AAB5B5] bg-white text-[#324443] hover:bg-[#EEF2F2]"
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          <div className="min-h-0 flex-1 overflow-hidden">
            {activeTab === "executive" && (
              <ExecutiveTab metrics={metrics} currency={defaultCurrency} activityRows={activityRows} shortageRows={shortageRows} varianceRows={varianceRows} />
            )}
            {activeTab === "activities" && <ActivityLedger rows={activityRows} currency={defaultCurrency} />}
            {activeTab === "consolidated" && <ConsolidatedTab rows={consolidatedRows} currency={defaultCurrency} />}
            {activeTab === "staff" && <GenericEnterpriseTable title="Staff and Cost Responsibility" rows={staffRows} />}
            {activeTab === "direct_indirect" && <GenericEnterpriseTable title="Direct Material/Labour vs Indirect Overheads" rows={directIndirectRows} />}
            {activeTab === "costs" && <CostsTab metrics={metrics} costRows={costRows} ledgerRows={ledgerRows} currency={defaultCurrency} />}
            {activeTab === "materials" && <MaterialsWipTab materialRows={materialRows} wipRows={wipRows} shortageRows={shortageRows} currency={defaultCurrency} />}
            {activeTab === "governance" && <GovernanceTab metrics={metrics} activityRows={activityRows} />}
          </div>
        </div>
      </div>

      <NoRadiusStyles />
    </FullScreenModal>
  );
}

function ExecutiveTab({ metrics, currency, activityRows, shortageRows, varianceRows }) {
  const total = Math.max(metrics.actualCost, metrics.plannedCost, 1);

  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-[1.1fr_0.9fr]">
        <Panel title="Executive Production Health" subtitle="Top-level business position">
          <div className="grid gap-2 md:grid-cols-3">
            <SmallMetric label="Open / WIP Orders" value={metrics.inProgressOrders} />
            <SmallMetric label="Planned Quantity" value={qtyNumber(metrics.plannedQty)} />
            <SmallMetric label="Good Quantity" value={qtyNumber(metrics.goodQty)} />
            <SmallMetric label="Reject Quantity" value={qtyNumber(metrics.rejectQty)} danger={metrics.rejectQty > 0} />
            <SmallMetric label="Waste Quantity" value={qtyNumber(metrics.wasteQty)} danger={metrics.wasteQty > 0} />
            <SmallMetric label="Loss %" value={`${metrics.lossPercent.toFixed(1)}%`} danger={metrics.lossPercent > 5} />
            <SmallMetric label="Cost Variance" value={moneyWithCurrency(currency, metrics.variance)} danger={metrics.variance > 0} />
            <SmallMetric label="Activities" value={activityRows.length} />
            <SmallMetric label="Shortage Lines" value={shortageRows.length} danger={shortageRows.length > 0} />
          </div>
        </Panel>

        <Panel title="Cost Composition" subtitle="Direct and indirect cost control">
          <CostBar label="Direct Material" value={metrics.materialCost} total={total} currency={currency} />
          <CostBar label="Direct Labour" value={metrics.labourCost} total={total} currency={currency} />
          <CostBar label="Indirect Overhead" value={metrics.overheadCost} total={total} currency={currency} />
          <CostBar label="Variance" value={Math.abs(metrics.variance)} total={Math.max(total, Math.abs(metrics.variance))} currency={currency} />
        </Panel>

        <Panel title="Management Alerts" subtitle="Operational actions required">
          <ReadinessLine label="Material shortages" ok={shortageRows.length === 0} value={`${shortageRows.length} line(s)`} />
          <ReadinessLine label="Cost variance" ok={metrics.variance <= 0} value={moneyWithCurrency(currency, metrics.variance)} />
          <ReadinessLine label="Yield above 90%" ok={metrics.yieldPercent >= 90 || metrics.goodQty <= 0} value={`${metrics.yieldPercent.toFixed(1)}%`} />
          <ReadinessLine label="Variance report rows" ok={varianceRows.length === 0 || metrics.variance <= 0} value={`${varianceRows.length} row(s)`} />
        </Panel>

        <Panel title="Enterprise Stage Model" subtitle="Use this sequence for every production run">
          <StageLine index="01" title="BOM / Recipe" text="Define formula, batch output, material units and average-cost based material value." />
          <StageLine index="02" title="Plan" text="Create demand plan, calculate material requirements and approve." />
          <StageLine index="03" title="Convert" text="Convert approved plan into production orders with planned costs." />
          <StageLine index="04" title="Reserve / Issue" text="Reserve stock, issue materials and move cost into WIP." />
          <StageLine index="05" title="Execute" text="Start production, capture staff/labour, direct expenses and indirect overheads." />
          <StageLine index="06" title="Complete / Report" text="Receive finished goods, capture reject/waste, calculate unit cost and review variance." />
        </Panel>
      </div>
    </div>
  );
}

function ActivityLedger({ rows, currency }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1450px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>#</GridTh>
            <GridTh>Activity</GridTh>
            <GridTh>Date</GridTh>
            <GridTh>Production No</GridTh>
            <GridTh>Product / Material</GridTh>
            <GridTh>Status</GridTh>
            <GridTh align="right">Qty</GridTh>
            <GridTh align="right">Direct Cost</GridTh>
            <GridTh align="right">Indirect Cost</GridTh>
            <GridTh align="right">Total Cost</GridTh>
            <GridTh>Staff / Operator</GridTh>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr><td colSpan={11} className="px-3 py-10 text-center text-[#627474]">No activity rows found.</td></tr>
          ) : rows.map((row, index) => (
            <tr key={`${row.type}-${index}`} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
              <GridTd>{index + 1}</GridTd>
              <GridTd strong>{row.type}</GridTd>
              <GridTd>{row.date}</GridTd>
              <GridTd>{row.production}</GridTd>
              <GridTd strong>{row.product}</GridTd>
              <GridTd><StatusBadge status={row.status} /></GridTd>
              <GridTd right>{qtyNumber(row.quantity)}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, row.directCost)}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, row.indirectCost)}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, row.totalCost)}</GridTd>
              <GridTd>{row.staff}</GridTd>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ConsolidatedTab({ rows, currency }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1050px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>#</GridTh>
            <GridTh>Product / Group</GridTh>
            <GridTh>Status</GridTh>
            <GridTh align="right">Activities</GridTh>
            <GridTh align="right">Quantity</GridTh>
            <GridTh align="right">Direct Cost</GridTh>
            <GridTh align="right">Indirect Cost</GridTh>
            <GridTh align="right">Total Cost</GridTh>
            <GridTh align="right">Avg Unit Cost</GridTh>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr><td colSpan={9} className="px-3 py-10 text-center text-[#627474]">No consolidated rows found.</td></tr>
          ) : rows.map((row, index) => (
            <tr key={`${row.product}-${row.status}`} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
              <GridTd>{index + 1}</GridTd>
              <GridTd strong>{row.product}</GridTd>
              <GridTd><StatusBadge status={row.status} /></GridTd>
              <GridTd right>{row.activities}</GridTd>
              <GridTd right>{qtyNumber(row.quantity)}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, row.directCost)}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, row.indirectCost)}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, row.totalCost)}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, row.quantity > 0 ? row.totalCost / row.quantity : 0)}</GridTd>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CostsTab({ metrics, costRows, ledgerRows, currency }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard title="Direct Material" value={moneyWithCurrency(currency, metrics.materialCost)} icon={Layers} />
        <MetricCard title="Direct Labour" value={moneyWithCurrency(currency, metrics.labourCost)} icon={Users} />
        <MetricCard title="Indirect Overhead" value={moneyWithCurrency(currency, metrics.overheadCost)} icon={Factory} />
        <MetricCard title="Total Cost" value={moneyWithCurrency(currency, metrics.actualCost)} icon={Calculator} />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-2">
        <Panel title="Production Cost Rows" subtitle="Order-level planned vs actual cost">
          <CompactCostTable rows={costRows} currency={currency} />
        </Panel>

        <Panel title="Accounting Ledger Preview" subtitle="Manufacturing ledger rows from report endpoint">
          <CompactLedger rows={ledgerRows} currency={currency} />
        </Panel>
      </div>
    </div>
  );
}

function MaterialsWipTab({ materialRows, wipRows, shortageRows, currency }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-3">
        <Panel title="Material Usage" subtitle="Issued, returned, wasted and net consumed">
          <MiniReport rows={materialRows} currency={currency} mode="material" />
        </Panel>
        <Panel title="Work In Progress" subtitle="Orders still carrying cost before completion">
          <MiniReport rows={wipRows} currency={currency} mode="wip" />
        </Panel>
        <Panel title="Shortage Alerts" subtitle="Materials requiring purchasing or action">
          <MiniReport rows={shortageRows} currency={currency} mode="shortage" />
        </Panel>
      </div>
    </div>
  );
}

function GovernanceTab({ metrics, activityRows }) {
  const hasStaffGaps = activityRows.some((row) => !row.staff || row.staff === "-");

  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-3">
        <Panel title="Production Controls" subtitle="Enterprise process checks">
          <ReadinessLine label="Activity trail exists" ok={activityRows.length > 0} value={`${activityRows.length} row(s)`} />
          <ReadinessLine label="Staff captured" ok={!hasStaffGaps && activityRows.length > 0} value={hasStaffGaps ? "Some rows missing" : "Available"} />
          <ReadinessLine label="Direct cost captured" ok={metrics.materialCost + metrics.labourCost > 0} value={moneyNumber(metrics.materialCost + metrics.labourCost)} />
          <ReadinessLine label="Indirect cost captured" ok={metrics.overheadCost >= 0} value={moneyNumber(metrics.overheadCost)} />
          <ReadinessLine label="Yield controlled" ok={metrics.yieldPercent >= 90 || metrics.goodQty <= 0} value={`${metrics.yieldPercent.toFixed(1)}%`} />
        </Panel>

        <Panel title="Expense Classification" subtitle="How costs should be captured">
          <StageLine index="D" title="Direct material" text="Raw material, packaging and semi-finished input consumed into production." />
          <StageLine index="D" title="Direct labour" text="Operator or staff cost that can be traced to one production order." />
          <StageLine index="I" title="Indirect overhead" text="Electricity, water, rent, depreciation, maintenance, fuel and general factory cost." />
          <StageLine index="L" title="Loss and wastage" text="Material wastage, rejects and finished product waste must remain visible in reports." />
        </Panel>

        <Panel title="Recommended Additions" subtitle="Backend fields to add when ready">
          <StageLine index="1" title="Staff assignment" text="Add production_order_staff table for operator, supervisor, hours and labour rate." />
          <StageLine index="2" title="Machine usage" text="Capture machine, runtime hours, downtime and maintenance cost by order." />
          <StageLine index="3" title="Quality checks" text="Capture QC passed, failed, reason, inspector and corrective action." />
          <StageLine index="4" title="Batch traceability" text="Link material lots to finished goods lots for recall and audit." />
        </Panel>
      </div>
    </div>
  );
}

function CompactCostTable({ rows, currency }) {
  return (
    <div className="max-h-[430px] overflow-auto">
      <table className="w-full min-w-[760px] border-collapse text-[11px]">
        <thead className="sticky top-0 bg-[#0E4D4D] text-white">
          <tr><GridTh>Production</GridTh><GridTh>Product</GridTh><GridTh align="right">Material</GridTh><GridTh align="right">Labour</GridTh><GridTh align="right">Overhead</GridTh><GridTh align="right">Total</GridTh></tr>
        </thead>
        <tbody>
          {rows.length === 0 ? <tr><td colSpan={6} className="px-3 py-8 text-center text-[#627474]">No cost rows.</td></tr> : rows.map((row, index) => (
            <tr key={index} className="border-b bg-white">
              <GridTd>{getProductionNumber(row)}</GridTd>
              <GridTd strong>{getProductName(row)}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, numberValue(row.direct_material_cost || row.material_cost || row.actual_raw_material_cost))}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, numberValue(row.direct_labour_cost || row.labour_cost || row.actual_labour_cost))}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, numberValue(row.indirect_overhead_cost || row.overhead_cost || row.actual_overhead_cost))}</GridTd>
              <GridTd right>{moneyWithCurrency(currency, totalCost(row))}</GridTd>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CompactLedger({ rows, currency }) {
  return (
    <div className="max-h-[430px] overflow-auto">
      <table className="w-full min-w-[760px] border-collapse text-[11px]">
        <thead className="sticky top-0 bg-[#0E4D4D] text-white">
          <tr><GridTh>Date</GridTh><GridTh>Production</GridTh><GridTh>Account</GridTh><GridTh>Type</GridTh><GridTh align="right">Amount</GridTh></tr>
        </thead>
        <tbody>
          {rows.length === 0 ? <tr><td colSpan={5} className="px-3 py-8 text-center text-[#627474]">No ledger rows.</td></tr> : rows.map((row, index) => (
            <tr key={index} className="border-b bg-white">
              <GridTd>{rowDate(row)}</GridTd>
              <GridTd>{getProductionNumber(row)}</GridTd>
              <GridTd strong>{row.account || row.account_name || row.description || "-"}</GridTd>
              <GridTd>{titleCase(row.entry_type || row.type || row.cost_type || "-")}</GridTd>
              <GridTd right>{moneyWithCurrency(getCurrency(row, currency), numberValue(row.amount || row.total_cost))}</GridTd>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function MiniReport({ rows, currency, mode }) {
  return (
    <div className="max-h-[520px] overflow-auto">
      {rows.length === 0 ? (
        <div className="py-10 text-center text-[12px] font-semibold text-[#627474]">No rows.</div>
      ) : rows.slice(0, 80).map((row, index) => (
        <div key={index} className="mb-2 border border-[#D8DFDF] bg-white p-2 text-[11px]">
          <div className="flex justify-between gap-2 font-semibold text-[#0C1B1B]">
            <span>{getProductName(row)}</span>
            <span>{getProductionNumber(row)}</span>
          </div>
          <div className="mt-1 grid grid-cols-2 gap-2">
            <SmallMetric label="Status" value={statusLabel(row.status || row.order_status || row.requirement_status || "-")} />
            <SmallMetric label="Date" value={rowDate(row)} />
            <SmallMetric label={mode === "shortage" ? "Shortage" : "Quantity"} value={qtyNumber(row.shortage_quantity || row.net_consumed_quantity || row.planned_quantity || row.quantity || row.actual_good_quantity)} danger={numberValue(row.shortage_quantity) > 0} />
            <SmallMetric label="Cost" value={moneyWithCurrency(getCurrency(row, currency), totalCost(row))} />
          </div>
        </div>
      ))}
    </div>
  );
}

function StageLine({ index, title, text }) {
  return (
    <div className="mb-2 grid grid-cols-[34px_1fr] gap-2 border border-[#D8DFDF] bg-[#F8FAFC] p-2 last:mb-0">
      <div className="flex h-7 w-7 items-center justify-center border border-[#027A48] bg-[#ECFDF3] text-[10px] font-bold text-[#027A48]">{index}</div>
      <div>
        <div className="text-[11px] font-semibold uppercase text-[#0C1B1B]">{title}</div>
        <div className="mt-0.5 text-[11px] leading-4 text-[#627474]">{text}</div>
      </div>
    </div>
  );
}

function GenericEnterpriseTable({ title, rows = [] }) {
  const headers = Object.keys(rows[0] || { Message: "No rows found." });

  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <Panel title={title} subtitle="Enterprise consolidated reporting">
        <div className="overflow-auto">
          <table className="w-full min-w-[1300px] border-collapse text-left text-[11px]">
            <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
              <tr>{headers.map((header) => <GridTh key={header}>{titleCase(header)}</GridTh>)}</tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr><td colSpan={headers.length} className="px-3 py-10 text-center text-[#627474]">No rows found.</td></tr>
              ) : rows.map((row, index) => (
                <tr key={index} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
                  {headers.map((header) => <GridTd key={header}>{String(row?.[header] ?? "-")}</GridTd>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
}

function NoRadiusStyles() {
  return (
    <style>
      {`
        button,
        input,
        select,
        textarea {
          border-radius: 0 !important;
        }
      `}
    </style>
  );
}
