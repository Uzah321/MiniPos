import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import PaymentTypeManagementModal from "./PaymentTypeManagementModal";
import { getPaymentTypes } from "../../../services/PaymentTypeService";

jest.mock("../../../services/PaymentTypeService", () => ({
  getPaymentTypes: jest.fn(),
  getPaymentType: jest.fn(),
  createPaymentType: jest.fn(),
  updatePaymentType: jest.fn(),
  deletePaymentType: jest.fn(),
}));

const paymentType = {
  id: "payment-type-1",
  name: "Till Cash",
  money_code: "CASH",
  type: "cash",
  default_currency_code: "USD",
  supportsMultiCurrency: false,
  requiresAmountTendered: true,
  integration_status: false,
  is_active: true,
};

const renderModal = (permissions = {}) =>
  render(
    <PaymentTypeManagementModal
      open
      onClose={jest.fn()}
      canView
      {...permissions}
    />
  );

describe("PaymentTypeManagementModal permissions", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getPaymentTypes.mockResolvedValue({ data: [paymentType] });
  });

  test("keeps a view-only register read-only", async () => {
    renderModal();

    expect(await screen.findByRole("cell", { name: "Till Cash" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Add Payment Type" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Edit" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Delete" })).not.toBeInTheDocument();
  });

  test("shows only actions backed by their granular permissions", async () => {
    renderModal({ canCreate: true, canDelete: true });

    expect(await screen.findByRole("cell", { name: "Till Cash" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Add Payment Type" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Edit" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Delete" })).toBeInTheDocument();
  });

  test("does not load the register without view permission", async () => {
    render(
      <PaymentTypeManagementModal
        open
        onClose={jest.fn()}
        canCreate
        canUpdate
        canDelete
      />
    );

    await waitFor(() => expect(getPaymentTypes).not.toHaveBeenCalled());
  });
});
