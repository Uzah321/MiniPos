import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import EnterpriseFilterBar from "./EnterpriseFilterBar";
import EnterpriseFormModal from "./EnterpriseFormModal";
import EnterpriseRowActionMenu from "./EnterpriseRowActionMenu";

describe("enterprise UI system", () => {
  test("form modals use the shared large centered shell without filler copy", () => {
    render(
      <EnterpriseFormModal open title="Add Shop" onClose={jest.fn()}>
        <div>Form content</div>
      </EnterpriseFormModal>,
    );

    const dialog = screen.getByRole("dialog", { name: "Add Shop" });
    expect(dialog).toHaveClass("sx-form-modal-backdrop");
    expect(dialog.querySelector(".sx-form-modal-window")).toHaveStyle("--sx-form-modal-width: 1180px");
    expect(screen.queryByText("Create or update the selected record")).not.toBeInTheDocument();
  });

  test("filter bars keep fields, search and actions in one shared structure", () => {
    const changeSearch = jest.fn();
    const submitSearch = jest.fn();
    render(
      <EnterpriseFilterBar
        searchValue="pizza"
        onSearchChange={changeSearch}
        onSearch={submitSearch}
        actions={<button type="button">Add</button>}
      >
        <label>Brand<select aria-label="Brand"><option>All</option></select></label>
      </EnterpriseFilterBar>,
    );

    fireEvent.change(screen.getByRole("searchbox", { name: "Search records" }), { target: { value: "chicken" } });
    expect(changeSearch).toHaveBeenCalledWith("chicken");
    fireEvent.submit(screen.getByRole("search"));
    expect(submitSearch).toHaveBeenCalledTimes(1);
    expect(screen.getByRole("button", { name: "Add" })).toBeInTheDocument();
  });

  test("row commands are hidden behind one accessible vertical-dot menu", () => {
    const edit = jest.fn();
    render(
      <EnterpriseRowActionMenu
        label="More actions for Main Till"
        actions={[{ label: "Edit till", onClick: edit }, { label: "Delete till", danger: true }]}
      />,
    );

    const trigger = screen.getByRole("button", { name: "More actions for Main Till" });
    expect(trigger).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("menuitem", { name: "Edit till" })).not.toBeInTheDocument();

    fireEvent.click(trigger);
    expect(trigger).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(screen.getByRole("menuitem", { name: "Edit till" }));
    expect(edit).toHaveBeenCalledTimes(1);
  });
});
