import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import ManagementReportNavigator, {
  getManagementReportItems,
  MANAGEMENT_REPORT_KEYS,
} from "./ManagementReportNavigator";

describe("ManagementReportNavigator", () => {
  test("always exposes the Hospitality management report set", () => {
    const expectedKeys = [
      MANAGEMENT_REPORT_KEYS.PROFIT_LOSS,
      MANAGEMENT_REPORT_KEYS.MONTHLY_PROFIT_COMPARISON,
      MANAGEMENT_REPORT_KEYS.DISCOUNTS,
      MANAGEMENT_REPORT_KEYS.TAX,
    ];

    expect(getManagementReportItems().map((item) => item.key)).toEqual(expectedKeys);
    // A historical caller argument cannot restore the removed mode branch.
    expect(getManagementReportItems("Retail").map((item) => item.key)).toEqual(expectedKeys);
  });

  test("navigates to another management report without reopening the active report", () => {
    const onNavigate = jest.fn();

    render(
      <ManagementReportNavigator
        activeKey={MANAGEMENT_REPORT_KEYS.DISCOUNTS}
        onNavigate={onNavigate}
        businessModeLabel="Retail"
      />
    );

    fireEvent.click(screen.getByRole("button", { name: /Profit & Loss/i }));
    expect(onNavigate).toHaveBeenCalledWith(MANAGEMENT_REPORT_KEYS.PROFIT_LOSS);

    fireEvent.click(screen.getByRole("button", { name: /Discount Report/i }));
    expect(onNavigate).toHaveBeenCalledTimes(1);

    expect(screen.getByRole("button", { name: /Monthly Profit Comparison/i })).toBeInTheDocument();
    expect(screen.queryByText(/Retail/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Price Adjustment History/i })).not.toBeInTheDocument();
  });
});
