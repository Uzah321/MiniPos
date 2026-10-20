import api from "../utils/apiBack";
import {
  commitHospitalityWorkbook,
  downloadProductImportCsvTemplate,
  downloadProductImportExcelTemplate,
  getHospitalityImportScope,
  previewHospitalityWorkbook,
} from "./ProductImportService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn(), post: jest.fn() },
}));

describe("ServeNest product import template downloads", () => {
  let downloadedFilename;
  let clickSpy;

  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    downloadedFilename = "";
    api.get.mockResolvedValue({ data: new Blob(["template"]) });
    URL.createObjectURL = jest.fn(() => "blob:servenest-template");
    URL.revokeObjectURL = jest.fn();
    clickSpy = jest.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(function click() {
      downloadedFilename = this.download;
    });
  });

  afterEach(() => {
    jest.runOnlyPendingTimers();
    jest.useRealTimers();
    clickSpy.mockRestore();
  });

  test.each([
    ["xlsx", downloadProductImportExcelTemplate, "servenest_product_import_template.xlsx"],
    ["csv", downloadProductImportCsvTemplate, "servenest_product_import_template.csv"],
  ])("uses ServeNest identity for the %s template", async (format, download, filename) => {
    await download();

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/products/import/template",
      expect.objectContaining({ params: { format }, responseType: "blob" }),
    );
    expect(downloadedFilename).toBe(filename);
    expect(downloadedFilename).not.toMatch(/salexa/i);
  });

  test("previews a brand-scoped hospitality workbook as multipart data", async () => {
    api.post.mockResolvedValue({ data: { data: { can_commit: true } } });
    const file = new File(["workbook"], "Chicken Inn.xlsx", {
      type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    });

    await previewHospitalityWorkbook({
      file,
      brand_id: "brand-1",
      branch_id: "branch-1",
    });

    expect(api.post).toHaveBeenCalledTimes(1);
    const [url, form, config] = api.post.mock.calls[0];
    expect(url).toBe("/api/pos/hospitality-imports/preview");
    expect(config.headers["X-Branch-Id"]).toBe("branch-1");
    expect(form.get("file").name).toBe("Chicken Inn.xlsx");
    expect(form.get("brand_id")).toBe("brand-1");
    expect(form.get("branch_id")).toBe("branch-1");
  });

  test("loads the protected brand and shop import scope", async () => {
    const response = {
      data: {
        data: {
          company: { id: "company-1" },
          company_wide: false,
          requires_branch: true,
          brands: [],
        },
      },
    };
    api.get.mockResolvedValue(response);

    const scope = await getHospitalityImportScope();

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/hospitality-imports/scope",
      expect.objectContaining({ headers: expect.any(Object) }),
    );
    expect(scope).toEqual(response.data);
  });

  test("requires a brand before committing a hospitality workbook", async () => {
    const file = new File(["workbook"], "Pizza Inn.xlsx");

    await expect(commitHospitalityWorkbook({ file })).rejects.toThrow(/select a brand/i);
    expect(api.post).not.toHaveBeenCalled();
  });
});
