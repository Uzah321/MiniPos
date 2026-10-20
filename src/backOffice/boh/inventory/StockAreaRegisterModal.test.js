import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import { BackStockRegisterModal } from "./StockAreaRegisterModal";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { downloadStockAreaRegister, getBackStock } from "../../../services/BohStockService";

jest.mock("../../../services/AuthenticationService", () => ({
  getAuthorisedCompany: jest.fn().mockResolvedValue({
    company: { branches: [{ id: "hospitality-a", branch_name: "Hospitality A" }] },
  }),
}));

jest.mock("../../../services/BohStockService", () => ({
  downloadStockAreaRegister: jest.fn().mockResolvedValue(undefined),
  getBackStock: jest.fn().mockResolvedValue({
    data: {
      data: [
        {
          id: "stock-a",
          branch_id: "hospitality-a",
          stock_area: "back",
          quantity_on_hand: 10,
          reserved_quantity: 1,
          available_quantity: 9,
          average_cost: 2,
          last_cost: 2.25,
          total_value: 20,
          currency: "USD",
          branch: { branch_name: "Hospitality A" },
          product: {
            name: "Chicken Fillet",
            code: "RM-001",
            sku: "SKU-001",
            barcode: "10001",
            product_type: "raw_material",
            is_active: true,
            reorder_level: 2,
            category: { name: "Ingredients" },
            uom: { name: "Kilogram", code: "KG" },
          },
        },
      ],
    },
  }),
  getFrontStock: jest.fn(),
}));

describe("StockAreaRegisterModal", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getAuthorisedCompany.mockResolvedValue({
      company: { branches: [{ id: "hospitality-a", branch_name: "Hospitality A" }] },
    });
    getBackStock.mockResolvedValue({
      data: [{
        id: "stock-a",
        branch_id: "hospitality-a",
        stock_area: "back",
        quantity_on_hand: 10,
        reserved_quantity: 1,
        available_quantity: 9,
        average_cost: 2,
        last_cost: 2.25,
        total_value: 20,
        currency: "USD",
        branch: { branch_name: "Hospitality A" },
        product: {
          name: "Chicken Fillet",
          code: "RM-001",
          sku: "SKU-001",
          barcode: "10001",
          product_type: "raw_material",
          is_active: true,
          reorder_level: 2,
          category: { name: "Ingredients" },
          uom: { name: "Kilogram", code: "KG" },
        },
      }],
    });
    downloadStockAreaRegister.mockResolvedValue(undefined);
  });

  test("uses plain one-value columns and a vertical-dots action menu", async () => {
    render(
      <BackStockRegisterModal
        open
        onClose={jest.fn()}
        currentUser={{ branch_id: "hospitality-a" }}
      />
    );

    await waitFor(() => expect(screen.getAllByRole("combobox")[0]).toHaveValue("hospitality-a"));
    fireEvent.click(screen.getByRole("button", { name: "Refresh" }));
    expect(await screen.findByText("Chicken Fillet")).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "Code" })).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "SKU" })).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "Average Cost" })).toBeInTheDocument();
    expect(screen.queryByRole("columnheader", { name: "Identity" })).not.toBeInTheDocument();
    expect(screen.queryByRole("columnheader", { name: "Classification" })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Actions for Chicken Fillet" }));
    expect(screen.getByRole("menuitem", { name: "View stock details" })).toBeInTheDocument();
  });

  test("requests downloads from the backend service", async () => {
    render(
      <BackStockRegisterModal
        open
        onClose={jest.fn()}
        currentUser={{ branch_id: "hospitality-a" }}
      />
    );

    await waitFor(() => expect(screen.getAllByRole("combobox")[0]).toHaveValue("hospitality-a"));
    fireEvent.click(screen.getByRole("button", { name: "Refresh" }));
    await screen.findByText("Chicken Fillet");
    fireEvent.click(screen.getByRole("button", { name: "Excel" }));

    await waitFor(() => {
      expect(downloadStockAreaRegister).toHaveBeenCalledWith(
        "back",
        "xlsx",
        expect.objectContaining({ branch_id: "hospitality-a" })
      );
    });
    expect(getBackStock).toHaveBeenCalled();
  });
});
