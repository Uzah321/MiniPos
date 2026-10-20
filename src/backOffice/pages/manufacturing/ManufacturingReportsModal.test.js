import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import ManufacturingReportsModal from './ManufacturingReportsModal';
import {
  downloadManufacturingReport,
  getProductionPlanHistoryReport,
  getProductionPlans,
  getProductionSummaryReport,
} from '../../../services/ManufacturingService';

jest.mock('../../../components/HospitalityReportScopePanel', () => {
  const ReactModule = require('react');
  return {
    __esModule: true,
    default: ({ filters, setFilters, onApply, loading, actions }) => ReactModule.createElement(
      'div',
      { 'data-testid': 'report-scope' },
      ReactModule.createElement('input', {
        'aria-label': 'Date from',
        type: 'date',
        value: filters.date_from || '',
        onChange: (event) => setFilters({ ...filters, date_from: event.target.value }),
      }),
      ReactModule.createElement('input', {
        'aria-label': 'Date to',
        type: 'date',
        value: filters.date_to || '',
        onChange: (event) => setFilters({ ...filters, date_to: event.target.value }),
      }),
      actions,
      ReactModule.createElement('button', { type: 'button', disabled: loading, onClick: onApply }, 'Generate'),
    ),
    useHospitalityReportLookups: () => ({}),
  };
});

jest.mock('../../../services/ManufacturingService', () => ({
  downloadManufacturingReport: jest.fn(),
  getAccountingLedgerReport: jest.fn(),
  getFinishedGoodsReceivedReport: jest.fn(),
  getManufacturingDashboardReport: jest.fn(),
  getManyProductionPlanHistoryReports: jest.fn(),
  getMaterialRequirementsReport: jest.fn(),
  getMaterialUsageReport: jest.fn(),
  getProductionCostReport: jest.fn(),
  getProductionPlanComparisonReport: jest.fn(),
  getProductionPlanHistoryReport: jest.fn(),
  getProductionPlans: jest.fn(),
  getProductionSummaryReport: jest.fn(),
  getProductionVarianceReport: jest.fn(),
  getShortageAlertsReport: jest.fn(),
  getWastageReport: jest.fn(),
  getWipReport: jest.fn(),
}));

const reportResponse = {
  data: {
    summary: { order_count: 1, actual_good_quantity: 4, actual_total_cost: 20 },
    rows: [{ id: 1, production_order_code: 'PO-001', planned_quantity: 5, completed_quantity: 4 }],
  },
};

const renderModal = () => render(
  <ManufacturingReportsModal
    open
    onClose={() => {}}
    currentUser={{ branch_id: 3 }}
    businessModeLabel="Hospitality"
  />,
);

beforeEach(() => {
  jest.clearAllMocks();
  getProductionPlans.mockResolvedValue({
    data: [{ id: 7, plan_number: 'PLAN-007', name: 'October Plan', status: 'approved' }],
  });
  getProductionSummaryReport.mockResolvedValue(reportResponse);
  downloadManufacturingReport.mockResolvedValue(undefined);
});

test('opens the chooser first, then views and refreshes with the applied snapshot', async () => {
  renderModal();

  fireEvent.change(screen.getByLabelText('Date from'), { target: { value: '2026-10-01' } });
  fireEvent.change(screen.getByLabelText('Date to'), { target: { value: '2026-10-02' } });
  fireEvent.click(screen.getByRole('button', { name: 'Generate' }));

  expect(screen.getByRole('dialog', { name: 'Choose report output' })).toBeInTheDocument();
  expect(getProductionSummaryReport).not.toHaveBeenCalled();

  fireEvent.click(screen.getByRole('button', { name: /View Report/i }));

  expect(await screen.findByRole('button', { name: /Configuration \/ Back/i })).toBeInTheDocument();
  expect(screen.queryByTestId('report-scope')).not.toBeInTheDocument();
  expect(getProductionSummaryReport).toHaveBeenCalledWith(expect.objectContaining({
    branch_id: 3,
    date_from: '2026-10-01',
    date_to: '2026-10-02',
    per_page: 1000,
  }));

  const appliedParams = getProductionSummaryReport.mock.calls[0][0];
  fireEvent.click(screen.getByRole('button', { name: /Refresh/i }));
  await waitFor(() => expect(getProductionSummaryReport).toHaveBeenCalledTimes(2));
  expect(getProductionSummaryReport.mock.calls[1][0]).toEqual(appliedParams);

  const downloadButton = screen.getByRole('button', { name: /^Download$/i });
  await waitFor(() => expect(downloadButton).not.toBeDisabled());
  fireEvent.click(downloadButton);
  expect(screen.getByRole('button', { name: /View Report/i })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: /Download Excel/i }));
  await waitFor(() => expect(downloadManufacturingReport).toHaveBeenCalledWith(
    'production_summary',
    'xlsx',
    expect.objectContaining({ date_from: '2026-10-01', date_to: '2026-10-02' }),
  ));
});

test('downloads directly from configuration with captured filters and plan selection', async () => {
  renderModal();

  await waitFor(() => expect(getProductionPlans).toHaveBeenCalled());
  fireEvent.change(screen.getByRole('combobox'), { target: { value: 'approved' } });
  fireEvent.change(screen.getByPlaceholderText('Search table...'), { target: { value: 'flour' } });
  fireEvent.click(screen.getByRole('button', { name: /^Production Plans$/i }));
  const planButtons = await screen.findAllByRole('button', { name: /PLAN-007 - October Plan/i });
  fireEvent.click(planButtons[planButtons.length - 1]);

  fireEvent.click(screen.getByRole('button', { name: 'Generate' }));
  fireEvent.click(screen.getByRole('button', { name: /Download PDF/i }));

  await waitFor(() => expect(downloadManufacturingReport).toHaveBeenCalledWith(
    'production_summary',
    'pdf',
    expect.objectContaining({
      branch_id: 3,
      status: 'approved',
      production_plan_id: '7',
      production_plan_ids: ['7'],
      q: 'flour',
      history_section: 'orders',
    }),
  ));
  expect(getProductionSummaryReport).not.toHaveBeenCalled();
});

test('clears failed output and remains in configuration when View fails', async () => {
  getProductionSummaryReport.mockRejectedValueOnce(new Error('Backend unavailable'));
  renderModal();

  fireEvent.click(screen.getByRole('button', { name: 'Generate' }));
  fireEvent.click(screen.getByRole('button', { name: /View Report/i }));

  expect(await screen.findByText('Backend unavailable')).toBeInTheDocument();
  expect(screen.getByTestId('report-scope')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /Configuration \/ Back/i })).not.toBeInTheDocument();
  expect(screen.queryByText('PO-001')).not.toBeInTheDocument();
});

test('keeps plan-history section navigation and downloads the displayed section', async () => {
  getProductionPlanHistoryReport.mockResolvedValueOnce({
    data: {
      summary: {},
      orders: [{ id: 21, production_number: 'ORDER-021' }],
      material_usage: [{ id: 31, production_number: 'ORDER-021', material: 'Flour', issued_quantity: 3 }],
      expenses: [],
      finished_goods: [],
      stock_movements: [],
      accounting_ledger: [],
    },
  });
  renderModal();

  await waitFor(() => expect(getProductionPlans).toHaveBeenCalled());
  fireEvent.click(screen.getByRole('button', { name: /^Production Plans$/i }));
  const planButtons = await screen.findAllByRole('button', { name: /PLAN-007 - October Plan/i });
  fireEvent.click(planButtons[planButtons.length - 1]);
  fireEvent.click(screen.getByRole('button', { name: /^Production Plan History$/i }));
  fireEvent.click(screen.getByRole('button', { name: 'Generate' }));
  fireEvent.click(screen.getByRole('button', { name: /View Report/i }));

  expect(await screen.findByText('ORDER-021')).toBeInTheDocument();
  const materialUsageButtons = screen.getAllByRole('button', { name: /^Material Usage$/i });
  fireEvent.click(materialUsageButtons[materialUsageButtons.length - 1]);
  expect(await screen.findByText('Flour')).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: /^Download$/i }));
  fireEvent.click(screen.getByRole('button', { name: /Download PDF/i }));
  await waitFor(() => expect(downloadManufacturingReport).toHaveBeenCalledWith(
    'plan_history',
    'pdf',
    expect.objectContaining({ history_section: 'material_usage' }),
  ));
});
