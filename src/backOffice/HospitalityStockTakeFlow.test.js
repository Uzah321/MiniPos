import fs from "fs";
import path from "path";

const read = (relativePath) => fs.readFileSync(path.join(__dirname, relativePath), "utf8");

describe("Hospitality stock-take navigation and modal separation", () => {
  test("Hospitality navigation exposes explicit Back and Front workflows without generic Retail Stock Take", () => {
    const source = read("AdminLayOut.js");
    const start = source.indexOf("const hospitalityOperationalNavigation");
    const end = source.indexOf("const retailSalesControlNavigation", start);
    const hospitalityNavigation = source.slice(start, end);

    expect(hospitalityNavigation).toContain('name: "Stock Takes & Reconciliation"');
    expect(hospitalityNavigation).toContain('name: "Back Stock Month-End Stock Take"');
    expect(hospitalityNavigation).toContain('name: "Front Stock Daily Count & Reconciliation"');
    expect(hospitalityNavigation).not.toContain('modal: "stock-take"');
    expect(source).toMatch(/!isHospitality\s*&&\s*\(\s*<StockTakeModal/);
  });

  test("Back Stock uses a centered operation modal and Front Stock is clearly identified", () => {
    const backStock = read("boh/monthend/BackStockMonthEndStockTakeModal.js");
    const frontStock = read("boh/inventory/DailyFrontStockClosingModal.js");

    expect(backStock).toContain('title="BACK STOCK MONTH-END STOCK TAKE"');
    expect(backStock).toContain("<EnterpriseFormModal");
    expect(backStock).not.toContain("<aside");
    expect(backStock).toContain('className="sx-enterprise-table');
    expect(frontStock).toContain('title="FRONT STOCK DAILY COUNT & RECONCILIATION"');
    expect(frontStock).toContain('stockArea] = useState("front")');
  });
});
