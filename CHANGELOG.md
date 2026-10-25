# Changelog

## 0.0.2

- Fixed: reverting a suggestion on a product that had no value removes the value instead of leaving an empty (NULL) row.
- Tested on Magento 2.4.9 and PHP 8.5.

## 0.0.1
- Fill empty select, multiselect and boolean attributes from product text via TypeSafe (both-orders Choice for select and boolean, Noul per multiselect option).
- Per-attribute rules: mode off/shadow/review/auto (new rules start in shadow), threshold, instructions, extra rules, attribute sets, fill mode, "Treat No as Empty" for Yes/No.
- Values in store 0 or any store view count as filled ("Treat No as Empty" applies to store 0 only; a "No" answer over it is not suggested); configurable parents' super attributes are never enriched (also without Magento_ConfigurableProduct installed); products not visible individually are skipped unless enabled in config.
- Suggestions grid with approve, reject and revert; selections over 50 are approved/reverted in the background (`dmlab.catalog_enrich.suggestions`); auto-apply through Product Action.
- Attempt ledger: unchanged product data is never re-sent, including data TypeSafe refused; error backoff; `--force`. Rejected or reverted values are never re-suggested for that product and attribute.
- Admin: Attempts grid with "Ask again on next run"; Rules grid with "Label (code)", Status (active/off/skipped with reason/inactive without TypeSafe) and outcome counts; last run per source (CLI, cron, whole product-grid mass action) above Rules and Suggestions, plus the last suggestion queue (approved/reverted/refused) above Suggestions; a warning above all three grids while TypeSafe is not configured; Suggestions link SKU/name to the product.
- ACL `suggestions_apply` for approve/reject/revert and `attempts`; mass actions are hidden for roles without them.
- CLI run/calibrate/report; product-grid mass action via DB queue, refused while TypeSafe is not configured or no rule is active (stopped runs are re-queued hourly once configured); optional nightly cron capped by products that produced a request.
