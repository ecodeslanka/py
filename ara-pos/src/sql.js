// Supplier balance = opening + all goods received - cleared payments - cleared stand-alone cheques
// (cheques that belong to a payment are already counted through that payment)
const SUP_PAID = `COALESCE((SELECT SUM(amount) FROM supplier_payments sp WHERE sp.supplier_id=s.id AND sp.cleared=1),0)
  + COALESCE((SELECT SUM(amount) FROM supplier_cheques sc WHERE sc.supplier_id=s.id AND sc.status='cleared' AND sc.payment_id IS NULL),0)`;
const SUP_BAL = `s.opening_balance + COALESCE((SELECT SUM(total) FROM goods_receipts g WHERE g.supplier_id=s.id),0) - (${SUP_PAID})`;
module.exports = { SUP_PAID, SUP_BAL };
