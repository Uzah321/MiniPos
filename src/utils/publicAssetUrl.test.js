import { publicAssetUrl } from "./publicAssetUrl";

describe("publicAssetUrl", () => {
  test("uses the API origin for server-owned asset paths", () => {
    expect(publicAssetUrl("assets/hierarchy/brands/pizza.png", "http://127.0.0.1:8003"))
      .toBe("http://127.0.0.1:8003/assets/hierarchy/brands/pizza.png");
  });

  test("preserves an installation path while removing a trailing API segment", () => {
    expect(publicAssetUrl("/assets/product-images/menu.webp", "http://localhost/ServeNestApi/api"))
      .toBe("http://localhost/ServeNestApi/assets/product-images/menu.webp");
  });

  test("does not rewrite explicit remote, data or preview URLs", () => {
    expect(publicAssetUrl("https://cdn.example.test/logo.png", "http://localhost/api"))
      .toBe("https://cdn.example.test/logo.png");
    expect(publicAssetUrl("blob:http://localhost/preview", "http://localhost/api"))
      .toBe("blob:http://localhost/preview");
    expect(publicAssetUrl("data:image/png;base64,AA==", "http://localhost/api"))
      .toBe("data:image/png;base64,AA==");
  });
});
