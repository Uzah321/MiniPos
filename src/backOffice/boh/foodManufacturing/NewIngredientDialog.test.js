import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import NewIngredientDialog from "./NewIngredientDialog";
import {
  createProduct,
  generateProductIdentity,
  getProductCategories,
  getUoms,
} from "../../../services/ProductService";

jest.mock("../../../services/ProductService", () => ({
  createProduct: jest.fn(),
  generateProductIdentity: jest.fn(),
  getProductCategories: jest.fn(),
  getUoms: jest.fn(),
}));

const renderDialog = (props = {}) =>
  render(
    <NewIngredientDialog
      open
      initialName="Beef patty"
      branchId="branch-cbd"
      onClose={() => {}}
      {...props}
    />,
  );

describe("NewIngredientDialog", () => {
  beforeEach(() => {
    getUoms.mockResolvedValue({ data: [{ id: "uom-each", name: "Each", code: "EACH" }] });
    getProductCategories.mockResolvedValue({ data: { data: [{ id: "cat-meat", name: "Meat" }] } });
    generateProductIdentity.mockImplementation(async ({ type }) => ({
      data: { value: type === "sku" ? "SKU00010" : "PRD00009" },
    }));
    createProduct.mockResolvedValue({
      data: { id: "product-new", name: "Beef patty", code: "PRD00009", product_type: "raw_material" },
    });
  });

  test("creates a stock item with generated codes and hands it to the recipe", async () => {
    const created = [];
    renderDialog({ onCreated: (product) => created.push(product) });

    expect(screen.getByLabelText("Ingredient name")).toHaveValue("Beef patty");
    await screen.findByRole("option", { name: "Each (EACH)" });
    fireEvent.change(screen.getByLabelText("Unit of measure"), { target: { value: "uom-each" } });
    fireEvent.change(screen.getByLabelText("Category (optional)"), { target: { value: "cat-meat" } });
    fireEvent.click(screen.getByRole("button", { name: "Create and add" }));

    await waitFor(() => expect(created).toHaveLength(1));
    expect(generateProductIdentity).toHaveBeenCalledWith({ type: "product_code", branch_id: "branch-cbd" });
    expect(generateProductIdentity).toHaveBeenCalledWith({ type: "sku", branch_id: "branch-cbd" });
    expect(createProduct).toHaveBeenCalledWith(expect.objectContaining({
      name: "Beef patty",
      code: "PRD00009",
      sku: "SKU00010",
      product_type: "raw_material",
      base_uom_id: "uom-each",
      category_id: "cat-meat",
      stock_usage_type: "turnover",
      default_issue_destination: "front",
      stock_tracking_enabled: true,
      is_global: true,
    }));
    expect(created[0]).toEqual(expect.objectContaining({
      id: "product-new",
      base_uom_id: "uom-each",
      uom: { id: "uom-each", name: "Each", code: "EACH" },
    }));
  });

  test("uses a typed code for both code and SKU", async () => {
    renderDialog({ onCreated: () => {} });
    await screen.findByRole("option", { name: "Each (EACH)" });
    fireEvent.change(screen.getByLabelText("Unit of measure"), { target: { value: "uom-each" } });
    fireEvent.change(screen.getByLabelText("Code (optional)"), { target: { value: "PATTY01" } });
    fireEvent.click(screen.getByRole("button", { name: "Create and add" }));

    await waitFor(() => expect(createProduct).toHaveBeenCalled());
    expect(generateProductIdentity).not.toHaveBeenCalled();
    expect(createProduct).toHaveBeenCalledWith(expect.objectContaining({ code: "PATTY01", sku: "PATTY01" }));
  });

  test("asks for a unit and shows server validation errors", async () => {
    createProduct.mockRejectedValue({
      response: { data: { errors: { name: ["The name has already been taken."] } } },
    });
    renderDialog({ onCreated: () => {} });
    await screen.findByRole("option", { name: "Each (EACH)" });

    fireEvent.click(screen.getByRole("button", { name: "Create and add" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Choose the unit the recipe measures it in.");
    expect(createProduct).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText("Unit of measure"), { target: { value: "uom-each" } });
    fireEvent.click(screen.getByRole("button", { name: "Create and add" }));
    expect(await screen.findByText("The name has already been taken.")).toBeInTheDocument();
  });
});
