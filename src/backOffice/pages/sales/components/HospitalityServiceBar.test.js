import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import HospitalityServiceBar from "./HospitalityServiceBar";

jest.mock("../../../../components/ManagerApprovalModal", () => ({
  __esModule: true,
  default: ({ open, onApproved, action, permission, referenceType }) => open ? (
    <button
      type="button"
      data-testid="manager-approval"
      data-action={action}
      data-permission={permission}
      data-reference-type={referenceType}
      onClick={() => onApproved({ id: "approval-1" })}
    >
      Approve manager
    </button>
  ) : null,
}));

const tables = [
  { id: "table-1", name: "Patio 1", capacity: 4 },
  { id: "table-2", name: "Patio 2", capacity: 2, active_order: { id: "other-order" } },
];

const hangingOrder = {
  id: "order-1",
  order_number: "Q-84",
  status: "hanging",
  revision: 3,
  service_type: "dine_in",
  table: tables[0],
  lines: [{ id: "line-1" }, { id: "line-2" }],
  total_amount: 18.5,
  currency_code: "USD",
};

const baseProps = {
  serviceType: "dine_in",
  assignmentMode: "manual",
  selectedTableId: "",
  tables,
  currentOrder: null,
  openOrders: [hangingOrder],
  cartLength: 0,
  busy: false,
  onRefresh: jest.fn(),
  onRecall: jest.fn(),
  onHang: jest.fn(),
  onSendKitchen: jest.fn(),
  onClear: jest.fn(),
  onRetry: jest.fn(),
  onServiceTypeChange: jest.fn(),
  onTableSelect: jest.fn(),
};

describe("HospitalityServiceBar", () => {
  beforeEach(() => jest.clearAllMocks());

  test("offers dine-in/takeaway and blocks occupied manual tables", () => {
    render(<HospitalityServiceBar {...baseProps} />);

    expect(screen.getByRole("button", { name: "Dine in" })).toHaveAttribute("aria-pressed", "true");
    fireEvent.click(screen.getByRole("button", { name: /Assign table before adding items/i }));
    fireEvent.mouseDown(screen.getByLabelText("Table"));

    expect(screen.getByRole("option", { name: /Patio 1.*available/i })).not.toHaveAttribute("aria-disabled", "true");
    expect(screen.getByRole("option", { name: /Patio 2.*occupied/i })).toHaveAttribute("aria-disabled", "true");
  });

  test("recalls a hanging order from the persistent open-table list", async () => {
    const onRecall = jest.fn().mockResolvedValue(undefined);
    render(<HospitalityServiceBar {...baseProps} onRecall={onRecall} />);

    fireEvent.click(screen.getByRole("button", { name: /Open tables/i }));
    expect(await screen.findByText("Q-84")).toBeInTheDocument();
    expect(screen.getByText(/2 items · USD 18\.50/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Recall & pay" }));

    await waitFor(() => expect(onRecall).toHaveBeenCalledWith(hangingOrder, { pay: true }));
  });

  test("recalls an order to add items, even while another order is on screen", async () => {
    const onRecall = jest.fn().mockResolvedValue(undefined);
    render(
      <HospitalityServiceBar
        {...baseProps}
        currentOrder={{ id: "order-on-screen", status: "open" }}
        cartLength={1}
        onRecall={onRecall}
      />
    );

    fireEvent.click(screen.getByRole("button", { name: /Open tables/i }));
    expect(await screen.findByText("Q-84")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Recall & pay" })).toBeEnabled();
    fireEvent.click(screen.getByRole("button", { name: "Add items" }));

    await waitFor(() => expect(onRecall).toHaveBeenCalledWith(hangingOrder));
  });

  test("requires manager approval when the operator cannot clear an open table", async () => {
    const onClear = jest.fn().mockResolvedValue(undefined);
    render(<HospitalityServiceBar {...baseProps} canClear={false} onClear={onClear} />);

    fireEvent.click(screen.getByRole("button", { name: /Open tables/i }));
    fireEvent.click(await screen.findByRole("button", { name: "Clear" }));
    fireEvent.change(screen.getByLabelText("Required reason"), { target: { value: "Duplicate ticket" } });
    fireEvent.click(screen.getByRole("button", { name: "Manager approval" }));

    const approval = screen.getByTestId("manager-approval");
    expect(approval).toHaveAttribute("data-action", "clear_open_table");
    expect(approval).toHaveAttribute("data-permission", "clear_open_tables");
    expect(approval).toHaveAttribute("data-reference-type", "hospitality_table_order");
    fireEvent.click(approval);

    await waitFor(() => expect(onClear).toHaveBeenCalledWith(hangingOrder, "Duplicate ticket", "approval-1"));
  });

  test("exposes pre-payment kitchen send for an active order", () => {
    const onSendKitchen = jest.fn();
    render(
      <HospitalityServiceBar
        {...baseProps}
        currentOrder={{ ...hangingOrder, status: "open" }}
        selectedTableId="table-1"
        cartLength={2}
        onSendKitchen={onSendKitchen}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Send kitchen" }));
    expect(onSendKitchen).toHaveBeenCalledWith(expect.objectContaining({ id: "order-1" }));
  });
});

