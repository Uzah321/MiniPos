import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import IssueStockToFrontModal from "./IssueStockToFrontModal";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getBackStock, issueBackStockEnterprise } from "../../../services/BohStockService";

jest.mock("../../../services/AuthenticationService", () => ({
  getAuthorisedCompany: jest.fn(),
}));

jest.mock("../../../services/BohStockService", () => ({
  getBackStock: jest.fn(),
  issueBackStockEnterprise: jest.fn(),
}));

const currentUser = {
  id: "user-1",
  name: "Jane Requester",
  branch_id: "branch-1",
  branch: { id: "branch-1", branch_name: "Central" },
};

const stockRow = {
  id: "stock-1",
  product_id: "product-1",
  branch_id: "branch-1",
  available_quantity: 10,
  average_cost: 2,
  currency: "USD",
  branch: { id: "branch-1", branch_name: "Central" },
  product: {
    id: "product-1",
    name: "Rice",
    code: "P001",
    sku: "SKU001",
    description: "Long grain rice",
    product_type: "raw_material",
    stock_usage_type: "turnover",
    category: { name: "Food" },
    uom: { name: "Kilogram", code: "KG" },
  },
};

describe("IssueStockToFrontModal", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getAuthorisedCompany.mockResolvedValue({
      company: { branches: [{ id: "branch-1", branch_name: "Central" }] },
    });
    getBackStock.mockResolvedValue({ data: [stockRow] });
    issueBackStockEnterprise.mockResolvedValue({ message: "Posted" });
  });

  test("locks Requested By to the authenticated user", async () => {
    const { container } = render(
      <IssueStockToFrontModal open onClose={jest.fn()} currentUser={currentUser} />
    );

    const requester = await screen.findByTitle("Requester is the signed-in user");
    expect(requester).toHaveValue("Jane Requester");
    expect(requester).toHaveAttribute("readonly");

    await waitFor(() => {
      const branchSelect = container.querySelectorAll("select")[1];
      expect(branchSelect).toHaveValue("branch-1");
    });
  });

  test("opens quantity from the stock row and never submits a client requester", async () => {
    const { container } = render(
      <IssueStockToFrontModal open onClose={jest.fn()} currentUser={currentUser} />
    );

    fireEvent.change(screen.getByPlaceholderText("e.g. Kitchen cleaning materials issue"), {
      target: { value: "Kitchen replenishment" },
    });
    fireEvent.change(screen.getByPlaceholderText("Reason for issue"), {
      target: { value: "Lunch service" },
    });

    await waitFor(() => expect(container.querySelectorAll("select")[1]).toHaveValue("branch-1"));
    fireEvent.click(screen.getByRole("button", { name: "Continue to Stock Items" }));

    const stockRecord = await screen.findByRole("button", { name: "Set issue quantity for Rice" });
    fireEvent.click(stockRecord);

    expect(await screen.findByText("Add Stock Item to Issue")).toBeInTheDocument();
    fireEvent.change(container.querySelector('input[type="number"]'), { target: { value: "2" } });
    fireEvent.click(screen.getByRole("button", { name: "Save Quantity" }));
    fireEvent.click(screen.getByRole("button", { name: "Review Issue" }));
    fireEvent.click(await screen.findByRole("button", { name: "Post Stock Issue" }));

    await waitFor(() => expect(issueBackStockEnterprise).toHaveBeenCalledTimes(1));
    const payload = issueBackStockEnterprise.mock.calls[0][0];
    expect(payload).not.toHaveProperty("requested_by");
    expect(payload).toMatchObject({
      branch_id: "branch-1",
      issue_name: "Kitchen replenishment",
      issue_purpose: "Lunch service",
      items: [{ product_id: "product-1", quantity: 2 }],
    });
  });
});
