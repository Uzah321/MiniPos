import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import EnterpriseConfirmModal from "./EnterpriseConfirmModal";

describe("EnterpriseConfirmModal", () => {
  test("exposes explicit confirm and cancel actions", () => {
    const onConfirm = jest.fn();
    const onClose = jest.fn();
    render(
      <EnterpriseConfirmModal
        open
        title="Delete Supplier"
        message="Delete Pizza Flour?"
        confirmLabel="Delete supplier"
        onConfirm={onConfirm}
        onClose={onClose}
      />,
    );

    const dialog = screen.getByRole("dialog", { name: "Delete Supplier" });
    expect(dialog).toHaveTextContent("Delete Pizza Flour?");
    expect(dialog.querySelector(".sx-compact-dialog-window")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Delete supplier" }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
