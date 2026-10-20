import React from "react";
import { render } from "@testing-library/react";
import AuthenticationAdministrationModal, {
  buildEnterpriseScopeTargets,
  extractAssignmentOptions,
  extractAdminPagination,
  parsePolicyJson,
  validatePolicySettings,
} from "./AuthenticationAdministrationModal";

describe("authentication administration", () => {
  test("builds safe, named scope targets across paginated hierarchy pages", () => {
    const pages = [
      {
        data: {
          country: { id: "country-1", name: "Zimbabwe", code: "ZW" },
          company: {
            id: "company-1",
            name: "Hospitality Group",
            code: "HG",
            brands: [{
              id: "brand-1",
              name: "Pizza Inn",
              code: "PI",
              complexes: [{
                id: "complex-1",
                name: "Harare CBD",
                shops: [{ id: "shop-1", name: "Samora Shop", code: "SAM", tills: [{ id: "till-1", name: "Counter 1", code: "T01" }] }],
              }],
            }],
          },
        },
      },
      {
        data: {
          country: { id: "country-1", name: "Zimbabwe", code: "ZW" },
          company: {
            id: "company-1",
            name: "Hospitality Group",
            code: "HG",
            brands: [{ id: "brand-1", name: "Pizza Inn", code: "PI", complexes: [] }],
          },
        },
      },
    ];

    const targets = buildEnterpriseScopeTargets(pages);
    expect(targets.country).toEqual([expect.objectContaining({ id: "country-1", label: "Zimbabwe (ZW)" })]);
    expect(targets.company).toHaveLength(1);
    expect(targets.brand).toHaveLength(1);
    expect(targets.shop[0]).toEqual(expect.objectContaining({ id: "shop-1", label: "Samora Shop (SAM)" }));
    expect(targets.till[0]).toEqual(expect.objectContaining({ id: "till-1", label: "Counter 1 (T01)" }));
  });

  test("uses assignment endpoint metadata for scope-safe user and role choices", () => {
    expect(extractAssignmentOptions({
      data: { data: [] },
      meta: {
        users: [{ id: "user-1", name: "Manager" }],
        roles: [{ id: "role-1", name: "Shop Manager" }],
      },
    })).toEqual({
      users: [{ id: "user-1", name: "Manager" }],
      roles: [{ id: "role-1", name: "Shop Manager" }],
    });
  });

  test("preserves server pagination for large assignment and policy registers", () => {
    expect(extractAdminPagination({
      data: { current_page: 3, last_page: 12, total: 1178, data: [] },
    })).toEqual({ page: 3, lastPage: 12, total: 1178 });
  });

  test("validates supported structured credential and session rules", () => {
    expect(validatePolicySettings("credential", {
      password: { minimum_length: 14, require_symbol: true },
      login_code: { minimum_length: 6, maximum_length: 10, numeric_only: true },
    }, { allowEmpty: false })).toBeTruthy();

    expect(validatePolicySettings("session", {
      pos: { lifetime_minutes: 720, idle_timeout_minutes: 30 },
      back_office: { lifetime_minutes: 480, idle_timeout_minutes: 20 },
    }, { allowEmpty: false })).toBeTruthy();
  });

  test("keeps Back Office password-only and rejects unknown JSON settings", () => {
    expect(() => parsePolicyJson("authentication", JSON.stringify({
      back_office: { login_mode: "login_code" },
    }))).toThrow("fixed to username_password");

    expect(() => parsePolicyJson("authentication", JSON.stringify({
      pos: { allow_anonymous: true },
    }))).toThrow("Unsupported authentication policy setting");

    expect(parsePolicyJson("authentication", JSON.stringify({
      pos: { login_mode: "both" },
      lockout: { maximum_attempts: 5, decay_seconds: 900 },
    }))).toEqual(expect.objectContaining({ pos: { login_mode: "both" } }));
  });

  test("does not mount the full-screen workspace while closed", () => {
    const { container } = render(
      <AuthenticationAdministrationModal
        open={false}
        onClose={jest.fn()}
        canManageAssignments
        canManagePolicies
      />,
    );
    expect(container).toBeEmptyDOMElement();
  });
});
