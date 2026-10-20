import React from "react";
import { render, screen } from "@testing-library/react";
import InventoryReportNavigator, {
  getHospitalityInventoryReportItems,
  getRetailInventoryReportItems,
} from "./InventoryReportNavigator";

describe("InventoryReportNavigator", () => {
  test("renders Hospitality inventory language", () => {
    render(<InventoryReportNavigator activeKey="dashboard" onNavigate={jest.fn()} />);

    expect(screen.getByRole("navigation", { name: "Hospitality inventory reports" })).toBeInTheDocument();
    expect(screen.getByText("Detailed product stock adjustment audit")).toBeInTheDocument();
    expect(screen.queryByText(/Retail/i)).not.toBeInTheDocument();
  });

  test("retains the historical helper export without restoring a separate mode", () => {
    expect(getRetailInventoryReportItems()).toBe(getHospitalityInventoryReportItems());
  });
});
