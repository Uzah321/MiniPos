import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import EnterpriseTableSystem from "./EnterpriseTableSystem";

describe("EnterpriseTableSystem", () => {
  test("turns a final actions cell into an accessible kebab menu trigger", async () => {
    render(
      <>
        <EnterpriseTableSystem />
        <table>
          <thead>
            <tr><th>Product</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>Espresso</td>
              <td><div><button type="button">Edit</button><button type="button">Delete</button></div></td>
            </tr>
          </tbody>
        </table>
      </>,
    );

    const actionCell = await screen.findByRole("button", { name: "Row options" });
    expect(actionCell).toHaveAttribute("aria-expanded", "false");

    fireEvent.click(actionCell);
    expect(actionCell).toHaveAttribute("aria-expanded", "true");
    expect(actionCell).toHaveClass("sx-actions-open");

    fireEvent.click(screen.getByRole("button", { name: "Edit" }));
    await waitFor(() => expect(actionCell).toHaveAttribute("aria-expanded", "false"));
  });

  test("leaves tables with an explicit row menu under component control", async () => {
    render(
      <>
        <EnterpriseTableSystem />
        <table>
          <thead>
            <tr><th>Product</th><th aria-label="Actions">⋮</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>Latte</td>
              <td><div className="action-menu-wrap"><button type="button" aria-label="Actions for Latte">⋮</button></div></td>
            </tr>
          </tbody>
        </table>
      </>,
    );

    expect(screen.getByRole("button", { name: "Actions for Latte" })).toBeInTheDocument();
    const [, explicitActionCell] = screen.getAllByRole("cell");
    await waitFor(() => expect(explicitActionCell).not.toHaveClass("sx-table-actions"));
  });

  test("does not collapse a final data-entry column that has a helper button", async () => {
    render(
      <>
        <EnterpriseTableSystem />
        <table>
          <thead>
            <tr><th>Payment</th><th>Counted</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>Card</td>
              <td>
                <input type="number" aria-label="CARD USD counted amount" defaultValue="0" />
                <button type="button">Open keyboard</button>
              </td>
            </tr>
          </tbody>
        </table>
      </>,
    );

    const countedInput = screen.getByLabelText("CARD USD counted amount");
    const countedCell = countedInput.closest("td");
    const countedHeader = countedCell?.closest("table")?.tHead?.rows?.[0]?.cells?.[1];

    await waitFor(() => expect(countedCell).not.toHaveClass("sx-table-actions"));
    expect(countedHeader).not.toHaveClass("sx-table-actions-head");
    expect(countedHeader).toHaveTextContent("Counted");
    expect(screen.getByRole("button", { name: "Open keyboard" })).toBeInTheDocument();
  });
});
