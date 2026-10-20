import React from "react";
import { render } from "@testing-library/react";
import EnterpriseHierarchyManagementModal, {
  extractHierarchyCollection,
  extractPagination,
} from "./EnterpriseHierarchyManagementModal";

describe("enterprise hierarchy management", () => {
  test("extracts Laravel paginator collections", () => {
    expect(extractHierarchyCollection({ data: { current_page: 1, data: [{ id: 1 }] } }, "country"))
      .toEqual([{ id: 1 }]);
    expect(extractHierarchyCollection({ brands: { data: [{ id: 2 }] } }, "brand"))
      .toEqual([{ id: 2 }]);
    expect(extractHierarchyCollection({ data: { menu_items: [{ id: 3 }] } }, "menu"))
      .toEqual([{ id: 3 }]);
  });

  test("preserves paginator state so large estates are navigable", () => {
    expect(extractPagination({ data: { current_page: 2, last_page: 9, total: 812, data: [] } }))
      .toEqual({ page: 2, lastPage: 9, total: 812 });
  });

  test("does not mount the full-screen workspace while closed", () => {
    const { container } = render(
      <EnterpriseHierarchyManagementModal open={false} onClose={jest.fn()} isPlatformAdmin />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});

