import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";

import ReportViewShell from "./ReportViewShell";

describe("ReportViewShell", () => {
  test("renders report output and returns to filters only when requested", () => {
    const onBackToFilters = jest.fn();

    render(
      <ReportViewShell
        title="Daily Sales Report"
        subtitle="Harare · 02 Oct 2026"
        onBackToFilters={onBackToFilters}
        actions={<button type="button">Refresh</button>}
      >
        <div>Report table</div>
      </ReportViewShell>,
    );

    expect(screen.getByRole("region", { name: "Daily Sales Report" })).toBeInTheDocument();
    expect(screen.getByText("Report table")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Refresh" })).toBeInTheDocument();
    expect(onBackToFilters).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "Back to filters" }));
    expect(onBackToFilters).toHaveBeenCalledTimes(1);
  });

  test("disables Back to filters while report work is busy", () => {
    const onBackToFilters = jest.fn();
    render(
      <ReportViewShell title="Stock Report" busy onBackToFilters={onBackToFilters}>
        <div>Rows</div>
      </ReportViewShell>,
    );

    const shell = screen.getByRole("region", { name: "Stock Report" });
    const back = screen.getByRole("button", { name: "Back to filters" });
    expect(shell).toHaveAttribute("aria-busy", "true");
    expect(back).toBeDisabled();
    fireEvent.click(back);
    expect(onBackToFilters).not.toHaveBeenCalled();
  });
});
