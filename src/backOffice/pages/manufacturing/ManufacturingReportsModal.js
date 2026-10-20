import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  BarChart3,
  BookOpen,
  CheckSquare,
  ChevronDown,
  Download,
  Layers,
  RefreshCw,
  Search,
  Square,
  TrendingDown,
  TrendingUp,
  X,
} from 'lucide-react';

import FullScreenModal from '../../../components/FullScreenModal';
import HospitalityReportScopePanel, {
  useHospitalityReportLookups,
} from '../../../components/HospitalityReportScopePanel';
import ReportViewNavigator from '../../../components/ReportViewNavigator';
import ReportGenerationPrompt from '../../../components/ReportGenerationPrompt';
import ReportOutputChooser from '../../../components/ReportOutputChooser';
import ReportViewShell from '../../../components/ReportViewShell';
import {
  getAccountingLedgerReport,
  getFinishedGoodsReceivedReport,
  getManufacturingDashboardReport,
  getManyProductionPlanHistoryReports,
  getMaterialRequirementsReport,
  getMaterialUsageReport,
  getProductionCostReport,
  getProductionPlanComparisonReport,
  getProductionPlanHistoryReport,
  getProductionPlans,
  getProductionSummaryReport,
  getProductionVarianceReport,
  getShortageAlertsReport,
  getWastageReport,
  getWipReport,
  downloadManufacturingReport,
} from '../../../services/ManufacturingService';
import {
  LoadingPill,
  Notice,
  getBranchId,
  getErrorMessage,
  moneyNumber,
  normalizeRows,
  numberValue,
  qtyNumber,
  safeDate,
  statusLabel,
  titleCase,
  useNotice,
} from './ManufacturingShared';

const REPORTS = [
  ['production_summary', 'Production Summary'],
  ['production_costs', 'Production Costs'],
  ['material_usage', 'Material Usage'],
  ['material_requirements', 'Material Requirements'],
  ['shortage_alerts', 'Material Shortage Alerts'],
  ['finished_goods', 'Finished Goods Received'],
  ['wip', 'Work In Progress'],
  ['wastage', 'Wastage & Rejects'],
  ['variance', 'Production Variance'],
  ['accounting_ledger', 'Accounting Ledger'],
  ['plan_history', 'Production Plan History'],
  ['plan_comparison', 'Production Plan Comparison'],
];

const HISTORY_SECTIONS = [
  ['orders', 'Orders'],
  ['material_usage', 'Material Usage'],
  ['expenses', 'Expenses / Labour'],
  ['finished_goods', 'Finished Goods'],
  ['stock_movements', 'Stock Movements'],
  ['accounting_ledger', 'Accounting Ledger'],
];

const REPORT_NAV_ITEMS = REPORTS.map(([key, label]) => ({ key, label }));
const REPORT_VIEW_ITEMS = [
  { key: 'table', label: 'Report Table', description: 'Detailed rows and values' },
  { key: 'visuals', label: 'Enterprise Visuals', description: 'Charts and comparisons' },
  { key: 'summary', label: 'Executive Summary', description: 'Management-level findings' },
];
const HISTORY_NAV_ITEMS = HISTORY_SECTIONS.map(([key, label]) => ({ key, label }));

const DEFAULT_CURRENCY = 'USD';
const VISUAL_COLORS = {
  material: '#004343',
  labour: '#49A942',
  overhead: '#F79009',
  good: '#12B76A',
  reject: '#F04438',
  waste: '#F79009',
  planned: '#324443',
  totalCost: '#057B7B',
  grid: '#CFD6D6',
};

const asArray = (value) => (Array.isArray(value) ? value : value ? [value] : []);
const getPlanLabel = (plan) => `${plan.plan_number || `PLAN-${plan.id}`} - ${plan.name || 'Unnamed Plan'}`;
const toText = (value, fallback = '-') => {
  if (value === undefined || value === null || value === '') return fallback;
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value)) return value.map((item) => toText(item, '')).filter(Boolean).join(', ') || fallback;
  if (typeof value === 'object') {
    return (
      value.name ||
      value.product_name ||
      value.finished_product_name ||
      value.material ||
      value.code ||
      value.sku ||
      value.plan_number ||
      value.production_number ||
      value.description ||
      fallback
    );
  }
  return String(value);
};

const valueAt = (row, keys, fallback = '') => {
  for (const key of keys) {
    const parts = String(key).split('.');
    let current = row;
    for (const part of parts) current = current?.[part];
    if (current !== undefined && current !== null && current !== '') return current;
  }
  return fallback;
};

const normalizeReportPayload = (response) => {
  const data = response?.data?.data || response?.data || response || {};
  if (Array.isArray(data)) return { summary: {}, rows: data, raw: data };
  if (Array.isArray(data?.data)) return { summary: data.summary || {}, rows: data.data, raw: data };
  if (Array.isArray(data?.rows)) return { summary: data.summary || {}, rows: data.rows, raw: data };
  if (Array.isArray(data?.recent_production)) return { summary: data, rows: data.recent_production, raw: data };
  if (Array.isArray(data?.orders)) return { summary: data.summary || data, rows: data.orders, raw: data };
  return { summary: data.summary || data || {}, rows: [], raw: data };
};

const fmtMoney = (value, currency = DEFAULT_CURRENCY) => `${currency || DEFAULT_CURRENCY} ${moneyNumber(value)}`;
const fmtQty = (value) => qtyNumber(value);
const fmtPct = (value) => `${numberValue(value).toFixed(2)}%`;

function col(path, label, format = (v) => toText(v), right = false) {
  return { path, label, format, right };
}

function getCell(row, column) {
  const value = valueAt(row, String(column.path).split('|'), '-');
  if (value === '-' || value === null || value === undefined || value === '') return '-';
  return toText(column.format(value, row.currency || row.metadata?.currency || DEFAULT_CURRENCY));
}

const historyColumns = (section) => {
  const map = {
    orders: [
      col('production_number', 'Production No'),
      col('finished_product|finished_product.name|product.name|product_name', 'Product'),
      col('status', 'Status'),
      col('planned_quantity|planned_output_quantity', 'Planned', fmtQty, true),
      col('actual_good_quantity|completed_quantity', 'Good Qty', fmtQty, true),
      col('actual_reject_quantity|reject_quantity', 'Reject', fmtQty, true),
      col('actual_waste_quantity|waste_quantity', 'Waste', fmtQty, true),
      col('actual_total_cost|total_cost', 'Actual Cost', fmtMoney, true),
      col('actual_unit_cost|unit_cost', 'Unit Cost', fmtMoney, true),
      col('created_at', 'Created', safeDate),
      col('completed_at', 'Completed', safeDate),
    ],
    material_usage: columnsFor('material_usage'),
    expenses: [
      col('production_number', 'Production No'),
      col('cost_type', 'Cost Type', titleCase),
      col('cost_class|accounting_class', 'Class', titleCase),
      col('staff_name|metadata.staff_name', 'Staff'),
      col('description', 'Description'),
      col('quantity', 'Qty', fmtQty, true),
      col('rate', 'Rate', fmtMoney, true),
      col('amount', 'Amount', fmtMoney, true),
      col('currency', 'Currency'),
      col('created_at', 'Date', safeDate),
    ],
    finished_goods: columnsFor('finished_goods'),
    stock_movements: [
      col('created_at', 'Date', safeDate),
      col('production_order_id', 'Order ID'),
      col('product_name|product.name', 'Product'),
      col('product_code|product.code', 'Code'),
      col('movement_type', 'Movement', titleCase),
      col('quantity_in', 'Qty In', fmtQty, true),
      col('quantity_out', 'Qty Out', fmtQty, true),
      col('unit_cost', 'Unit Cost', fmtMoney, true),
      col('total_cost', 'Total Cost', fmtMoney, true),
      col('notes', 'Notes'),
    ],
    accounting_ledger: columnsFor('accounting_ledger'),
  };
  return map[section] || map.orders;
};

function columnsFor(reportType, historySection = 'orders') {
  if (reportType === 'plan_history') return historyColumns(historySection);
  if (reportType === 'plan_comparison') {
    return [
      col('plan|plan_label|plan_number', 'Plan'),
      col('status', 'Status'),
      col('orders|order_count', 'Orders', fmtQty, true),
      col('planned_quantity|planned_output_quantity', 'Planned', fmtQty, true),
      col('actual_good_quantity|good_quantity', 'Good Output', fmtQty, true),
      col('actual_reject_quantity|reject_quantity', 'Reject', fmtQty, true),
      col('actual_waste_quantity|waste_quantity', 'Waste', fmtQty, true),
      col('yield_percent', 'Yield %', fmtPct, true),
      col('direct_material_cost|material_cost', 'Material Cost', fmtMoney, true),
      col('direct_labour_cost|labour_cost', 'Labour Cost', fmtMoney, true),
      col('indirect_overhead_cost|overhead_cost', 'Overhead', fmtMoney, true),
      col('actual_total_cost|total_cost', 'Total Cost', fmtMoney, true),
      col('actual_unit_cost|unit_cost', 'Unit Cost', fmtMoney, true),
    ];
  }

  const map = {
    production_summary: [
      col('production_order_code|production_number', 'Production No'),
      col('product_name|finished_product|finished_product.name|product.name', 'Product'),
      col('status', 'Status'),
      col('planned_quantity|planned_output_quantity', 'Planned', fmtQty, true),
      col('completed_quantity|actual_good_quantity', 'Good Qty', fmtQty, true),
      col('reject_quantity|actual_reject_quantity', 'Reject', fmtQty, true),
      col('waste_quantity|actual_waste_quantity', 'Waste', fmtQty, true),
      col('planned_total_cost', 'Planned Cost', fmtMoney, true),
      col('actual_total_cost', 'Actual Cost', fmtMoney, true),
      col('actual_unit_cost', 'Unit Cost', fmtMoney, true),
      col('created_at', 'Created', safeDate),
      col('completed_at', 'Completed', safeDate),
    ],
    production_costs: [
      col('production_number', 'Production No'),
      col('finished_product|finished_product.name|product_name|product.name', 'Finished Product'),
      col('status', 'Status'),
      col('planned_quantity|planned_output_quantity', 'Planned', fmtQty, true),
      col('actual_good_quantity', 'Good Qty', fmtQty, true),
      col('bom_standard_cost', 'BOM Cost', fmtMoney, true),
      col('planned_total_cost', 'Planned Cost', fmtMoney, true),
      col('direct_material_cost|actual_raw_material_cost', 'Direct Material', fmtMoney, true),
      col('direct_labour_cost|actual_labour_cost', 'Direct Labour', fmtMoney, true),
      col('indirect_overhead_cost|actual_overhead_cost', 'Overhead', fmtMoney, true),
      col('actual_total_cost', 'Actual Total', fmtMoney, true),
      col('actual_unit_cost', 'Unit Cost', fmtMoney, true),
      col('cost_variance_amount', 'Variance', fmtMoney, true),
    ],
    material_usage: [
      col('production_number', 'Production No'),
      col('material|product.name|product_name', 'Material'),
      col('material_code|product.code|product_code', 'Code'),
      col('planned_quantity', 'Planned', fmtQty, true),
      col('reserved_quantity', 'Reserved', fmtQty, true),
      col('issued_quantity', 'Issued', fmtQty, true),
      col('returned_quantity', 'Returned', fmtQty, true),
      col('wasted_quantity', 'Wasted', fmtQty, true),
      col('net_consumed_quantity', 'Net Consumed', fmtQty, true),
      col('unit_cost', 'Unit Cost', fmtMoney, true),
      col('total_cost', 'Total Cost', fmtMoney, true),
      col('order_status|status', 'Status'),
    ],
    material_requirements: [
      col('production_plan_id|plan_number', 'Plan'),
      col('product.name|metadata.recipe_product_name|metadata.product_name|product|product_name', 'Material'),
      col('product.code|product_code|metadata.product_code', 'Code'),
      col('product.product_type|product_type|metadata.product_type', 'Type', titleCase),
      col('required_quantity', 'Required', fmtQty, true),
      col('available_quantity', 'Available', fmtQty, true),
      col('reserved_quantity', 'Reserved', fmtQty, true),
      col('shortage_quantity', 'Shortage', fmtQty, true),
      col('status', 'Status'),
      col('metadata.calculation_basis', 'Calculation Basis'),
    ],
    shortage_alerts: [
      col('production_plan_id|plan_number', 'Plan'),
      col('product|product.name|product_name', 'Material'),
      col('product_code|product.code', 'Code'),
      col('required_quantity', 'Required', fmtQty, true),
      col('available_quantity', 'Available', fmtQty, true),
      col('shortage_quantity', 'Shortage', fmtQty, true),
      col('status', 'Status'),
    ],
    finished_goods: [
      col('production_number', 'Production No'),
      col('finished_product|finished_product.name|product_name|product.name', 'Finished Product'),
      col('quantity_received|actual_good_quantity|quantity_produced', 'Received', fmtQty, true),
      col('quantity_rejected|actual_reject_quantity', 'Rejected', fmtQty, true),
      col('quantity_wasted|actual_waste_quantity', 'Wasted', fmtQty, true),
      col('unit_cost|actual_unit_cost', 'Unit Cost', fmtMoney, true),
      col('total_cost|actual_total_cost', 'Total Cost', fmtMoney, true),
      col('created_at|completed_at', 'Date', safeDate),
    ],
    wip: [
      col('production_number', 'Production No'),
      col('finished_product|finished_product.name|product_name|product.name', 'Product'),
      col('status', 'Status'),
      col('planned_quantity|planned_output_quantity', 'Planned', fmtQty, true),
      col('actual_good_quantity', 'Good Qty', fmtQty, true),
      col('direct_material_cost|actual_raw_material_cost', 'Material Cost', fmtMoney, true),
      col('direct_labour_cost|actual_labour_cost', 'Labour Cost', fmtMoney, true),
      col('indirect_overhead_cost|actual_overhead_cost', 'Overhead', fmtMoney, true),
      col('actual_total_cost', 'WIP Cost', fmtMoney, true),
    ],
    wastage: [
      col('production_number', 'Production No'),
      col('finished_product|finished_product.name|product_name|material|product.name', 'Item'),
      col('status|order_status', 'Status'),
      col('planned_quantity|planned_output_quantity', 'Planned', fmtQty, true),
      col('actual_good_quantity|completed_quantity', 'Good Qty', fmtQty, true),
      col('actual_reject_quantity|reject_quantity', 'Reject Qty', fmtQty, true),
      col('actual_waste_quantity|waste_quantity|wasted_quantity', 'Waste Qty', fmtQty, true),
      col('loss_percent', 'Loss %', fmtPct, true),
      col('total_cost|actual_total_cost', 'Cost Impact', fmtMoney, true),
    ],
    variance: [
      col('production_number', 'Production No'),
      col('finished_product|finished_product.name|product_name|product.name', 'Product'),
      col('status', 'Status'),
      col('planned_quantity|planned_output_quantity', 'Planned', fmtQty, true),
      col('actual_good_quantity', 'Actual Good', fmtQty, true),
      col('planned_total_cost', 'Planned Cost', fmtMoney, true),
      col('actual_total_cost', 'Actual Cost', fmtMoney, true),
      col('cost_variance_amount', 'Cost Variance', fmtMoney, true),
      col('quantity_variance|yield_variance_quantity', 'Qty Variance', fmtQty, true),
    ],
    accounting_ledger: [
      col('date|created_at', 'Date', safeDate),
      col('production_number', 'Production No'),
      col('account|account_name', 'Account'),
      col('description', 'Description'),
      col('source|entry_type|type', 'Source', titleCase),
      col('debit', 'Debit', fmtMoney, true),
      col('credit', 'Credit', fmtMoney, true),
      col('currency', 'Currency'),
    ],
  };
  return map[reportType] || [col('message', 'Message')];
}

export default function ManufacturingReportsModal({ open, onClose, currentUser = null, businessModeLabel = 'Hospitality' }) {
  const branchId = getBranchId(currentUser);
  const { notice, showNotice, closeNotice } = useNotice();
  const plansDropdownRef = useRef(null);

  const [reportType, setReportType] = useState('production_summary');
  const [status, setStatus] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [search, setSearch] = useState('');
  const [plans, setPlans] = useState([]);
  const [selectedPlanIds, setSelectedPlanIds] = useState([]);
  const [plansOpen, setPlansOpen] = useState(false);
  const [planSearch, setPlanSearch] = useState('');
  const [historySection, setHistorySection] = useState('orders');
  const [summary, setSummary] = useState({});
  const [rows, setRows] = useState([]);
  const [rawData, setRawData] = useState({});
  const [loading, setLoading] = useState(false);
  const [activeView, setActiveView] = useState('table');
  const [generated, setGenerated] = useState(false);
  const [outputChooserOpen, setOutputChooserOpen] = useState(false);
  const [chooserSource, setChooserSource] = useState('configuration');
  const [outputSnapshot, setOutputSnapshot] = useState(null);
  const [appliedSnapshot, setAppliedSnapshot] = useState(null);
  const [exporting, setExporting] = useState(false);
  const scopeFilters = useMemo(() => ({
    branch_id: branchId || '',
    date_from: dateFrom,
    date_to: dateTo,
  }), [branchId, dateFrom, dateTo]);
  const scopeLookups = useHospitalityReportLookups(open, scopeFilters);

  const selectedPlans = useMemo(
    () => plans.filter((plan) => selectedPlanIds.includes(String(plan.id))),
    [plans, selectedPlanIds]
  );

  const filteredPlans = useMemo(() => {
    const q = planSearch.trim().toLowerCase();
    return plans.filter((plan) => {
      const inSearch = !q || getPlanLabel(plan).toLowerCase().includes(q) || String(plan.status || '').toLowerCase().includes(q);
      return inSearch;
    });
  }, [plans, planSearch]);

  const displayedReportType = generated && appliedSnapshot?.reportType
    ? appliedSnapshot.reportType
    : reportType;
  const displayedHistorySection = generated && appliedSnapshot?.historySection
    ? appliedSnapshot.historySection
    : historySection;
  const displayedSearch = generated && appliedSnapshot
    ? appliedSnapshot.search
    : search.trim();
  const displayedSelectedPlans = generated && appliedSnapshot
    ? appliedSnapshot.selectedPlans
    : selectedPlans;

  const visibleRows = useMemo(() => {
    if (displayedReportType === 'plan_history') return asArray(rawData?.[displayedHistorySection]);
    return rows;
  }, [displayedReportType, displayedHistorySection, rawData, rows]);

  const filteredRows = useMemo(() => {
    const q = displayedSearch.toLowerCase();
    if (!q) return visibleRows;
    return visibleRows.filter((row) => JSON.stringify(row).toLowerCase().includes(q));
  }, [visibleRows, displayedSearch]);

  const tableColumns = useMemo(
    () => columnsFor(displayedReportType, displayedHistorySection),
    [displayedReportType, displayedHistorySection]
  );
  const dashboard = useMemo(() => buildDashboard(summary, filteredRows, rawData), [summary, filteredRows, rawData]);
  const comparisonRows = displayedReportType === 'plan_comparison' ? filteredRows : buildComparisonRowsFromHistory(rawData);

  useEffect(() => {
    if (!open) return undefined;
    loadPlans();
    setSummary({});
    setRows([]);
    setRawData({});
    setGenerated(false);
    setOutputChooserOpen(false);
    setOutputSnapshot(null);
    setAppliedSnapshot(null);
    return undefined;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;
    const timer = window.setTimeout(() => loadPlans(), 250);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateFrom, dateTo, status]);

  useEffect(() => {
    const handler = (event) => {
      if (!plansDropdownRef.current?.contains(event.target)) setPlansOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const loadPlans = async () => {
    try {
      const res = await getProductionPlans({
        branch_id: branchId || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        status: status || undefined,
        per_page: 1000,
      });
      setPlans(normalizeRows(res));
    } catch (_) {
      setPlans([]);
    }
  };

  const captureReportSnapshot = () => {
    const capturedPlanIds = selectedPlanIds.length
      ? [...selectedPlanIds]
      : plans.slice(0, 100).map((plan) => String(plan.id));
    const capturedSelectedPlans = selectedPlanIds.length
      ? selectedPlans.map((plan) => ({ ...plan }))
      : [];

    return {
      reportType,
      historySection,
      search: search.trim(),
      planIds: capturedPlanIds,
      selectedPlanIds: [...selectedPlanIds],
      selectedPlans: capturedSelectedPlans,
      params: {
        branch_id: branchId || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        status: status || undefined,
        production_plan_id: selectedPlanIds.length === 1 ? selectedPlanIds[0] : undefined,
        production_plan_ids: selectedPlanIds.length ? [...selectedPlanIds] : undefined,
        per_page: 1000,
      },
    };
  };

  const normalizeManyHistories = (response) => {
    const data = response?.data?.data || response?.data || response || {};
    const reports = data.reports || [];
    return consolidateHistories(reports);
  };

  const loadHistoryForSelectedPlans = async (params, planIds = []) => {
    const ids = [...planIds];
    if (!ids.length) {
      return { data: { summary: {}, orders: [], material_usage: [], expenses: [], finished_goods: [], stock_movements: [], accounting_ledger: [] } };
    }
    if (ids.length === 1) return getProductionPlanHistoryReport(ids[0], params);
    const res = await getManyProductionPlanHistoryReports(ids, params);
    return normalizeManyHistories(res);
  };

  const loadReport = async (snapshot) => {
    const captured = snapshot || captureReportSnapshot();
    const capturedReportType = captured.reportType;
    try {
      setLoading(true);
      setSummary({});
      setRows([]);
      setRawData({});
      const params = { ...captured.params };
      let res;

      if (capturedReportType === 'production_summary') res = await getProductionSummaryReport(params);
      else if (capturedReportType === 'production_costs') res = await getProductionCostReport(params);
      else if (capturedReportType === 'material_usage') res = await getMaterialUsageReport(params);
      else if (capturedReportType === 'material_requirements') res = await getMaterialRequirementsReport(params);
      else if (capturedReportType === 'shortage_alerts') res = await getShortageAlertsReport(params);
      else if (capturedReportType === 'finished_goods') res = await getFinishedGoodsReceivedReport(params);
      else if (capturedReportType === 'wip') res = await getWipReport(params);
      else if (capturedReportType === 'wastage') res = await getWastageReport(params);
      else if (capturedReportType === 'variance') res = await getProductionVarianceReport(params);
      else if (capturedReportType === 'accounting_ledger') {
        try {
          res = await getAccountingLedgerReport(params);
        } catch (_) {
          const historyRes = await loadHistoryForSelectedPlans(params, captured.planIds);
          const history = normalizeReportPayload(historyRes).raw || {};
          res = { data: { summary: history.summary || {}, rows: history.accounting_ledger || [] } };
        }
      } else if (capturedReportType === 'plan_comparison') {
        try {
          res = await getProductionPlanComparisonReport(params);
        } catch (_) {
          const historyRes = await loadHistoryForSelectedPlans(params, captured.planIds);
          const history = normalizeReportPayload(historyRes).raw || {};
          res = { data: { summary: history.summary || {}, rows: buildComparisonRowsFromHistory(history) } };
        }
      } else if (capturedReportType === 'plan_history') {
        res = await loadHistoryForSelectedPlans(params, captured.planIds);
      } else res = await getManufacturingDashboardReport(params);

      const payload = normalizeReportPayload(res);
      const data = payload.raw || {};

      if (capturedReportType === 'plan_history') {
        setRawData(data);
        setSummary(data.summary || data.consolidated_summary || {});
        setRows([]);
      } else if (capturedReportType === 'plan_comparison') {
        setRawData(data);
        setSummary(data.summary || {});
        setRows(payload.rows.length ? payload.rows : buildComparisonRowsFromHistory(data));
      } else {
        setRawData(data);
        setSummary(payload.summary || {});
        setRows(payload.rows || []);
      }
      return true;
    } catch (error) {
      setSummary({});
      setRows([]);
      setRawData({});
      showNotice(getErrorMessage(error, 'Failed to load manufacturing report.'), 'error', 'Report Failed');
      return false;
    } finally {
      setLoading(false);
    }
  };

  const handleReportChange = (value) => {
    setReportType(value);
    setGenerated(false);
    setOutputChooserOpen(false);
    setOutputSnapshot(null);
    setAppliedSnapshot(null);
    setSummary({});
    setRows([]);
    setRawData({});
    setActiveView('table');
    if (value === 'plan_history') setHistorySection('orders');
  };

  const handleHistorySectionChange = (value) => {
    setHistorySection(value);
    if (generated) {
      setAppliedSnapshot((current) => current ? { ...current, historySection: value } : current);
    }
  };

  const togglePlan = (planId) => {
    const id = String(planId);
    setGenerated(false);
    setSelectedPlanIds((prev) => (prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]));
  };

  const clearPlans = () => { setGenerated(false); setSelectedPlanIds([]); };
  const selectVisiblePlans = () => { setGenerated(false); setSelectedPlanIds(filteredPlans.map((plan) => String(plan.id))); };

  const clearFilters = () => {
    setGenerated(false);
    setOutputSnapshot(null);
    setAppliedSnapshot(null);
    setStatus('');
    setDateFrom('');
    setDateTo('');
    setSearch('');
    setPlanSearch('');
    setSelectedPlanIds([]);
  };

  const setScopeFilters = (next) => {
    setGenerated(false);
    setDateFrom(next.date_from || '');
    setDateTo(next.date_to || '');
  };

  const openOutputChooser = (source = 'configuration') => {
    const snapshot = source === 'result' && appliedSnapshot
      ? {
          ...appliedSnapshot,
          planIds: [...appliedSnapshot.planIds],
          selectedPlanIds: [...appliedSnapshot.selectedPlanIds],
          selectedPlans: appliedSnapshot.selectedPlans.map((plan) => ({ ...plan })),
          params: { ...appliedSnapshot.params },
        }
      : captureReportSnapshot();
    setChooserSource(source);
    setOutputSnapshot(snapshot);
    setOutputChooserOpen(true);
  };

  const viewSelectedOutput = async () => {
    const snapshot = outputSnapshot || captureReportSnapshot();
    setOutputChooserOpen(false);
    setGenerated(false);
    const loaded = await loadReport(snapshot);
    if (loaded) {
      setAppliedSnapshot(snapshot);
      setGenerated(true);
    }
  };

  const refreshReport = async () => {
    if (!appliedSnapshot) return;
    await loadReport(appliedSnapshot);
  };

  const downloadReport = async (format) => {
    const snapshot = outputSnapshot || appliedSnapshot || captureReportSnapshot();
    try {
      setExporting(true);
      await downloadManufacturingReport(snapshot.reportType, format, {
        ...snapshot.params,
        q: snapshot.search || undefined,
        history_section: snapshot.historySection,
      });
      setOutputChooserOpen(false);
    } catch (error) {
      setOutputChooserOpen(false);
      showNotice(getErrorMessage(error, `Failed to download the manufacturing ${format === 'pdf' ? 'PDF' : 'workbook'}.`), 'error', 'Download Failed');
    } finally {
      setExporting(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="MANUFACTURING ANALYTICS"
      subtitle="Costing, material movement, WIP, finished goods, plan history and comparisons"
    >
      <Notice notice={notice} onClose={closeNotice} />
      <LoadingPill loading={loading} />

      <div className="manufacturing-reports-workspace h-full min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 border border-[#AAB5B5] bg-white">
          <ReportViewNavigator
            groups={[
              { title: 'Manufacturing reports', items: REPORT_NAV_ITEMS, activeKey: reportType, onChange: handleReportChange },
              { title: 'Report view', items: REPORT_VIEW_ITEMS, activeKey: activeView, onChange: setActiveView },
              ...(reportType === 'plan_history'
                ? [{ title: 'History section', items: HISTORY_NAV_ITEMS, activeKey: historySection, onChange: handleHistorySectionChange }]
                : []),
            ]}
          />
          <div className="flex min-h-0 min-w-0 flex-1 flex-col">
          {!generated ? <>
          <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
            <HospitalityReportScopePanel
              filters={scopeFilters}
              setFilters={setScopeFilters}
              lookups={scopeLookups}
              onApply={() => openOutputChooser('configuration')}
              loading={loading}
              businessModeLabel={businessModeLabel}
              supported={{
                branch_id: false,
                date_from: true,
                date_to: true,
                till_id: false,
                cashier_id: false,
                shift_session_id: false,
                currency_codes: false,
              }}
              actions={(
                <button type="button" onClick={clearFilters} className="h-8 border border-[#AAB5B5] bg-white px-3 text-[11px] font-semibold text-[#324443]">Clear</button>
              )}
            />

            <div className="grid grid-cols-[155px_minmax(300px,1fr)_240px] gap-2 px-2 py-2">
              <FieldLabel label="Status">
                <select value={status} onChange={(e) => { setGenerated(false); setStatus(e.target.value); }} className="report-input">
                  <option value="">All Status</option>
                  <option value="draft">Draft</option>
                  <option value="approved">Approved</option>
                  <option value="materials_reserved">Materials Reserved</option>
                  <option value="materials_issued">Materials Issued</option>
                  <option value="in_progress">In Progress</option>
                  <option value="completed">Completed</option>
                  <option value="converted_to_orders">Converted</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </FieldLabel>

              <FieldLabel label="Production Plans">
                <div ref={plansDropdownRef} className="relative">
                  <button
                    type="button"
                    onClick={() => setPlansOpen((prev) => !prev)}
                    className="flex h-8 w-full items-center justify-between border border-[#AAB5B5] bg-white px-2 text-left text-[11px] outline-none hover:bg-[#F8FAFC]"
                  >
                    <span className="truncate">
                      {selectedPlanIds.length === 0
                        ? 'All plans in date range'
                        : selectedPlanIds.length === 1
                          ? getPlanLabel(selectedPlans[0] || {})
                          : `${selectedPlanIds.length} production plans selected`}
                    </span>
                    <ChevronDown className="h-3.5 w-3.5 text-[#627474]" />
                  </button>

                  {plansOpen && (
                    <div className="absolute left-0 top-[34px] z-[300000] w-full min-w-[430px] border border-[#627474] bg-white shadow-2xl">
                      <div className="border-b border-[#CFD9D8] bg-[#F5F7FA] p-2">
                        <div className="relative">
                          <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                          <input
                            value={planSearch}
                            onChange={(e) => setPlanSearch(e.target.value)}
                            placeholder="Find plan code or name..."
                            className="h-8 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none"
                          />
                        </div>
                        <div className="mt-2 flex gap-1">
                          <button type="button" onClick={selectVisiblePlans} className="h-7 border border-[#027A48] bg-[#ECFDF3] px-2 text-[10px] font-semibold text-[#027A48]">Select visible</button>
                          <button type="button" onClick={clearPlans} className="h-7 border border-[#AAB5B5] bg-white px-2 text-[10px] font-semibold text-[#324443]">Clear plans</button>
                        </div>
                      </div>
                      <div className="max-h-[280px] overflow-auto">
                        {filteredPlans.length === 0 ? (
                          <div className="px-3 py-8 text-center text-[12px] text-[#627474]">No production plans found.</div>
                        ) : filteredPlans.map((plan) => {
                          const checked = selectedPlanIds.includes(String(plan.id));
                          return (
                            <button
                              key={plan.id}
                              type="button"
                              onClick={() => togglePlan(plan.id)}
                              className={`flex w-full items-start gap-2 border-b border-[#E9EDED] px-3 py-2 text-left text-[11px] hover:bg-[#F8FAFC] ${checked ? 'bg-[#ECFDF3]' : 'bg-white'}`}
                            >
                              {checked ? <CheckSquare className="mt-0.5 h-4 w-4 text-[#027A48]" /> : <Square className="mt-0.5 h-4 w-4 text-[#627474]" />}
                              <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold text-[#0C1B1B]">{getPlanLabel(plan)}</span>
                                <span className="block truncate text-[10px] uppercase text-[#627474]">
                                  {statusLabel(plan.status)} • {safeDate(plan.period_start)} to {safeDate(plan.period_end)}
                                </span>
                              </span>
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  )}
                </div>
              </FieldLabel>

              <FieldLabel label="Search">
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search table..."
                    className="h-8 w-full border border-[#AAB5B5] bg-white pl-8 pr-8 text-[11px] outline-none focus:border-[#004343]"
                  />
                  {search && <button type="button" onClick={() => setSearch('')} className="absolute right-1 top-1 h-6 w-6 text-[#627474]"><X className="mx-auto h-3.5 w-3.5" /></button>}
                </div>
              </FieldLabel>
            </div>
          </div>

          <ReportGenerationPrompt
            title="Configure Manufacturing Report"
            message="Choose the report, period, status and production plans, then select Generate."
          />
          </> : (
            <ReportViewShell
              title={REPORTS.find(([key]) => key === displayedReportType)?.[1] || 'Manufacturing Report'}
              subtitle="Manufacturing report results"
              backLabel="Configuration / Back"
              onBackToFilters={() => setGenerated(false)}
              busy={loading}
              actions={<>
                <button type="button" onClick={refreshReport} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#AAB5B5] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-60">
                  <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} /> Refresh
                </button>
                <button type="button" onClick={() => openOutputChooser('result')} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#069495] bg-[#E8F7F5] px-3 text-[11px] font-semibold text-[#006869] disabled:opacity-60">
                  <Download className="h-3.5 w-3.5" /> Download
                </button>
              </>}
            >
              <div className="flex h-full min-h-0 flex-col">
                <div className="grid min-h-[118px] shrink-0 grid-cols-5 gap-2 border-b border-[#AAB5B5] bg-[#F7F8FA] p-2">
                  <KpiCard icon={BookOpen} label="Orders / Rows" value={dashboard.orders} />
                  <KpiCard icon={TrendingUp} label="Good Output" value={fmtQty(dashboard.goodQty)} />
                  <KpiCard icon={TrendingDown} label="Reject / Waste" value={`${fmtQty(dashboard.rejectQty)} / ${fmtQty(dashboard.wasteQty)}`} danger={dashboard.rejectQty > 0 || dashboard.wasteQty > 0} />
                  <KpiCard icon={Layers} label="Actual Cost" value={fmtMoney(dashboard.actualCost, dashboard.currency)} />
                  <KpiCard icon={BarChart3} label="Avg Unit Cost" value={fmtMoney(dashboard.avgUnitCost, dashboard.currency)} />
                </div>

                <div className="min-h-0 flex-1 overflow-hidden">
                  {activeView === 'table' && <ReportTable columns={tableColumns} rows={filteredRows} />}
                  {activeView === 'visuals' && <EnterpriseVisuals dashboard={dashboard} rows={filteredRows} comparisonRows={comparisonRows} reportType={displayedReportType} />}
                  {activeView === 'summary' && <ExecutiveSummary dashboard={dashboard} selectedPlans={displayedSelectedPlans} rawData={rawData} reportType={displayedReportType} />}
                </div>
              </div>
            </ReportViewShell>
          )}
          </div>
        </div>
      </div>

      <ReportOutputChooser
        open={outputChooserOpen}
        reportTitle={REPORTS.find(([key]) => key === (outputSnapshot?.reportType || reportType))?.[1] || 'Manufacturing Report'}
        busy={exporting}
        busyLabel="Preparing manufacturing report download…"
        onClose={() => setOutputChooserOpen(false)}
        onView={viewSelectedOutput}
        onDownloadPdf={() => downloadReport('pdf')}
        onDownloadExcel={() => downloadReport('xlsx')}
        outputs={chooserSource === 'result' ? { view: { disabled: true, reason: 'This report is already open.' } } : undefined}
      />

      <style>{`
        .manufacturing-reports-workspace .report-input { height: 32px; width: 100%; border: 1px solid #AAB5B5; background: white; padding: 0 8px; font-size: 11px; outline: none; border-radius: 0; }
        .manufacturing-reports-workspace .report-input:focus { border-color: #004343; }
        .manufacturing-reports-workspace button,
        .manufacturing-reports-workspace input,
        .manufacturing-reports-workspace select,
        .manufacturing-reports-workspace textarea { border-radius: 0 !important; }
      `}</style>
    </FullScreenModal>
  );
}

function FieldLabel({ label, children }) {
  return <label className="block min-w-0"><div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>{children}</label>;
}

function KpiCard({ icon: Icon, label, value, danger = false }) {
  return (
    <div className={`border ${danger ? 'border-[#FDA29B] bg-[#FFFBFA]' : 'border-[#B9C3C3] bg-white'} p-3`}>
      <div className="flex items-center gap-1 text-[10px] font-semibold uppercase text-[#627474]"><Icon className="h-3.5 w-3.5" /> {label}</div>
      <div className={`mt-3 truncate text-[24px] font-semibold ${danger ? 'text-[#B42318]' : 'text-[#004343]'}`}>{value ?? '-'}</div>
    </div>
  );
}

function ReportTable({ columns, rows }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1300px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>{columns.map((column) => <th key={column.label} className={`border-r border-[#465757] px-2 py-2 font-semibold ${column.right ? 'text-right' : 'text-left'}`}>{column.label}</th>)}</tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr><td colSpan={columns.length} className="px-3 py-16 text-center text-[#627474]">No report rows found.</td></tr>
          ) : rows.map((row, index) => (
            <tr key={row.id || row.production_order_id || row.production_number || index} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
              {columns.map((column) => <td key={column.label} className={`max-w-[320px] border-r border-[#E9EDED] px-2 py-2 align-top ${column.right ? 'text-right font-semibold text-[#004343]' : ''}`}>{getCell(row, column)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function EnterpriseVisuals({ dashboard, rows, comparisonRows, reportType }) {
  const visualRows = getVisualRows(rows, comparisonRows, reportType).slice(0, 14);
  const trendRows = getTrendRows(visualRows);
  const maxCost = Math.max(...visualRows.map((row) => row.material + row.labour + row.overhead), 1);
  const maxQty = Math.max(...visualRows.map((row) => row.good + row.reject + row.waste), 1);

  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-2">
        <Panel
          title="Cost structure"
          subtitle="Direct material, direct labour and indirect overhead"
          actions={<Legend items={costLegendItems(dashboard.currency)} />}
        >
          {visualRows.length === 0 ? <EmptyVisual /> : visualRows.map((row, index) => {
            const total = row.material + row.labour + row.overhead;
            return (
              <div key={`cost-${row.label}-${index}`} className="mb-3">
                <div className="mb-1 flex justify-between gap-2 text-[11px] font-semibold text-[#324443]">
                  <span className="truncate">{toText(row.label)}</span>
                  <span>{fmtMoney(total, dashboard.currency)}</span>
                </div>
                <StackBar
                  parts={[row.material, row.labour, row.overhead]}
                  max={maxCost}
                  colors={[VISUAL_COLORS.material, VISUAL_COLORS.labour, VISUAL_COLORS.overhead]}
                />
                <div className="mt-1 grid grid-cols-3 gap-1 text-[10px] text-[#627474]">
                  <span>Material {fmtMoney(row.material, dashboard.currency)}</span>
                  <span>Labour {fmtMoney(row.labour, dashboard.currency)}</span>
                  <span>Overhead {fmtMoney(row.overhead, dashboard.currency)}</span>
                </div>
              </div>
            );
          })}
        </Panel>

        <Panel
          title="Output quality"
          subtitle="Good output, rejects and wastage"
          actions={<Legend items={qualityLegendItems()} />}
        >
          {visualRows.length === 0 ? <EmptyVisual /> : visualRows.map((row, index) => (
            <div key={`quality-${row.label}-${index}`} className="mb-3">
              <div className="mb-1 flex justify-between gap-2 text-[11px] font-semibold text-[#324443]">
                <span className="truncate">{toText(row.label)}</span>
                <span>{fmtQty(row.good)} good</span>
              </div>
              <QualityBar good={row.good} reject={row.reject} waste={row.waste} max={maxQty} />
              <div className="mt-1 grid grid-cols-3 gap-1 text-[10px] text-[#627474]">
                <span>Good {fmtQty(row.good)}</span>
                <span>Reject {fmtQty(row.reject)}</span>
                <span>Waste {fmtQty(row.waste)}</span>
              </div>
            </div>
          ))}
        </Panel>
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-[1.35fr_0.65fr]">
        <Panel
          title="Production trend line graph"
          subtitle="Planned, good, reject and waste movement"
          actions={<Legend items={lineLegendItems()} />}
        >
          {trendRows.length === 0 ? <EmptyVisual /> : <LineGraph rows={trendRows} />}
        </Panel>

        <Panel
          title="Comparison keys"
          subtitle="Legend used in screen, PDF and Excel"
          actions={null}
        >
          <div className="grid gap-2 text-[11px]">
            <LegendBlock title="Cost graph key" items={costLegendItems(dashboard.currency)} />
            <LegendBlock title="Output graph key" items={qualityLegendItems()} />
            <LegendBlock title="Line graph key" items={lineLegendItems()} />
          </div>
        </Panel>
      </div>
    </div>
  );
}

function getVisualRows(rows = [], comparisonRows = [], reportType = '') {
  const source = reportType === 'plan_comparison' || comparisonRows.length ? comparisonRows : rows;
  return source.map((row, index) => {
    const planned = numberValue(valueAt(row, ['planned_quantity', 'planned_output_quantity', 'planned'], 0));
    const good = numberValue(valueAt(row, ['actual_good_quantity', 'good_quantity', 'completed_quantity', 'finished_product_quantity_actual', 'quantity_received', 'quantity_produced'], 0));
    const reject = numberValue(valueAt(row, ['actual_reject_quantity', 'reject_quantity', 'quantity_rejected'], 0));
    const waste = numberValue(valueAt(row, ['actual_waste_quantity', 'waste_quantity', 'wasted_quantity', 'quantity_wasted'], 0));
    const material = numberValue(valueAt(row, ['direct_material_cost', 'actual_raw_material_cost', 'material_cost', 'total_cost'], 0));
    const labour = numberValue(valueAt(row, ['direct_labour_cost', 'actual_labour_cost', 'labour_cost'], 0));
    const overhead = numberValue(valueAt(row, ['indirect_overhead_cost', 'actual_overhead_cost', 'overhead_cost'], 0));
    const label = toText(valueAt(row, ['plan', 'plan_label', 'plan_number', 'production_number', 'product_name', 'finished_product', 'material'], `Line ${index + 1}`));
    const date = safeDate(valueAt(row, ['completed_at', 'created_at', 'date'], ''));
    return { label, date, planned, good, reject, waste, material, labour, overhead, total: material + labour + overhead };
  }).filter((row) => row.label || row.planned || row.good || row.reject || row.waste || row.total);
}

function getTrendRows(rows = []) {
  return rows.map((row, index) => ({
    ...row,
    label: row.date && row.date !== '-' ? row.date : String(index + 1).padStart(2, '0'),
  }));
}

function costLegendItems(currency = DEFAULT_CURRENCY) {
  return [
    { label: 'Direct Material', color: VISUAL_COLORS.material, note: currency },
    { label: 'Direct Labour', color: VISUAL_COLORS.labour, note: currency },
    { label: 'Indirect Overhead', color: VISUAL_COLORS.overhead, note: currency },
  ];
}

function qualityLegendItems() {
  return [
    { label: 'Good Output', color: VISUAL_COLORS.good },
    { label: 'Rejects', color: VISUAL_COLORS.reject },
    { label: 'Wastage', color: VISUAL_COLORS.waste },
  ];
}

function lineLegendItems() {
  return [
    { label: 'Planned Qty', color: VISUAL_COLORS.planned },
    { label: 'Good Qty', color: VISUAL_COLORS.good },
    { label: 'Reject Qty', color: VISUAL_COLORS.reject },
    { label: 'Waste Qty', color: VISUAL_COLORS.waste },
  ];
}

function Legend({ items = [] }) {
  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
      {items.map((item) => (
        <span key={item.label} className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase text-[#465757]">
          <span className="h-2.5 w-2.5 border border-[#96A5A4]" style={{ backgroundColor: item.color }} />
          {item.label}
        </span>
      ))}
    </div>
  );
}

function LegendBlock({ title, items = [] }) {
  return (
    <div className="border border-[#CFD9D8] bg-[#F8FAFC] p-2">
      <div className="mb-2 text-[10px] font-semibold uppercase text-[#627474]">{title}</div>
      <Legend items={items} />
    </div>
  );
}

function LineGraph({ rows = [] }) {
  const width = 900;
  const height = 260;
  const padding = { left: 52, right: 20, top: 20, bottom: 44 };
  const maxValue = Math.max(...rows.flatMap((row) => [row.planned, row.good, row.reject, row.waste]), 1);
  const xFor = (index) => padding.left + (rows.length <= 1 ? 0 : (index / (rows.length - 1)) * (width - padding.left - padding.right));
  const yFor = (value) => height - padding.bottom - (numberValue(value) / maxValue) * (height - padding.top - padding.bottom);
  const series = [
    ['planned', VISUAL_COLORS.planned, 'Planned Qty'],
    ['good', VISUAL_COLORS.good, 'Good Qty'],
    ['reject', VISUAL_COLORS.reject, 'Reject Qty'],
    ['waste', VISUAL_COLORS.waste, 'Waste Qty'],
  ];

  return (
    <div className="w-full overflow-x-auto">
      <svg viewBox={`0 0 ${width} ${height}`} className="min-w-[760px] bg-white">
        {[0, 0.25, 0.5, 0.75, 1].map((ratio) => {
          const y = padding.top + ratio * (height - padding.top - padding.bottom);
          const value = maxValue - ratio * maxValue;
          return (
            <g key={ratio}>
              <line x1={padding.left} x2={width - padding.right} y1={y} y2={y} stroke="#E9EDED" />
              <text x={padding.left - 8} y={y + 3} textAnchor="end" fontSize="10" fill="#627474">{qtyNumber(value)}</text>
            </g>
          );
        })}
        <line x1={padding.left} x2={padding.left} y1={padding.top} y2={height - padding.bottom} stroke="#96A5A4" />
        <line x1={padding.left} x2={width - padding.right} y1={height - padding.bottom} y2={height - padding.bottom} stroke="#96A5A4" />
        {series.map(([key, color, label]) => {
          const points = rows.map((row, index) => `${xFor(index)},${yFor(row[key])}`).join(' ');
          return (
            <g key={key}>
              <polyline points={points} fill="none" stroke={color} strokeWidth="3" />
              {rows.map((row, index) => (
                <circle key={`${key}-${index}`} cx={xFor(index)} cy={yFor(row[key])} r="3.5" fill={color}>
                  <title>{`${label}: ${fmtQty(row[key])}`}</title>
                </circle>
              ))}
            </g>
          );
        })}
        {rows.map((row, index) => (
          <text key={index} x={xFor(index)} y={height - 24} textAnchor="middle" fontSize="9" fill="#627474">
            {String(row.label || index + 1).slice(0, 10)}
          </text>
        ))}
      </svg>
    </div>
  );
}

function ExecutiveSummary({ dashboard, selectedPlans, rawData, reportType }) {
  const planText = selectedPlans.length ? selectedPlans.map(getPlanLabel).join(', ') : 'All production plans within selected filters';
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-3">
      <div className="border border-[#B9C3C3] bg-white p-4">
        <h3 className="text-[16px] font-semibold text-[#0C1B1B]">Executive manufacturing summary</h3>
        <p className="mt-1 text-[12px] text-[#627474]">{planText}</p>
        <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <SummaryBox label="Orders / Rows" value={dashboard.orders} />
          <SummaryBox label="Good Output" value={fmtQty(dashboard.goodQty)} />
          <SummaryBox label="Reject Qty" value={fmtQty(dashboard.rejectQty)} danger={dashboard.rejectQty > 0} />
          <SummaryBox label="Waste Qty" value={fmtQty(dashboard.wasteQty)} danger={dashboard.wasteQty > 0} />
          <SummaryBox label="Direct Material" value={fmtMoney(dashboard.materialCost, dashboard.currency)} />
          <SummaryBox label="Direct Labour" value={fmtMoney(dashboard.labourCost, dashboard.currency)} />
          <SummaryBox label="Indirect Overhead" value={fmtMoney(dashboard.overheadCost, dashboard.currency)} />
          <SummaryBox label="Actual Cost" value={fmtMoney(dashboard.actualCost, dashboard.currency)} />
        </div>
        {reportType === 'plan_history' && rawData?.plan && (
          <div className="mt-4 border border-[#CFD9D8] bg-[#F8FAFC] p-3 text-[12px]">
            <b>{rawData.plan.plan_number}</b> - {rawData.plan.name} | {statusLabel(rawData.plan.status)} | {safeDate(rawData.plan.period_start)} to {safeDate(rawData.plan.period_end)}
          </div>
        )}
      </div>
    </div>
  );
}

function SummaryBox({ label, value, danger = false }) {
  return <div className={`border p-3 ${danger ? 'border-[#FDA29B] bg-[#FFFBFA]' : 'border-[#CFD9D8] bg-[#FCFCFD]'}`}><div className="text-[10px] font-semibold uppercase text-[#627474]">{label}</div><div className={`mt-2 text-[18px] font-semibold ${danger ? 'text-[#B42318]' : 'text-[#004343]'}`}>{value}</div></div>;
}

function Panel({ title, subtitle, children, actions = null }) {
  return (
    <div className="border border-[#B9C3C3] bg-white">
      <div className="flex min-h-[44px] items-center justify-between gap-2 border-b border-[#CFD9D8] bg-[#F5F7FA] px-3 py-2">
        <div className="min-w-0">
          <div className="text-[12px] font-semibold text-[#0C1B1B]">{title}</div>
          <div className="truncate text-[10px] uppercase text-[#627474]">{subtitle}</div>
        </div>
        {actions && <div className="shrink-0">{actions}</div>}
      </div>
      <div className="p-3">{children}</div>
    </div>
  );
}

function EmptyVisual() { return <div className="py-12 text-center text-[12px] text-[#627474]">No data available for visuals.</div>; }

function StackBar({ parts, max, colors = [VISUAL_COLORS.material, VISUAL_COLORS.labour, VISUAL_COLORS.overhead] }) {
  const total = parts.reduce((sum, value) => sum + numberValue(value), 0);
  const width = Math.max((total / max) * 100, total > 0 ? 2 : 0);
  return (
    <div className="h-4 border border-[#B9C3C3] bg-[#EEF2F2]">
      <div className="flex h-full" style={{ width: `${Math.min(100, width)}%` }}>
        {parts.map((part, index) => {
          const partWidth = total ? (numberValue(part) / total) * 100 : 0;
          return <div key={index} style={{ width: `${partWidth}%`, backgroundColor: colors[index] }} />;
        })}
      </div>
    </div>
  );
}

function QualityBar({ good, reject, waste, max }) {
  return <StackBar parts={[good, reject, waste]} max={max} colors={[VISUAL_COLORS.good, VISUAL_COLORS.reject, VISUAL_COLORS.waste]} />;
}

function buildDashboard(summary = {}, rows = [], rawData = {}) {
  const currency = summary.currency || rawData.currency || DEFAULT_CURRENCY;
  const sourceRows = rows || [];
  const orders = numberValue(summary.orders || summary.lines || summary.total_orders || sourceRows.length || rawData?.orders?.length || 0);
  const goodQty = numberValue(summary.actual_good_quantity || summary.completed_quantity || summary.good_quantity || sumRows(sourceRows, ['actual_good_quantity', 'completed_quantity', 'quantity_received', 'finished_product_quantity_actual', 'quantity_produced']));
  const rejectQty = numberValue(summary.actual_reject_quantity || summary.reject_quantity || sumRows(sourceRows, ['actual_reject_quantity', 'reject_quantity', 'quantity_rejected']));
  const wasteQty = numberValue(summary.actual_waste_quantity || summary.waste_quantity || summary.wasted_quantity || sumRows(sourceRows, ['actual_waste_quantity', 'waste_quantity', 'wasted_quantity', 'quantity_wasted']));
  const materialCost = numberValue(summary.direct_material_cost || summary.actual_raw_material_cost || sumRows(sourceRows, ['direct_material_cost', 'actual_raw_material_cost']));
  const labourCost = numberValue(summary.direct_labour_cost || summary.actual_labour_cost || sumRows(sourceRows, ['direct_labour_cost', 'actual_labour_cost']));
  const overheadCost = numberValue(summary.indirect_overhead_cost || summary.actual_overhead_cost || sumRows(sourceRows, ['indirect_overhead_cost', 'actual_overhead_cost']));
  const actualCost = numberValue(summary.actual_total_cost || summary.finished_goods_value || summary.total_cost || materialCost + labourCost + overheadCost || sumRows(sourceRows, ['actual_total_cost', 'total_cost']));
  return { orders, goodQty, rejectQty, wasteQty, materialCost, labourCost, overheadCost, actualCost, avgUnitCost: goodQty > 0 ? actualCost / goodQty : numberValue(summary.average_cost_per_unit || summary.actual_unit_cost), currency };
}

function sumRows(rows = [], keys = []) {
  return rows.reduce((sum, row) => sum + numberValue(valueAt(row, keys, 0)), 0);
}

function normalizeComparisonRows(data = {}) {
  const rows = data.rows || data.plans || data.comparison || [];
  return rows.map((row) => ({
    plan: row.plan || row.plan_label || row.plan_number || `${row.plan_number || ''} ${row.name || ''}`.trim(),
    ...row,
  }));
}

function buildComparisonRowsFromHistory(rawData = {}) {
  if (Array.isArray(rawData?.rows)) return normalizeComparisonRows(rawData);
  if (Array.isArray(rawData?.plans)) return normalizeComparisonRows(rawData);
  if (Array.isArray(rawData?.reports)) return rawData.reports.map(reportToComparisonRow);
  if (!rawData?.plan) return [];
  return [reportToComparisonRow(rawData)];
}

function reportToComparisonRow(report = {}) {
  const plan = report.plan || {};
  const summary = buildDashboard(report.summary || {}, report.orders || [], report);
  return {
    plan: `${plan.plan_number || ''} - ${plan.name || ''}`.trim() || report.plan_label || 'Plan',
    status: plan.status || report.status || '-',
    orders: asArray(report.orders).length || summary.orders,
    planned_quantity: sumRows(report.orders || [], ['planned_quantity', 'planned_output_quantity']),
    actual_good_quantity: summary.goodQty,
    actual_reject_quantity: summary.rejectQty,
    actual_waste_quantity: summary.wasteQty,
    yield_percent: summary.goodQty > 0 ? (summary.goodQty / Math.max(sumRows(report.orders || [], ['planned_quantity', 'planned_output_quantity']), summary.goodQty)) * 100 : 0,
    direct_material_cost: summary.materialCost,
    direct_labour_cost: summary.labourCost,
    indirect_overhead_cost: summary.overheadCost,
    actual_total_cost: summary.actualCost,
    actual_unit_cost: summary.avgUnitCost,
  };
}

function consolidateHistories(reports = []) {
  const cleanReports = reports.map((item) => item?.data || item).filter(Boolean);
  const output = {
    summary: {},
    reports: cleanReports,
    orders: [],
    material_usage: [],
    expenses: [],
    finished_goods: [],
    stock_movements: [],
    accounting_ledger: [],
  };
  cleanReports.forEach((report) => {
    const planLabel = report?.plan ? `${report.plan.plan_number || ''} - ${report.plan.name || ''}`.trim() : '';
    HISTORY_SECTIONS.forEach(([key]) => {
      output[key] = output[key].concat(asArray(report?.[key]).map((row) => ({ ...row, plan_label: planLabel })));
    });
  });
  output.summary = buildDashboard({}, output.orders, output);
  return { data: output };
}
