import api from "../utils/apiBack";
import { getUoms } from "./ProductService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn() },
}));

describe("product UOM service", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test("requests the single POS-prefixed UOM route", async () => {
    const response = { data: [{ id: "uom-1", name: "Each" }] };
    api.get.mockResolvedValue({ data: response });

    await expect(getUoms({ q: "each" })).resolves.toEqual(response);

    expect(api.get).toHaveBeenCalledWith("/api/pos/uoms", {
      params: { q: "each" },
      headers: { "X-Company-Domain": window.location.hostname },
    });
  });
});
