import React from "react";
import { act, fireEvent, render, screen } from "@testing-library/react";
import EnterpriseConfirmHost, { requestEnterpriseConfirmation } from "./EnterpriseConfirmHost";

describe("EnterpriseConfirmHost", () => {
  test("resolves false when the user cancels", async () => {
    render(<EnterpriseConfirmHost />);

    let result;
    await act(async () => {
      result = requestEnterpriseConfirmation({ title: "Delete Record", message: "Delete it?" });
    });

    expect(screen.getByRole("dialog", { name: "Delete Record" })).toBeInTheDocument();
    fireEvent.click(screen.getByText("Cancel"));

    await expect(result).resolves.toBe(false);
  });

  test("resolves true when the user confirms", async () => {
    render(<EnterpriseConfirmHost />);

    let result;
    await act(async () => {
      result = requestEnterpriseConfirmation({
        title: "Delete Record",
        message: "Delete it?",
        confirmLabel: "Delete record",
      });
    });

    fireEvent.click(screen.getByText("Delete record"));

    await expect(result).resolves.toBe(true);
  });
});
