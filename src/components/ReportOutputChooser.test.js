import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import ReportOutputChooser from "./ReportOutputChooser";

describe("ReportOutputChooser", () => {
  test("waits for an explicit output selection before calling a callback", () => {
    const onView = jest.fn();
    const onDownloadPdf = jest.fn();
    const onDownloadExcel = jest.fn();

    render(
      <ReportOutputChooser
        open
        reportTitle="Daily Sales Report"
        onView={onView}
        onDownloadPdf={onDownloadPdf}
        onDownloadExcel={onDownloadExcel}
      />,
    );

    expect(screen.getByRole("dialog", { name: "Choose report output" })).toBeInTheDocument();
    expect(onView).not.toHaveBeenCalled();
    expect(onDownloadPdf).not.toHaveBeenCalled();
    expect(onDownloadExcel).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: /View Report/i }));
    expect(onView).toHaveBeenCalledTimes(1);
    expect(onDownloadPdf).not.toHaveBeenCalled();
    expect(onDownloadExcel).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: /Download PDF/i }));
    fireEvent.click(screen.getByRole("button", { name: /Download Excel/i }));
    expect(onDownloadPdf).toHaveBeenCalledTimes(1);
    expect(onDownloadExcel).toHaveBeenCalledTimes(1);
  });

  test("renders unavailable and disabled destinations without invoking them", () => {
    const onView = jest.fn();
    const onDownloadPdf = jest.fn();
    const onDownloadExcel = jest.fn();

    render(
      <ReportOutputChooser
        open
        onView={onView}
        onDownloadPdf={onDownloadPdf}
        onDownloadExcel={onDownloadExcel}
        outputs={{
          pdf: { available: false, reason: "PDF export is not supported." },
          excel: { disabled: true, reason: "Choose a branch first." },
        }}
      />,
    );

    const pdf = screen.getByRole("button", { name: /Download PDF/i });
    const excel = screen.getByRole("button", { name: /Download Excel/i });
    expect(pdf).toBeDisabled();
    expect(excel).toBeDisabled();
    expect(screen.getByText("PDF export is not supported.")).toBeInTheDocument();
    expect(screen.getByText("Choose a branch first.")).toBeInTheDocument();

    fireEvent.click(pdf);
    fireEvent.click(excel);
    expect(onDownloadPdf).not.toHaveBeenCalled();
    expect(onDownloadExcel).not.toHaveBeenCalled();
    expect(onView).not.toHaveBeenCalled();
  });

  test("traps focus, closes with Escape, and restores the previous focus", async () => {
    const onClose = jest.fn();
    const trigger = document.createElement("button");
    trigger.textContent = "Generate";
    document.body.appendChild(trigger);
    trigger.focus();

    const { rerender } = render(
      <ReportOutputChooser
        open
        onClose={onClose}
        onView={() => {}}
        onDownloadPdf={() => {}}
        onDownloadExcel={() => {}}
      />,
    );

    const view = screen.getByRole("button", { name: /View Report/i });
    const close = screen.getByRole("button", { name: "Close report output chooser" });
    const cancel = screen.getByRole("button", { name: "Cancel" });
    await waitFor(() => expect(view).toHaveFocus());

    close.focus();
    fireEvent.keyDown(close, { key: "Tab", shiftKey: true });
    expect(cancel).toHaveFocus();

    fireEvent.keyDown(cancel, { key: "Tab" });
    expect(close).toHaveFocus();

    fireEvent.keyDown(close, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);

    rerender(<ReportOutputChooser open={false} onClose={onClose} />);
    await waitFor(() => expect(trigger).toHaveFocus());
    trigger.remove();
  });

  test("locks selection and dismissal while busy", () => {
    const onClose = jest.fn();
    const onView = jest.fn();

    render(
      <ReportOutputChooser
        open
        busy
        busyLabel="Downloading PDF…"
        onClose={onClose}
        onView={onView}
        onDownloadPdf={() => {}}
        onDownloadExcel={() => {}}
      />,
    );

    const dialog = screen.getByRole("dialog", { name: "Choose report output" });
    expect(dialog).toHaveAttribute("aria-busy", "true");
    expect(screen.getByRole("status")).toHaveTextContent("Downloading PDF…");
    expect(screen.getByRole("button", { name: /View Report/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Cancel" })).toBeDisabled();

    fireEvent.keyDown(dialog, { key: "Escape" });
    fireEvent.mouseDown(dialog);
    expect(onClose).not.toHaveBeenCalled();
    expect(onView).not.toHaveBeenCalled();
  });
});
