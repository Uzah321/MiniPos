import api from "../utils/apiBack";
import { convertCurrency } from "./CurrencyService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    post: jest.fn(),
  },
}));

describe("CurrencyService shared conversion", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.post.mockResolvedValue({ data: {} });
  });

  test.each([
    ["/front", "front-office"],
    ["/admin", "back-office"],
  ])("uses the active workspace identity from %s", async (path, expectedWorkspace) => {
    window.history.pushState({}, "", path);

    await convertCurrency({ from: "USD", to: "ZWG", amount: 5 });

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/currencies/convert",
      { from: "USD", to: "ZWG", amount: 5 },
      expect.objectContaining({ salexaWorkspace: expectedWorkspace }),
    );
  });
});
