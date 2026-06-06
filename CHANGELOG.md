# Changelog

## [Unreleased]

- Split configuration into common Fattura24 API settings and per-document rules.
- Added status mapping per document type, allowing different Fattura24 documents to be generated from different PrestaShop order statuses.
- Added custom document numbering with shop/year/order tokens, enabled by default for non-fiscal customer orders.
- Added a local 20-character guard for generated Fattura24 document numbers.
- Added input validation for document rule numeric IDs, shop codes and number formats.
- Updated the back-office order panel to show all document rows and provide manual send or retry actions per enabled document rule.

## [1.0.0] - 2026-06-05

- Initial Tec Fattura24 Connector module.
- Added status-based Fattura24 document creation.
- Added API key test, masked API key field, order panel and manual retry.
- Added dedicated document log table and anti-concurrency lock.
