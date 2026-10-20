import api from "../utils/apiBack";
import { createStandaloneTillActivationCode } from "./TillService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    post: jest.fn(),
  },
}));

describe("standalone till activation service", () => {
  test("issues a one-time code without activating the Back Office browser", async () => {
    const response = {
      data: {
        data: {
          terminal_activation_code: "TERM-01234567-89ABCDEF-01234567-89ABCDEF",
          expires_at: "2026-10-08T12:10:00Z",
        },
      },
    };
    api.post.mockResolvedValue(response);

    await expect(createStandaloneTillActivationCode("till/id", {
      device_label: "Reception browser",
    })).resolves.toBe(response.data);

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/tills/till/id/activation-code",
      { device_label: "Reception browser" },
      { headers: { "X-Company-Domain": window.location.hostname } },
    );
  });
});
