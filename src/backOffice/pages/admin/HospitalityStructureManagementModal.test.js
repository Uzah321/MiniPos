import React from "react";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import HospitalityStructureManagementModal, {
  extractManagementPagination,
  extractManagementRows,
} from "./HospitalityStructureManagementModal";
import {
  createManagedHierarchyRecord,
  deleteManagedHierarchyRecord,
  getManagedHierarchyRecords,
  updateManagedHierarchyRecord,
} from "../../../services/EnterpriseHierarchyService";

jest.mock("../../../services/EnterpriseHierarchyService", () => ({
  createManagedHierarchyRecord: jest.fn(),
  deleteManagedHierarchyRecord: jest.fn(),
  getManagedHierarchyRecords: jest.fn(),
  updateManagedHierarchyRecord: jest.fn(),
}));

const response = (rows) => ({ data: { data: rows, current_page: 1, last_page: 1, total: rows.length } });

describe("hospitality structure management", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    createManagedHierarchyRecord.mockResolvedValue({ data: {} });
    updateManagedHierarchyRecord.mockResolvedValue({ data: {} });
    deleteManagedHierarchyRecord.mockResolvedValue({});
    getManagedHierarchyRecords.mockImplementation((resource) => {
      if (resource === "companies") return Promise.resolve(response([{ id: "company-1", company_name: "Simbisa Foods" }]));
      if (resource === "brands") return Promise.resolve(response([{ id: "brand-1", name: "Chicken Inn", company_id: "company-1" }]));
      if (resource === "complexes") return Promise.resolve(response([{ id: "complex-1", name: "Harare CBD", company_id: "company-1" }]));
      if (resource === "shops") return Promise.resolve(response([{ id: "shop-1", name: "First Street", code: "FS1", company_id: "company-1", brand_id: "brand-1", complex_id: "complex-1", brand: { id: "brand-1", name: "Chicken Inn" }, complex: { id: "complex-1", name: "Harare CBD" } }]));
      return Promise.resolve(response([]));
    });
  });

  test("normalizes flat paginated registers", () => {
    const payload = response([{ id: 1 }]);
    expect(extractManagementRows(payload)).toEqual([{ id: 1 }]);
    expect(extractManagementPagination(payload)).toEqual({ page: 1, lastPage: 1, total: 1 });
  });

  test("country management uses an image file picker instead of a path field", async () => {
    const { container } = render(
      <HospitalityStructureManagementModal open onClose={jest.fn()} section="countries" isPlatformAdmin />,
    );

    fireEvent.click(await screen.findByRole("button", { name: "Add Country" }));
    const form = screen.getByRole("dialog", { name: "Add Country" });
    const fileInput = form.querySelector('input[type="file"]');
    expect(fileInput).toHaveAttribute("accept", "image/png,image/jpeg,image/jpg,image/webp");
    expect(within(form).queryByText(/image url/i)).not.toBeInTheDocument();
    expect(container).not.toHaveTextContent(/enterprise hierarchy/i);
  });

  test("shop management requires independent brand and complex parents", async () => {
    render(
      <HospitalityStructureManagementModal open onClose={jest.fn()} section="shops" isPlatformAdmin />,
    );

    fireEvent.click(await screen.findByRole("button", { name: "Add Shop" }));
    const form = screen.getByRole("dialog", { name: "Add Shop" });
    fireEvent.change(within(form).getByLabelText(/Company/), { target: { value: "company-1" } });

    await waitFor(() => {
      expect(within(form).getByRole("option", { name: "Chicken Inn" })).toBeInTheDocument();
      expect(within(form).getByRole("option", { name: "Harare CBD" })).toBeInTheDocument();
    });
    expect(within(form).getByLabelText(/Brand/)).toBeRequired();
    expect(within(form).getByLabelText(/Complex/)).toBeRequired();
  });

  test("till management displays brand and complex inherited from its shop", async () => {
    render(
      <HospitalityStructureManagementModal open onClose={jest.fn()} section="tills" isPlatformAdmin />,
    );

    fireEvent.click(await screen.findByRole("button", { name: "Add Till" }));
    const form = screen.getByRole("dialog", { name: "Add Till" });
    fireEvent.change(within(form).getByLabelText(/Company/), { target: { value: "company-1" } });
    await waitFor(() => expect(within(form).getByRole("option", { name: "First Street (FS1)" })).toBeInTheDocument());
    fireEvent.change(within(form).getByLabelText(/Shop/), { target: { value: "shop-1" } });

    expect(within(form).getByText("Chicken Inn")).toBeInTheDocument();
    expect(within(form).getByText("Harare CBD")).toBeInTheDocument();
  });

  test("does not expose a redundant till settings action", async () => {
    getManagedHierarchyRecords.mockImplementation((resource) => {
      if (resource === "companies") return Promise.resolve(response([{ id: "company-1", company_name: "Simbisa Foods" }]));
      if (resource === "tills") return Promise.resolve(response([{ id: "till-1", name: "Counter 1", code: "T01", shop: { name: "First Street" } }]));
      return Promise.resolve(response([]));
    });

    render(<HospitalityStructureManagementModal open onClose={jest.fn()} section="tills" isPlatformAdmin />);

    const more = await screen.findByRole("button", { name: "More actions for Counter 1" });
    fireEvent.click(more);
    expect(screen.queryByRole("button", { name: "Open settings" })).not.toBeInTheDocument();
  });
});

