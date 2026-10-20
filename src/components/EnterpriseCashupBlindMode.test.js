import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import EnterpriseCashupModal, { cashupFeedbackMessage } from "./EnterpriseCashupModal";
import EnterpriseTableSystem from "./EnterpriseTableSystem";
import {
  cashupShift,
  endShift,
  getActiveShiftSummary,
  recordCashDrawerMovement,
} from "../services/CashupService";
import { getCurrencies } from "../services/CurrencyService";

jest.mock("../services/CashupService", () => ({
  cashupShift: jest.fn(),
  endShift: jest.fn(),
  getActiveShiftSummary: jest.fn(),
  recordCashDrawerMovement: jest.fn(),
}));

jest.mock("../services/CurrencyService", () => ({
  getCurrencies: jest.fn(),
}));

jest.mock("./ManagerApprovalModal", () => () => null);

const activeSummary = (canViewFinancials) => ({
  permissions: {
    can_view_cashup_financial_amounts: canViewFinancials,
    can_view_expected_cashup_amounts: canViewFinancials,
    can_cashup_on_behalf: false,
  },
  settings: { business_mode: "fast_food_boh", trading_date: "2026-10-03" },
  trading_date: "2026-10-03",
  totals: { transaction_count: 3 },
  shift: {
    shift_session_id: "shift-1",
    opened_at: "2026-10-03T05:52:00",
  },
  selected_shift: {
    id: "shift-1",
    cashier_name: "Cashier One",
    shift_name: "Morning",
    opened_at: "2026-10-03T05:52:00",
    is_own_shift: true,
  },
  available_shifts: [{
    id: "shift-1",
    cashier_name: "Cashier One",
    shift_name: "Morning",
    opened_at: "2026-10-03T05:52:00",
    is_own_shift: true,
  }],
  actor: { name: "Cashier One" },
  payments: [
    { payment_name: "CASH", money_code: "cash", currency_code: "USD", amount: 40, payment_count: 1 },
    { payment_name: "CARD", money_code: "card", currency_code: "USD", amount: 60, payment_count: 1 },
    { payment_name: "EcoCash", money_code: "mobile_money", currency_code: "USD", amount: 25, payment_count: 1 },
  ],
  denomination_template: [
    { currency_code: "USD", value: 20, quantity: 2, type: "note", sort_order: 1 },
    { currency_code: "USD", value: 10, quantity: 0, type: "note", sort_order: 2 },
  ],
  cash_movements: { cash_out_rows: [], cash_in_rows: [] },
});

const loadModal = (canViewFinancials, props = {}) => {
  getActiveShiftSummary.mockResolvedValue(activeSummary(canViewFinancials));
  getCurrencies.mockResolvedValue({
    data: [{ id: "usd", code: "USD", name: "US Dollar", is_base_currency: true }],
  });

  return render(
    <>
      <EnterpriseTableSystem />
      <EnterpriseCashupModal
        open
        onClose={jest.fn()}
        {...props}
      />
    </>
  );
};

beforeEach(() => {
  jest.clearAllMocks();
  cashupShift.mockResolvedValue({ message: "Cashup saved." });
  endShift.mockResolvedValue({ message: "Cashup saved." });
  recordCashDrawerMovement.mockResolvedValue({ message: "Cash movement saved." });
});

describe("EnterpriseCashupModal blind counting", () => {
  test("a denied optional currency catalogue does not block the cashier shift summary", async () => {
    getActiveShiftSummary.mockResolvedValue(activeSummary(false));
    getCurrencies.mockRejectedValue({
      response: { status: 403, data: { message: "User has no permission" } },
    });

    render(
      <EnterpriseCashupModal
        open
        onClose={jest.fn()}
        closeShiftDefault
      />
    );

    expect(await screen.findByText("Cashier One · Morning · 2026-10-03 05:52")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Count Cash/i })).toBeEnabled();
    expect(screen.queryByText(/Failed to load active shift summary/i)).not.toBeInTheDocument();
  });

  test("a super admin can select and close another cashier shift with an audit reason", async () => {
    const targetShift = {
      id: "shift-target",
      cashier_id: "cashier-target",
      cashier_name: "Cashier Target",
      shift_name: "Morning",
      opened_at: "2026-10-03T05:52:00",
      is_own_shift: false,
    };
    const otherShift = {
      id: "shift-other",
      cashier_id: "cashier-other",
      cashier_name: "Cashier Other",
      shift_name: "Afternoon",
      opened_at: "2026-10-03T13:00:00",
      is_own_shift: false,
    };
    const summary = {
      ...activeSummary(true),
      permissions: {
        ...activeSummary(true).permissions,
        can_cashup_on_behalf: true,
      },
      actor: { id: "system-owner", name: "System Owner" },
      selected_shift: targetShift,
      available_shifts: [targetShift, otherShift],
      shift: {
        ...activeSummary(true).shift,
        shift_session_id: targetShift.id,
      },
    };

    getActiveShiftSummary.mockResolvedValue(summary);
    getCurrencies.mockResolvedValue({
      data: [{ id: "usd", code: "USD", name: "US Dollar", is_base_currency: true }],
    });

    render(
      <EnterpriseCashupModal
        open
        onClose={jest.fn()}
        closeShiftDefault
      />
    );

    const shiftSelector = await screen.findByDisplayValue("Cashier Target · Morning · 2026-10-03 05:52");
    expect(shiftSelector).toBeEnabled();
    expect(screen.getByText("Supervisor cashup by System Owner")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /Review/i }));
    fireEvent.change(screen.getByLabelText("Supervisor Reason (required)"), {
      target: { value: "Cashier left before closing the drawer." },
    });
    fireEvent.click(screen.getByRole("button", { name: /Cashup and End Shift/i }));

    await waitFor(() => expect(endShift).toHaveBeenCalledTimes(1));
    expect(endShift.mock.calls[0][0]).toEqual(expect.objectContaining({
      shift_session_id: "shift-target",
      force_close_reason: "Cashier left before closing the drawer.",
      close_shift: true,
    }));
  });

  test("a cashier without financial visibility enters cash and every non-cash tender without seeing expected or variance", async () => {
    const openSpy = jest.spyOn(window, "open").mockImplementation(() => null);
    endShift.mockResolvedValue({
      message: "Cashup saved. Expected USD 125.00; variance USD -5.00.",
      cashup_print: { html: "<p>Expected USD 125.00; variance USD -5.00</p>" },
    });
    loadModal(false, { closeShiftDefault: true });

    fireEvent.click(screen.getByRole("button", { name: /Count Cash/i }));
    const twentyDollarQuantity = await screen.findByLabelText("USD 20.00 denomination quantity");

    // A server-provided quantity must never pre-fill a blind physical count.
    expect(twentyDollarQuantity).toHaveValue(null);
    fireEvent.change(twentyDollarQuantity, { target: { value: "2" } });

    fireEvent.click(screen.getByRole("button", { name: /Other Payments/i }));
    const cardCount = await screen.findByLabelText("CARD USD counted amount");
    const mobileCount = screen.getByLabelText("EcoCash USD counted amount");
    const cashCount = screen.getByLabelText("CASH USD counted amount");

    expect(cardCount).toHaveValue(0);
    expect(mobileCount).toHaveValue(0);
    expect(cashCount).toBeDisabled();
    expect(cashCount).toHaveValue(40);
    await waitFor(() => expect(cardCount.closest("td")).not.toHaveClass("sx-table-actions"));
    expect(cardCount.closest("table")?.tHead?.rows?.[0]?.cells?.[2]).toHaveTextContent("Counted");
    expect(screen.queryByText(/^Expected$/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/^Variance$/i)).not.toBeInTheDocument();

    fireEvent.change(cardCount, { target: { value: "58.25" } });
    fireEvent.change(mobileCount, { target: { value: "24.50" } });
    fireEvent.click(screen.getByRole("button", { name: /Review/i }));

    expect(await screen.findAllByText(/^Counted$/i)).not.toHaveLength(0);
    expect(screen.queryByText(/^Expected$/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/^Variance$/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /Cashup and End Shift/i }));

    await waitFor(() => expect(endShift).toHaveBeenCalledTimes(1));
    const submitted = endShift.mock.calls[0][0];
    expect(submitted.counted_cash).toBe(40);
    expect(submitted.trading_date).toBe("2026-10-03");
    expect(submitted).not.toHaveProperty("business_mode");
    expect(submitted.counted_payments).toEqual(expect.arrayContaining([
      expect.objectContaining({ payment_name: "CARD", currency_code: "USD", amount: 58.25 }),
      expect.objectContaining({ payment_name: "EcoCash", currency_code: "USD", amount: 24.5 }),
    ]));

    expect(await screen.findByText("Cashup saved and shift closed.")).toBeInTheDocument();
    expect(screen.queryByText(/Expected USD 125\.00/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/variance USD -5\.00/i)).not.toBeInTheDocument();
    expect(openSpy).not.toHaveBeenCalled();
    openSpy.mockRestore();
  });

  test("blind cashup validation errors never echo calculated financial comparisons", async () => {
    endShift.mockRejectedValue({
      response: { data: { message: "Expected USD 125.00 but counted USD 120.00; variance USD -5.00." } },
    });
    loadModal(false, { closeShiftDefault: true });

    await screen.findByText("2026-10-03");
    fireEvent.click(screen.getByRole("button", { name: /Review/i }));
    await screen.findByText("Review and Save");
    fireEvent.click(screen.getByRole("button", { name: /Cashup and End Shift/i }));

    expect(await screen.findByText("Cashup failed. Review the counted amounts and try again.")).toBeInTheDocument();
    expect(screen.queryByText(/Expected USD 125\.00/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/variance USD -5\.00/i)).not.toBeInTheDocument();
  });

  test("an authorised user retains expected, counted and variance reconciliation", async () => {
    loadModal(true);

    fireEvent.click(screen.getByRole("button", { name: /Other Payments/i }));
    const cardCount = await screen.findByLabelText("CARD USD counted amount");

    expect(cardCount).toHaveValue(60);
    expect(screen.getAllByText(/^Expected$/i).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/^Variance$/i).length).toBeGreaterThan(0);
    expect(screen.getAllByText("USD 125.00").length).toBeGreaterThan(0);
  });

  test("feedback text is fail-closed when financial visibility is absent", () => {
    expect(cashupFeedbackMessage({
      message: "Expected 100; variance -10",
      fallback: "Cashup failed.",
      canViewFinancials: false,
    })).toBe("Cashup failed.");

    expect(cashupFeedbackMessage({
      message: "Expected 100; variance -10",
      fallback: "Cashup failed.",
      canViewFinancials: true,
    })).toBe("Expected 100; variance -10");
  });
});
