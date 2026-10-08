"""Run: python3 tools/stripe-treasurer/test_stripe_treasurer.py"""

import json
import unittest
from pathlib import Path

import stripe_treasurer as st

CATS = st.Categories(json.loads((Path(__file__).parent / "categories.json").read_text()))


def item(cats, amount, type_="line_item"):
    return {"type": type_, "product": "1", "name": "x", "amount": amount, "cats": cats}


def order(items, tax=0, refunds=()):
    return {"status": "wc-processing", "method": "stripe", "total": sum(i["amount"] for i in items) + tax,
            "tax": tax, "paid_gmt": "", "created_gmt": "", "items": list(items), "refunds": list(refunds)}


def txn(id_, type_, amount, fee=0, order_id=None, via_pi=False):
    meta = {"order_id": str(order_id)} if order_id else {}
    src = {"metadata": {} if via_pi else meta, "payment_intent": {"metadata": meta} if via_pi else None}
    return {"id": id_, "type": type_, "amount": amount, "fee": fee, "net": amount - fee, "source": src}


class SplitTest(unittest.TestCase):
    def test_split_is_exact(self):
        parts = st.split(1000, {"a": 1, "b": 1, "c": 1})
        self.assertEqual(sum(parts.values()), 1000)
        self.assertEqual(sorted(parts.values()), [333, 333, 334])

    def test_negative_split(self):
        self.assertEqual(st.split(-301, {"a": 2, "b": 1}), {"a": -201, "b": -100})


class CategoryTest(unittest.TestCase):
    def test_priority_picks_one_category(self):
        self.assertEqual(CATS.label_for(item(["Donation", "Fundraising"], 1)), "Donation")
        self.assertEqual(CATS.label_for(item(["Carnival", "Events"], 1)), "Carnival")

    def test_aliases_merge(self):
        self.assertEqual(CATS.label_for(item(["Membership Family"], 1)), "Membership")

    def test_no_category_and_fees(self):
        self.assertEqual(CATS.label_for(item([], 1)), "Uncategorized")
        self.assertEqual(CATS.label_for(item([], 1, "fee")), "Order fees")


class AllocatorTest(unittest.TestCase):
    def setUp(self):
        refund = {"id": 900, "order": 2, "total": -5000, "tax": 0,
                  "created_gmt": "", "items": [item(["Enrichment"], -5000)]}
        self.orders = {
            1: order([item(["Enrichment"], 8000), item(["Donation"], 2000)]),
            2: order([item(["Enrichment"], 5000), item(["Donation"], 5000)], refunds=[refund]),
        }
        self.a = st.Allocator(self.orders, CATS)

    def test_charge_and_fee_follow_order_lines(self):
        r = self.a.allocate(txn("ch_1", "charge", 10000, fee=320, order_id=1, via_pi=True))
        self.assertEqual(r["gross"], {"Enrichment": 8000, "Donation": 2000})
        self.assertEqual(r["fee"], {"Enrichment": -256, "Donation": -64})
        self.assertEqual(self.a.issues, [])

    def test_refund_follows_refunded_items(self):
        r = self.a.allocate(txn("re_1", "refund", -5000, order_id=2))
        self.assertEqual(r["gross"], {"Enrichment": -5000})

    def test_mismatches_are_reported(self):
        self.a.allocate(txn("ch_2", "charge", 9000, order_id=1))
        self.a.allocate(txn("ch_3", "charge", 500))
        self.a.allocate(txn("ch_4", "charge", 500, order_id=77))
        self.a.allocate(txn("re_2", "refund", -1234, order_id=1))
        kinds = [i["kind"] for i in self.a.issues]
        self.assertEqual(kinds, [
            "Payment differs from order total",
            "Stripe payment with no website order",
            "Order not found on website",
            "Stripe refund with no matching website refund",
        ])


if __name__ == "__main__":
    unittest.main()
