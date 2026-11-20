import { expect, it } from "vitest";
import { parseReceiptEdits } from "../src/receipts.js";

it("parses corrections using the Other Expenses fields and keeps cleared values", () => {
  expect(parseReceiptEdits("Category: office\nCurrency: php\nVAT: YES\nPayment source ID:\nNote: Cabinet: supplies")).toEqual({
    category: "office", currency: "PHP", vat: "yes", payment_source_id: null, note: "Cabinet: supplies",
  });
});

it("rejects unexpected or duplicate correction labels", () => {
  expect(() => parseReceiptEdits("Status: active")).toThrow();
  expect(() => parseReceiptEdits("Category: food\nCategory: office")).toThrow();
});
