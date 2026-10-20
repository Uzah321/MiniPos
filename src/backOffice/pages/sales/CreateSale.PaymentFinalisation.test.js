import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import "@testing-library/jest-dom";

import { PaymentPanel, SplitPaymentModal } from "./CreateSale";

const noop = jest.fn();

const paymentProps = (overrides = {}) => ({
  totals: {
    subTotal: 100,
    discount: 0,
    tax: 0,
    total: 100,
    received: 105,
    balance: 0,
    change: 5,
    currency: "USD",
  },
  amountReceived: "105",
  paymentRows: [{ id: "row-cash", payment_type_id: "cash", amount: "105" }],
  activePaymentRowId: "row-cash",
  activePaymentAmount: "105",
  selectPaymentRow: noop,
  updatePaymentRowAmount: noop,
  updatePaymentRowType: noop,
  addPaymentRow: noop,
  removePaymentRow: noop,
  openSplitPaymentModal: noop,
  paymentTypes: [{ id: "cash", name: "Cash" }],
  selectedPaymentTypeId: "cash",
  setSelectedPaymentTypeId: noop,
  selectedPaymentType: { id: "cash", name: "Cash" },
  currencies: [{ id: "usd", code: "USD", name: "US Dollar" }],
  saleCurrencyCode: "USD",
  handleSaleCurrencyChange: noop,
  paymentCanChooseCurrency: true,
  amountTenderedRequired: true,
  creditCustomers: [],
  selectedCreditCustomerId: "",
  selectedCreditCustomer: null,
  handleCreditCustomerChange: noop,
  creditPoNumber: "",
  setCreditPoNumber: noop,
  isCreditSale: false,
  creditAvailable: 0,
  creditLimitExceeded: false,
  loadingCreditCustomers: false,
  accountCustomers: [
    {
      id: "customer-1",
      name: "Ada Customer",
      account_number: "ACC-001",
      currency_code: "USD",
      status: "approved",
    },
  ],
  loadingAccountCustomers: false,
  canCaptureCreditCustomerPayment: true,
  postChangeToCreditAccount: true,
  changeCreditCustomerId: "customer-1",
  setChangeCreditCustomerId: noop,
  handlePostChangeToggle: noop,
  loadingPayments: false,
  loadingCurrencies: false,
  appendPaymentValue: noop,
  backspacePaymentValue: noop,
  clearPaymentValue: noop,
  exactPayment: noop,
  quickPayment: noop,
  completeSale: noop,
  saleComplete: false,
  clearSale: noop,
  savingSale: false,
  printingReceipt: false,
  lastSaleResponse: null,
  onBack: noop,
  ...overrides,
});

describe("payment finalisation layout", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test("keeps credited-change controls in the summary rail beside the fixed keypad", () => {
    const handlePostChangeToggle = jest.fn();
    render(<PaymentPanel {...paymentProps({ handlePostChangeToggle })} />);

    const rail = screen.getByTestId("payment-summary-rail");
    const changeCard = screen.getByTestId("change-allocation-card");
    const keypad = screen.getByTestId("payment-keypad");
    const workspace = screen.getByTestId("payment-workspace");

    expect(rail).toContainElement(changeCard);
    expect(workspace).toContainElement(rail);
    expect(workspace).toContainElement(keypad);
    expect(screen.getByLabelText("Amount tendered")).toHaveAttribute("data-touch-keyboard", "off");
    expect(screen.getByRole("button", { name: "Complete · Credit USD 5.00" })).toBeEnabled();

    fireEvent.click(screen.getByRole("checkbox"));
    expect(handlePostChangeToggle).toHaveBeenCalledWith(false);
  });

  test("locks completion while customer accounts are loading", () => {
    render(<PaymentPanel {...paymentProps({ loadingAccountCustomers: true })} />);

    expect(screen.getByRole("button", { name: "Complete · Credit USD 5.00" })).toBeDisabled();
  });
});

describe("split payment modal", () => {
  test("shows a compact payment editor and closes with Escape", () => {
    const onClose = jest.fn();
    const addPaymentRow = jest.fn();
    const updatePaymentRowAmount = jest.fn();
    const rows = [
      { id: "cash-row", payment_type_id: "cash", amount: "40" },
      { id: "card-row", payment_type_id: "card", amount: "20" },
    ];
    const paymentTypes = [
      { id: "cash", name: "Cash" },
      { id: "card", name: "Card" },
    ];

    render(
      <SplitPaymentModal
        open
        onClose={onClose}
        paymentRows={rows}
        paymentTypes={paymentTypes}
        saleCurrencyCode="USD"
        totals={{ total: 100, currency: "USD" }}
        loadingPayments={false}
        activePaymentRowId="cash-row"
        selectPaymentRow={noop}
        updatePaymentRowAmount={updatePaymentRowAmount}
        updatePaymentRowType={noop}
        addPaymentRow={addPaymentRow}
        removePaymentRow={noop}
        completeSale={noop}
        savingSale={false}
        printingReceipt={false}
        paymentTypeById={new Map(paymentTypes.map((row) => [row.id, row]))}
      />
    );

    expect(screen.getByTestId("split-payment-modal")).toBeInTheDocument();
    expect(screen.queryByText(/example:/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/different currency/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /add payment/i }));
    expect(addPaymentRow).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getAllByRole("button", { name: /fill balance/i })[0]);
    expect(updatePaymentRowAmount).toHaveBeenCalledWith("cash-row", "80.00");

    fireEvent.keyDown(window, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
