import fs from "fs";
import path from "path";

const read = (file) => fs.readFileSync(path.join(__dirname, file), "utf8");
const adminLayout = fs.readFileSync(
  path.join(__dirname, "..", "..", "AdminLayOut.js"),
  "utf8",
);

describe("Menu item prep and modifier workspaces", () => {
  const prep = read("MenuItemPrepMessageConfigurationModal.js");
  const modifiers = read("MenuItemModifierConfigurationModal.js");

  test.each([
    ["prep messages", prep],
    ["modifiers", modifiers],
  ])("keeps the %s workspace height-managed and scrollable", (_name, source) => {
    expect(source).toContain("h-full min-h-0 overflow-hidden");
    expect(source).not.toContain("h-[calc(100vh-86px)]");
    expect(source).toContain("min-h-0 flex-1 overflow-auto");
    expect(source).toContain("sticky top-0 z-10");
    expect(source).toMatch(/min-w-\[(720|820)px\]/);
  });

  test.each([
    ["prep messages", prep],
    ["modifiers", modifiers],
  ])("gives the %s register a roomy two-column workspace", (_name, source) => {
    expect(source).toContain("Menu Item Navigation");
    expect(source).toContain("row-span-2");
    expect(source).toContain("col-start-2 row-start-1");
    expect(source).toContain("col-start-2 row-start-2");
    expect(source).toContain("New and edit forms always open in a centered modal");
    expect(source).not.toMatch(/grid-cols-\[300px_minmax\((420|460)px,1fr\)_(360|400)px\]/);
  });

  test.each([
    ["prep messages", prep],
    ["modifiers", modifiers],
  ])("uses vertical-dot row actions and centered confirmations for %s", (_name, source) => {
    expect(source).toContain("<RowActionMenu");
    expect(source).toContain("<ConfirmActionModal");
    expect(source).toContain("<EnterpriseFormModal");
    expect(source).toContain("aria-haspopup=\"menu\"");
    expect(source).not.toContain("window.confirm");
  });

  test.each([
    ["prep messages", prep],
    ["modifiers", modifiers],
  ])("protects dirty assignments and refreshes current assignments for %s", (_name, source) => {
    expect(source).toContain("const reloadWorkspace = async () =>");
    expect(source).toContain("await loadProductAssignments(selectedProductId)");
    expect(source).toContain("const requestWorkspaceRefresh = () =>");
    expect(source).toContain("const requestWorkspaceClose = () =>");
    expect(source).toContain('type: "refresh-workspace"');
    expect(source).toContain('type: "close-workspace"');
    expect(source).toContain("onClose={requestWorkspaceClose}");
  });

  test("guards assignment requests and scopes master data to the selected branch", () => {
    expect(prep).toContain("assignmentRequestRef");
    expect(prep).toContain("requestId !== assignmentRequestRef.current");
    expect(prep).toMatch(/getPrepMessages\(\{[\s\S]*?branch_id: branchId \|\| undefined/);

    expect(modifiers).toContain("assignmentRequestRef");
    expect(modifiers).toContain("requestId !== assignmentRequestRef.current");
    expect(modifiers).toMatch(/getModifiers\(\{[\s\S]*?branch_id: branchId \|\| undefined/);
    expect(prep).toMatch(/getFinishedGoodMenuItemsForConfiguration\(\{[\s\S]*?branch_id: branchId \|\| undefined,[\s\S]*?branch_only: true/);
    expect(modifiers).toMatch(/getFinishedGoodMenuItemsForConfiguration\(\{[\s\S]*?branch_id: branchId \|\| undefined,[\s\S]*?branch_only: true/);
  });

  test("does not leak generic input and button rules outside the menu workspaces", () => {
    expect(prep).toContain(".menu-prep-workspace .desk-input");
    expect(modifiers).toContain(".menu-modifier-workspace .desk-input");
    expect(prep).not.toMatch(/(?:^|[;}])\s*button,input,select,textarea/);
    expect(modifiers).not.toMatch(/(?:^|[;}])\s*button,input,select,textarea/);
  });

  test("keeps prep and modifier configuration inside Hospitality navigation and rendering", () => {
    const hospitalityStart = adminLayout.indexOf("const hospitalityOperationalNavigation");
    const retailSplit = adminLayout.indexOf("const retailSalesControlNavigation");
    const hospitalityNavigation = adminLayout.slice(hospitalityStart, retailSplit);
    const hospitalityRenderStart = adminLayout.indexOf("{isHospitality && (");
    const hospitalityRenderEnd = adminLayout.indexOf("{!isHospitality && qoutationOpen", hospitalityRenderStart);
    const hospitalityRendering = adminLayout.slice(hospitalityRenderStart, hospitalityRenderEnd);

    expect(hospitalityNavigation).toContain('modal: "prep-message-configuration"');
    expect(hospitalityNavigation).toContain('modal: "modifier-configuration"');
    expect(hospitalityRendering).toContain("<MenuItemPrepMessageConfigurationModal");
    expect(hospitalityRendering).toContain("<MenuItemModifierConfigurationModal");
    expect(adminLayout).toContain('"prep-message-configuration": () => openHospitalityOnly(');
    expect(adminLayout).toContain('"modifier-configuration": () => openHospitalityOnly(');
  });
});
