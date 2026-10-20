import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import IssuedStockModal from "./IssuedStockModal";
import {
  downloadIssuedStockPdf,
  getIssuedStock,
  getIssuedStockDetail,
} from "../../../services/IssuedStockService";

jest.mock("../../../services/IssuedStockService", () => ({
  downloadIssuedStockPdf: jest.fn(),
  getIssuedStock: jest.fn(),
  getIssuedStockDetail: jest.fn(),
}));

const registerRow = {
  issue_id: "issue-1",
  issue_number: "ISS-20261005-0001",
  issue_name: "Kitchen replenishment",
  issue_destination: "department",
  branch_name: "Central",
  department_name: "Kitchen",
  requested_by: "Jane Requester",
  issue_purpose: "Lunch service",
  posted_at: "2026-10-05T10:30:00+02:00",
  currency_code: "USD",
  line_count: 1,
  total_quantity: 2,
  total_value: 4,
};

describe("IssuedStockModal", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getIssuedStock.mockResolvedValue({
      data: [registerRow],
      meta: { current_page: 1, last_page: 1, total: 1 },
    });
    getIssuedStockDetail.mockResolvedValue({
      data: {
        ...registerRow,
        cost_center: "FOOD",
        lines: [{
          movement_id: "movement-1",
          product_id: "product-1",
          product_name: "Rice",
          product_code: "P001",
          product_sku: "SKU001",
          stock_usage_type: "turnover",
          issued_quantity: 2,
          uom_code: "KG",
          unit_cost: 2,
          total_cost: 4,
          currency_code: "USD",
          from_stock_area: "back",
          to_stock_area: "department",
        }],
      },
    });
    downloadIssuedStockPdf.mockResolvedValue({});
  });

  test("opens a posted issue from the row and downloads its PDF", async () => {
    render(
      <IssuedStockModal
        open
        onClose={jest.fn()}
        currentUser={{ id: "user-1", branch_id: "branch-1" }}
      />
    );

    const issueRow = await screen.findByRole("button", {
      name: "View stock issue ISS-20261005-0001",
    });
    fireEvent.click(issueRow);

    expect(await screen.findByText("Issued Stock ISS-20261005-0001")).toBeInTheDocument();
    expect(screen.getByText("Rice")).toBeInTheDocument();
    expect(getIssuedStockDetail).toHaveBeenCalledWith("issue-1", { branch_id: "branch-1" });

    fireEvent.click(screen.getByRole("button", { name: "Download PDF" }));
    await waitFor(() => {
      expect(downloadIssuedStockPdf).toHaveBeenCalledWith(
        "issue-1",
        { branch_id: "branch-1" },
        "ISS-20261005-0001.pdf"
      );
    });
  });
});
