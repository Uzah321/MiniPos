import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import HospitalityTableServiceModal from "./HospitalityTableServiceModal";
import {
  createHospitalityTable,
  getHospitalityTables,
  getHospitalityTableServiceSettings,
  updateHospitalityTableServiceSettings,
} from "../../../services/HospitalityTableManagementService";

const mockWorkspace = {
    data: {
      available_shops: [{ id: "shop-1", branch_id: "branch-1", name: "Harare CBD" }],
    },
    shop: { id: "shop-1", branch_id: "branch-1", name: "Harare CBD" },
};

jest.mock("../../../context/WorkspaceContext", () => ({
  useOptionalWorkspace: () => mockWorkspace,
}));

jest.mock("../../../services/HospitalityTableManagementService", () => ({
  createHospitalityTable: jest.fn(),
  getHospitalityTables: jest.fn(),
  getHospitalityTableServiceSettings: jest.fn(),
  updateHospitalityTable: jest.fn(),
  updateHospitalityTableServiceSettings: jest.fn(),
}));

const manager = {
  permissions: [{ name: "manage_hospitality_tables" }],
  selectedBranchId: "branch-1",
  selectedShopId: "shop-1",
};

describe("HospitalityTableServiceModal", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getHospitalityTableServiceSettings.mockResolvedValue({
      assignment_mode: "manual",
      require_table_for_dine_in: true,
      takeaway_uses_tables: false,
      allow_cross_till_recall: false,
      is_active: true,
    });
    getHospitalityTables.mockResolvedValue([{
      id: "table-1",
      code: "T01",
      name: "Window 1",
      section: "Main floor",
      capacity: 4,
      is_active: true,
      active_order: null,
    }]);
    updateHospitalityTableServiceSettings.mockResolvedValue({ assignment_mode: "auto" });
    createHospitalityTable.mockResolvedValue({ id: "table-2" });
  });

  test("loads explicit shop scope and saves configurable assignment rules", async () => {
    render(<HospitalityTableServiceModal open onClose={jest.fn()} currentUser={manager} />);

    expect(await screen.findByText("Window 1")).toBeInTheDocument();
    expect(getHospitalityTableServiceSettings).toHaveBeenCalledWith({
      branch_id: "branch-1",
      shop_id: "shop-1",
      label: "Harare CBD",
      detail: "",
    });
    expect(screen.getByRole("radio", { name: "Cashier selects a table" })).toBeChecked();
    fireEvent.click(screen.getByRole("radio", { name: "Assign next available table" }));
    fireEvent.click(screen.getByRole("switch", { name: /recall each other's open orders/i }));
    fireEvent.click(screen.getByRole("button", { name: "Save table rules" }));

    await waitFor(() => expect(updateHospitalityTableServiceSettings).toHaveBeenCalledWith(
      expect.objectContaining({ branch_id: "branch-1", shop_id: "shop-1" }),
      expect.objectContaining({
        assignment_mode: "auto",
        require_table_for_dine_in: true,
        allow_cross_till_recall: true,
      }),
    ));
  });

  test("creates a dining table with both scope identifiers", async () => {
    render(<HospitalityTableServiceModal open onClose={jest.fn()} currentUser={manager} />);
    await screen.findByText("Window 1");

    fireEvent.click(screen.getByRole("button", { name: "Add table" }));
    fireEvent.change(await screen.findByLabelText(/Table code/i), { target: { value: "P01" } });
    fireEvent.change(screen.getByLabelText(/Table name/i), { target: { value: "Patio 1" } });
    fireEvent.change(screen.getByLabelText("Guest capacity"), { target: { value: "6" } });
    fireEvent.click(screen.getByRole("button", { name: "Save table" }));

    await waitFor(() => expect(createHospitalityTable).toHaveBeenCalledWith(
      expect.objectContaining({ branch_id: "branch-1", shop_id: "shop-1" }),
      expect.objectContaining({ code: "P01", name: "Patio 1", capacity: 6 }),
    ));
  });

  test("keeps table configuration read-only without management permission", async () => {
    render(
      <HospitalityTableServiceModal
        open
        onClose={jest.fn()}
        currentUser={{ ...manager, permissions: [{ name: "view_hospitality_tables" }] }}
      />,
    );

    expect(await screen.findByText(/workspace is read-only/i)).toBeInTheDocument();
    await screen.findByText("Window 1");
    expect(screen.getByRole("button", { name: "Save table rules" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Add table" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "More actions for Window 1" }));
    expect(screen.getByRole("menuitem", { name: "Edit table" })).toBeDisabled();
  });
});
