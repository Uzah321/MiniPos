import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import PosCustomerAccountModal from "./PosCustomerAccountModal";
import {
  captureCreditCustomerPosPayment,
  getCreditCustomerPosAccount,
  getCreditCustomersForPos,
} from "../../../../services/CreditCustomerService";

jest.mock("../../../../services/CreditCustomerService", () => ({
  captureCreditCustomerPosPayment: jest.fn(),
  downloadCreditCustomerPaymentReceipt: jest.fn(),
  getCreditCustomerPosAccount: jest.fn(),
  getCreditCustomersForPos: jest.fn(),
}));

jest.mock("../../../../services/CurrencyService", () => ({
  convertCurrency: jest.fn(),
}));

const currencies = [{ id: "usd", code: "USD", is_active: true, is_base_currency: true }];
const paymentTypes = [{ id: "cash", name: "Cash", money_code: "cash", is_active: true }];

const customer = (status = "approved") => ({
  id: "customer-1",
  name: "Alex Customer",
  account_number: "ACC-1",
  currency_code: "USD",
  current_balance: 10,
  credit_limit: 100,
  status,
  is_active: true,
});

const accountResponse = (status = "approved") => ({
  customer: customer(status),
  available_credit: 90,
  statement: { closing_balance: 10, rows: [] },
});

const paymentResponse = (amount = 10, status = "approved") => ({
  idempotent_replay: false,
  data: {
    id: "payment-1",
    payment_number: "CCP-1",
    amount,
    currency_code: "USD",
  },
  customer: { ...customer(status), current_balance: Math.min(0, 10 - amount) },
  account: {
    currency_code: "USD",
    payment_currency_code: "USD",
    amount_received: amount,
    account_amount: amount,
    applied_to_debt: Math.min(10, amount),
    credit_created: Math.max(0, amount - 10),
    balance_after: 10 - amount,
  },
  statement: { closing_balance: 10 - amount, rows: [] },
});

const renderModal = async (status = "approved") => {
  getCreditCustomersForPos.mockResolvedValue([customer(status)]);
  getCreditCustomerPosAccount.mockResolvedValue(accountResponse(status));

  render(
    <PosCustomerAccountModal
      open
      onClose={jest.fn()}
      currencies={currencies}
      paymentTypes={paymentTypes}
    />
  );

  await waitFor(() => expect(getCreditCustomerPosAccount).toHaveBeenCalledWith("customer-1"));
  await waitFor(() => expect(screen.getByLabelText("Amount Received")).toBeEnabled());
};

describe("POS customer account payment integrity", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test("keeps one client payment id across a failed retry and sends false without excess", async () => {
    captureCreditCustomerPosPayment
      .mockRejectedValueOnce(new Error("temporary failure"))
      .mockResolvedValueOnce(paymentResponse(10));
    await renderModal();

    fireEvent.change(screen.getByLabelText("Amount Received"), { target: { value: "10" } });
    fireEvent.click(screen.getByRole("button", { name: /post account payment/i }));

    await waitFor(() => expect(captureCreditCustomerPosPayment).toHaveBeenCalledTimes(1));
    const firstPayload = captureCreditCustomerPosPayment.mock.calls[0][1];
    expect(firstPayload.confirm_overpayment).toBe(false);

    await waitFor(() => expect(screen.getByRole("button", { name: /post account payment/i })).toBeEnabled());
    fireEvent.click(screen.getByRole("button", { name: /post account payment/i }));

    await waitFor(() => expect(captureCreditCustomerPosPayment).toHaveBeenCalledTimes(2));
    const retryPayload = captureCreditCustomerPosPayment.mock.calls[1][1];
    expect(retryPayload.client_payment_id).toBe(firstPayload.client_payment_id);
    expect(retryPayload.confirm_overpayment).toBe(false);
  });

  test("sends true only from the explicit approved-account excess confirmation", async () => {
    captureCreditCustomerPosPayment.mockResolvedValue(paymentResponse(15));
    await renderModal();

    fireEvent.change(screen.getByLabelText("Amount Received"), { target: { value: "15" } });
    fireEvent.click(screen.getByRole("button", { name: /post account payment/i }));

    expect(captureCreditCustomerPosPayment).not.toHaveBeenCalled();
    const confirm = await screen.findByRole("button", { name: /confirm & credit excess/i });
    fireEvent.click(confirm);

    await waitFor(() => expect(captureCreditCustomerPosPayment).toHaveBeenCalledTimes(1));
    expect(captureCreditCustomerPosPayment.mock.calls[0][1]).toEqual(expect.objectContaining({
      confirm_overpayment: true,
      amount: 15,
    }));
  });

  test.each(["blocked", "suspended"])(
    "prevents %s accounts from creating prepaid credit",
    async (status) => {
      await renderModal(status);

      fireEvent.change(screen.getByLabelText("Amount Received"), { target: { value: "15" } });
      fireEvent.click(screen.getByRole("button", { name: /post account payment/i }));

      expect(captureCreditCustomerPosPayment).not.toHaveBeenCalled();
      expect(await screen.findByText(new RegExp(`${status} accounts may only settle`, "i"))).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /confirm & credit excess/i })).not.toBeInTheDocument();
    }
  );

  test("keeps the immediate docket after a restricted account is fully settled and leaves the lookup", async () => {
    getCreditCustomersForPos
      .mockResolvedValueOnce([customer("blocked")])
      .mockResolvedValueOnce([]);
    getCreditCustomerPosAccount.mockResolvedValue(accountResponse("blocked"));
    captureCreditCustomerPosPayment.mockResolvedValue(paymentResponse(10, "blocked"));

    render(
      <PosCustomerAccountModal
        open
        onClose={jest.fn()}
        currencies={currencies}
        paymentTypes={paymentTypes}
      />
    );

    await waitFor(() => expect(getCreditCustomerPosAccount).toHaveBeenCalledWith("customer-1"));
    fireEvent.change(screen.getByLabelText("Amount Received"), { target: { value: "10" } });
    fireEvent.click(screen.getByRole("button", { name: /post account payment/i }));

    await waitFor(() => expect(getCreditCustomersForPos).toHaveBeenCalledTimes(2));
    expect(await screen.findByRole("button", { name: /print 80mm/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^download$/i })).toBeInTheDocument();
  });
});
