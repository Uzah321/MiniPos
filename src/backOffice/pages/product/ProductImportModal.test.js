import React from "react";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import ProductImportModal from "./ProductImportModal";
import {
  commitHospitalityWorkbook,
  getHospitalityImportScope,
  previewHospitalityWorkbook,
} from "../../../services/ProductImportService";

jest.mock("../../../services/ProductImportService", () => ({
  commitHospitalityWorkbook: jest.fn(),
  getHospitalityImportScope: jest.fn(),
  previewHospitalityWorkbook: jest.fn(),
}));

describe("brand menu import", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    getHospitalityImportScope.mockResolvedValue({
      data: {
        company: { id: "company-1", name: "ServeNest Simulation" },
        company_wide: true,
        requires_branch: false,
        brands: [{
          id: "brand-1",
          name: "Chicken Inn",
          code: "CHI",
          shops: [{
            id: "shop-1",
            branch_id: "branch-1",
            name: "First Street",
            code: "FS1",
            branch_name: "Harare",
          }],
        }],
      },
    });
    previewHospitalityWorkbook.mockResolvedValue({
      data: {
        can_commit: true,
        format: "gaap",
        sheets: ["Menu Items", "Recipes"],
        statistics: { menu_items: 12, recipes: 8 },
        warnings: [],
        errors: [],
      },
    });
    commitHospitalityWorkbook.mockResolvedValue({ data: { import_id: "import-1", statistics: {} } });
  });

  test("requires and submits the selected brand with the workbook", async () => {
    render(<ProductImportModal open onClose={jest.fn()} />);

    const dialog = screen.getByRole("dialog", { name: "Brand menu import" });
    expect(await within(dialog).findByRole("option", { name: "Chicken Inn (CHI)" })).toBeInTheDocument();

    const file = new File(["xlsx"], "Zim Chicken Inn BOMs.xlsx", {
      type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    });
    fireEvent.change(within(dialog).getByLabelText(/Excel Workbook/), { target: { files: [file] } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Validate" }));

    await waitFor(() => expect(previewHospitalityWorkbook).toHaveBeenCalledTimes(1));
    const payload = previewHospitalityWorkbook.mock.calls[0][0];
    expect(payload.file.name).toBe("Zim Chicken Inn BOMs.xlsx");
    expect(payload.brand_id).toBe("brand-1");
    expect(payload.source_system).toBe("gaap");
    expect(await within(dialog).findByText(/Ready to import into Chicken Inn/i)).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Import" })).toBeEnabled();
  });

  test("limits shops to the selected brand and submits the selected branch on preview and commit", async () => {
    getHospitalityImportScope.mockResolvedValueOnce({
      data: {
        company: { id: "company-1", name: "ServeNest Simulation" },
        company_wide: false,
        requires_branch: true,
        brands: [{
          id: "brand-chicken",
          name: "Chicken Inn",
          code: "CHI",
          shops: [{ id: "shop-chicken", branch_id: "branch-chicken", name: "Chicken Shop", code: "CS1" }],
        }, {
          id: "brand-pizza",
          name: "Pizza Inn",
          code: "PZI",
          shops: [{ id: "shop-pizza", branch_id: "branch-pizza", name: "Pizza Shop", code: "PS1" }],
        }],
      },
    });

    render(<ProductImportModal open onClose={jest.fn()} />);

    const dialog = screen.getByRole("dialog", { name: "Brand menu import" });
    const brandSelect = within(dialog).getByLabelText(/Brand \*/i);
    expect(await within(dialog).findByRole("option", { name: "Pizza Inn (PZI)" })).toBeInTheDocument();
    fireEvent.change(brandSelect, { target: { value: "brand-pizza" } });

    const shopSelect = within(dialog).getByLabelText(/Shop \*/i);
    expect(within(shopSelect).queryByRole("option", { name: "Brand-wide import" })).not.toBeInTheDocument();
    expect(within(shopSelect).getByRole("option", { name: "Pizza Shop (PS1)" })).toBeInTheDocument();
    expect(within(shopSelect).queryByRole("option", { name: "Chicken Shop (CS1)" })).not.toBeInTheDocument();
    fireEvent.change(shopSelect, { target: { value: "branch-pizza" } });

    const file = new File(["xlsx"], "Pizza Inn BOMs.xlsx", {
      type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    });
    fireEvent.change(within(dialog).getByLabelText(/Excel Workbook/), { target: { files: [file] } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Validate" }));

    await waitFor(() => expect(previewHospitalityWorkbook).toHaveBeenCalledWith(expect.objectContaining({
      brand_id: "brand-pizza",
      branch_id: "branch-pizza",
    })));

    const importButton = within(dialog).getByRole("button", { name: "Import" });
    await waitFor(() => expect(importButton).toBeEnabled());
    fireEvent.click(importButton);
    const confirmation = await screen.findByRole("dialog", { name: "Confirm brand menu import" });
    fireEvent.click(within(confirmation).getByRole("button", { name: "Import" }));

    await waitFor(() => expect(commitHospitalityWorkbook).toHaveBeenCalledWith(expect.objectContaining({
      brand_id: "brand-pizza",
      branch_id: "branch-pizza",
    })));
  });

  test("clears unavailable scope data and shows the protected endpoint error", async () => {
    getHospitalityImportScope.mockRejectedValueOnce({
      response: { data: { message: "You do not have permission to access this company management resource." } },
    });

    render(<ProductImportModal open onClose={jest.fn()} />);

    const dialog = screen.getByRole("dialog", { name: "Brand menu import" });
    expect(await within(dialog).findByText(/do not have permission/i)).toBeInTheDocument();
    expect(within(dialog).getByRole("option", { name: "No brands available" })).toBeInTheDocument();
  });
});
