# Tec Fattura24 Connector

Tec Fattura24 Connector is a PrestaShop module by Tecnoacquisti.com® that sends orders to Fattura24 when one of the configured document rules matches the reached order status.

The module is built for PrestaShop 1.7.8.11, 8.x, 9.0 and 9.1. It does not send documents on order creation and it does not depend on the PrestaShop paid-state flag: the merchant chooses which document types are enabled and which order status triggers each one.

## Main Features

- Status-based Fattura24 document creation through the `actionOrderStatusUpdate` hook.
- Separate document rules to map each Fattura24 document type to its own PrestaShop order status.
- Configurable Fattura24 API key with masked storage in the back office.
- API key test from the module configuration page.
- Supported Fattura24 document types: customer order (`C`), electronic invoice (`FE`), invoice (`I`), forced invoice (`I-force`) and receipt (`R`).
- Optional Fattura24 numerator ID and template ID.
- Optional Fattura24 email sending.
- Optional paid flag in the generated document payment row.
- Optional zero-total order handling.
- HTTPS POST integration with Fattura24 `TestKey` and `SaveDocument`.
- Idempotent Fattura24 `idRequest` built from document type, order ID and shop ID.
- Dedicated document log table with status, attempts, Fattura24 document ID, API response and error message.
- Manual send or retry action from the PrestaShop back-office order page.
- MySQL named lock to avoid concurrent sends for the same order and document type.
- Invoice data read from the PrestaShop invoice address.
- ArteInvoice integration for SDI and PEC fields when `address.sdi` and `address.pec` are available.
- Optional debug logging through `PrestaShopLogger`.

## Requirements

- PrestaShop 1.7.8.11 or later.
- PHP cURL extension enabled.
- A valid Fattura24 API key.
- At least one enabled document rule with a configured PrestaShop order status.
- Invoice address data suitable for the document type selected in Fattura24.

## Installation

Install the module through the PrestaShop back office or mount it in the local development containers with the workspace module script.

After installation, the module:

- creates the `tecfattura24_document` database table;
- registers `actionOrderStatusUpdate`;
- registers `displayAdminOrder` and `displayAdminOrderSideBottom` for the order detail panel;
- creates default global configuration values.

## Configuration

Open the module configuration page and set the common API settings first:

| Field | Purpose |
|---|---|
| API key | Fattura24 API key. The stored value is displayed masked; leave it unchanged to preserve the existing key. |
| HTTP timeout | API request timeout in seconds. Values outside 5-120 seconds are normalized to 60. |
| Debug log | Writes debug entries to `PrestaShopLogger` when enabled. |

Then configure the document rules. Each supported document type has its own row:

| Field | Purpose |
|---|---|
| Enabled | Enables automatic and manual send actions for that document type. |
| Trigger order status | The PrestaShop status that triggers creation of that document type. |
| Document type | Fattura24 document type: `C`, `FE`, `I`, `I-force` or `R`. |
| Numerator ID | Optional Fattura24 numerator ID. Leave empty to use the Fattura24 default. |
| Template ID | Optional Fattura24 template ID. Leave empty to use the Fattura24 default. |
| Shop code | Optional short code used by custom document numbering. When empty, the module uses `SHOP` plus the PrestaShop shop ID. |
| Custom number | Sends a custom Fattura24 `<Number>` value. Enabled by default for customer orders (`C`) and disabled by default for fiscal documents. |
| Number format | Format used to build the custom document number. Default for customer orders: `{order_id}-{shop_code}-{year}`. |
| Send email from Fattura24 | Sends the document email from Fattura24 when enabled. |
| Mark document as paid | Writes `Paid=true` in the Fattura24 payment row when enabled. |
| Allow zero-total orders | Allows zero-total orders to be sent. Disabled by default. |

Use the `Test API key` button before enabling production document rules.

Free-text document rule fields are validated before saving:

- `Numerator ID` and `Template ID`: digits only, up to 16 characters.
- `Shop code`: letters, numbers, underscore and hyphen only, up to 8 characters.
- `Number format`: letters, numbers, `_`, `-`, `/`, `.`, braces and supported tokens only.
- generated `Number`: maximum 20 characters before the API call.

## FAQ

### What does Document type mean?

`Document type` is the Fattura24 document type sent in the XML node for a specific rule:

```xml
<DocumentType>...</DocumentType>
```

Available values:

| Value | Meaning | Typical use |
|---|---|---|
| `FE` | Electronic invoice | Italian electronic invoicing with SDI/PEC. |
| `I` | Invoice | Standard Fattura24 invoice. |
| `I-force` | Forced invoice | Fattura24 forced invoice flow, only when that behavior is required. |
| `R` | Receipt | Receipt/corrispettivo flow. |
| `C` | Customer order | Fattura24 customer order flow. Use this when the expected result is an entry under Fattura24 customer orders instead of a fiscal document. |

For `C`, the module sends a customer-order payload: it includes the order
number and totals, but it does not send fiscal payment nodes such as
`FePaymentCode`, `Payments` or `IdNumerator`.

Customer orders use Fattura24 `Number` instead of `IdNumerator`. By default the
number is built as `{order_id}-{shop_code}-{year}`, for example
`24-SHOP1-2026`. This keeps customer orders identifiable across multi-shop or
multiple e-commerce installations without relying on fiscal document
numbering.

The configured document type is also part of the module log uniqueness rule:
one row is tracked for each order, shop and document type. Changing the document
type after sending an order can create a separate tracked row for the same order.

### What are Numerator ID and Template ID for?

Both fields are optional. When they are empty, the module does not send them and
Fattura24 uses the default settings configured in the account.

`Numerator ID` is sent as:

```xml
<IdNumerator>...</IdNumerator>
```

Use it only when a specific Fattura24 numerator or document series must be used,
for example a dedicated e-commerce sequence.

For customer orders (`C`), the module does not send `IdNumerator`. Use `Shop
code`, `Custom number` and `Number format` to distinguish order numbering by
year, shop or e-commerce source.

`Template ID` is sent as:

```xml
<IdTemplate>...</IdTemplate>
```

Use it only when a specific Fattura24 document layout/template must be used.

For a first test, a conservative setup is:

- `Document type`: `C` when testing customer orders, or `R` when testing receipt creation
- `Numerator ID`: empty
- `Template ID`: empty
- `Send email from Fattura24`: disabled
- `Mark document as paid`: disabled unless the test order should be marked as paid
- `Allow zero-total orders`: disabled

### What does Send email from Fattura24 do?

When enabled, the module sends `SendEmail=true` in the document XML. This asks
Fattura24 to email the generated document to the customer.

Keep it disabled during tests if real customer emails must not be sent.

### What does Mark document as paid do?

When enabled, the module writes `Paid=true` in the generated Fattura24 payment
row. This marks the document as paid in Fattura24.

Enable it when the selected PrestaShop trigger status means the order payment is
confirmed. Leave it disabled when testing document creation without changing the
payment status in Fattura24.

### What does Allow zero-total orders do?

When disabled, zero-total orders are not sent to Fattura24. The module records an
error in the order panel instead.

Enable it only when zero-total orders, such as fully discounted orders or gifts,
must still create a Fattura24 document.

### What is HTTP timeout?

`HTTP timeout` is the maximum number of seconds the module waits for Fattura24
responses to `TestKey` and `SaveDocument` requests.

The default is `60`. Values below `5` or above `120` are normalized to `60`.
Keep `60` for normal tests and production unless real API timeouts require a
different value.

### What is Debug log?

When enabled, the module writes high-level diagnostic messages to
`PrestaShopLogger`, for example when it starts sending an order with a specific
Fattura24 `idRequest`.

Keep it disabled in normal use. Enable it temporarily while testing or diagnosing
send problems. Do not add logs that expose API keys, full XML payloads or other
sensitive data.

## Order Flow

1. The order changes status in PrestaShop.
2. The module finds all enabled document rules whose trigger status matches the new order status.
3. For each matching rule, zero-total orders are skipped unless that rule explicitly allows them.
4. The module acquires a MySQL named lock for the order, shop and document type.
5. The module creates or resets a pending row in `tecfattura24_document`.
6. The Fattura24 XML document is generated from the invoice address, customer, order lines, discounts, shipping and payment data.
7. The module calls Fattura24 `SaveDocument`.
8. When Fattura24 returns a document ID, the row is marked as `sent`.
9. On failure, the row is marked as `error` and can be retried manually from the order page.

If a document was already sent for the same order, shop and document type, automatic processing does not send it again. If a previous automatic send failed, the module requires a manual retry from the order page.

When Fattura24 reports that the document number already exists, the module treats
the result as already created and marks the local row as `sent`.

## Custom Numbering

Custom numbering sends the Fattura24 `Number` node. It is enabled by default for
customer orders (`C`) because they are non-fiscal documents and often need a
clear e-commerce reference.

Supported format tokens:

| Token | Value |
|---|---|
| `{year}` | Year from the PrestaShop order date. |
| `{shop_id}` | PrestaShop shop ID. |
| `{shop_code}` | Rule shop code, or `SHOP` plus the shop ID when empty. |
| `{order_id}` | PrestaShop order ID. |
| `{order_reference}` | PrestaShop order reference. |
| `{document_type}` | Fattura24 document type. |

Fattura24 does not document a maximum length for the `Number` node in the
public `SaveDocument` page. The module applies a conservative 20-character
limit before calling the API. If the generated number is longer, the send is
blocked locally and the order panel shows the error.

For fiscal documents (`FE`, `I`, `I-force`, `R`), keep `Custom number` disabled
unless there is a deliberate reason to override Fattura24 numbering. Prefer
Fattura24 `IdNumerator` for fiscal document series.

## Fattura24 Data Mapping

The generated XML includes:

- currency;
- customer name, address, postcode, city, province and country;
- fiscal code from `Address::dni` when available;
- VAT number from `Address::vat_number`, normalized by removing the country prefix when present;
- customer email;
- payment method name and description;
- electronic invoice payment code guessed from the PrestaShop payment label;
- order VAT amount and total;
- product rows with quantity, tax-excluded price and VAT rate;
- discount rows as negative lines;
- shipping row when shipping has a positive tax-excluded amount;
- order reference in footnotes and object;
- selected Fattura24 document type;
- optional Fattura24 numerator and template IDs.
- optional custom Fattura24 document number.

For Italian invoice addresses, the destination code is read from `address.sdi` when available and falls back to `0000000`. For non-Italian invoice addresses, the destination code is `XXXXXXX`. PEC is read from `address.pec` when available.

## Back-Office Order Panel

The order detail page displays:

- enabled document rules and their trigger status IDs;
- document history rows for the order;
- module document status for each tracked document type;
- Fattura24 request ID;
- Fattura24 document ID when available;
- Fattura24 API response when available;
- error message when available;
- attempt count;
- last update date;
- `Send or retry` action for each enabled document rule.

The retry action uses an employee-bound token and forces a new `SaveDocument` attempt for the selected configured document type.

## Database

The module owns one table: `<prefix>tecfattura24_document`.

| Column | Purpose |
|---|---|
| `id_tecfattura24_document` | Primary key. |
| `id_order` | PrestaShop order ID. |
| `id_shop` | Shop ID. |
| `id_order_state` | Status that triggered or retried the send. |
| `document_type` | Fattura24 document type. |
| `id_request` | Fattura24 idempotency request ID. |
| `doc_id` | Fattura24 document ID returned by the API. |
| `status` | `pending`, `sent` or `error`. |
| `api_response` | Raw Fattura24 API response for successful sends and failed responses that do not contain a document ID. |
| `error_message` | Last error message. |
| `attempts` | Number of send attempts. |
| `date_add` | Creation date. |
| `date_upd` | Last update date. |

The table has a unique key on `id_order`, `document_type` and `id_shop`.

## Configuration Keys

| Key | Default | Purpose |
|---|---:|---|
| `TECFATTURA24_API_KEY` | empty | Fattura24 API key. |
| `TECFATTURA24_DOCUMENT_RULES` | empty | JSON document rules keyed by Fattura24 document type. |
| `TECFATTURA24_TRIGGER_STATE` | `0` | Legacy fallback order status used only when no document rules are saved. |
| `TECFATTURA24_DOCUMENT_TYPE` | `FE` | Legacy fallback document type used only when no document rules are saved. |
| `TECFATTURA24_SEND_EMAIL` | `0` | Legacy fallback email flag used only when no document rules are saved. |
| `TECFATTURA24_PAID_STATUS` | `0` | Legacy fallback paid flag used only when no document rules are saved. |
| `TECFATTURA24_ALLOW_ZERO` | `0` | Legacy fallback zero-total flag used only when no document rules are saved. |
| `TECFATTURA24_ID_NUMERATOR` | empty | Legacy fallback Fattura24 numerator ID used only when no document rules are saved. |
| `TECFATTURA24_ID_TEMPLATE` | empty | Legacy fallback Fattura24 template ID used only when no document rules are saved. |
| `TECFATTURA24_TIMEOUT` | `60` | Fattura24 API timeout in seconds. |
| `TECFATTURA24_DEBUG` | `0` | Enables debug logging. |
| `TECFATTURA24_TEST_KEY` | empty | Last API key test timestamp and HTTP status. |

## Fattura24 API Rules

Before using the module in production, review the Fattura24 API and e-commerce regulations:

- https://www.fattura24.com/documentazione-legale/regolamento-api/
- https://www.fattura24.com/documentazione-legale/regolamento-ecommerce/

The merchant remains responsible for checking invoice data, fiscal correctness, Fattura24 document status and SDI outcomes.

## Development Notes

- Keep the module compatible with PrestaShop 1.7.8.11, 8.x and 9.x.
- Keep code paths legacy-safe unless a future modern path is explicitly guarded by `_PS_VERSION_`.
- Do not expose the Fattura24 API key in JavaScript, logs, URLs, templates or diagnostics.
- Keep HTML in Smarty templates, not in PHP strings.
- Keep public-facing module text in English; Italian translations should be added through the translation system.
