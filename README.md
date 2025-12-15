# ExtendedExports for Magento 2

**ExtendedExports** adds a flexible and performance-friendly CSV export pipeline to Magento 2. It lets you blend core order data, selected product attributes, and values from any custom tables into a single export without touching Magento core code.

---

## ✨ Highlights

- **Configurable Data Sources** – Pick sales order columns, product attributes, and arbitrary tables directly from the admin UI.
- **Admin & CLI Triggers** – Run the export from the Orders grid mass action or the included console command.
- **Safe Streaming** – Streams rows via PDO cursor to keep memory usage predictable on large datasets.

---

## 🔧 Installation

```bash
composer require chammedinger/extendedexports
bin/magento module:enable CHammedinger_ExtendedExports
bin/magento setup:upgrade
bin/magento cache:clean config
# (production deployments)
bin/magento setup:di:compile
```

After installation, flush browser caches and re-open the Magento admin to load the new configuration UI.

---

## 🧭 Configuration Overview

Head to **Stores → Configuration → Sales → Extended Exports**. Three panels shape what ends up in your CSV:

| Panel | Purpose | Stored Under |
|-------|---------|--------------|
| **Extension Tables** | Join extra tables (e.g. custom order metadata) and expose a column from that table in the CSV. | `extendedexports/general/extension_tables` |
| **Product Attributes** | Append any EAV product attribute value for each order item. | `extendedexports/general/product_attributes` |
| **Order Attributes** | Append additional columns from the `sales_order` table. | `extendedexports/general/order_attributes` |

Each panel stores a serialized configuration that the export model reads at runtime.

### 1. Extension Tables Panel

Add as many rows as you need. Each row requires three inputs:

> Example: Join `custom_order_flags` with `order_id` (numeric) and export the `flag_value`. The exporter automatically joins on `sales_order.entity_id`.


### 2. Product Attributes Panel

Choose any product attributes to append for each order item. The dropdown lists the entire catalog attribute set (with frontend labels and codes). For each selected attribute the exporter:

- Resolves the attribute metadata (backend type, attribute ID, etc.).
- Joins store-specific and default attribute tables (`catalog_product_entity_*`).
- Outputs a header like `Manufacturer (manufacturer)` and populates it per order item.

> Store-specific attribute values are preferred; the exporter falls back to the global value when none are set for the order’s store.

### 3. Order Attributes Panel

Select additional columns from the `sales_order` table. Each column becomes a new CSV header (labelled `Column Name (column_name)`) and is fetched directly from the order record. Use this to expose custom columns added by other extensions or integrations.

---

## � Running Exports

### Mass Action in Admin

1. Open **Sales → Orders**.
2. Select one or more orders (or apply filters and choose “Select All”).
3. In the mass-actions dropdown, click **Extended Export**.
4. Magento streams the CSV to `storage/extendedexports/export/extended_orders_export.csv` inside the Magento root directory. Download it via SSH or mounted storage.

### Console Command

Use the CLI command for scheduled jobs or scripted integrations:

```bash
bin/magento chammedinger:extendedexports:export --order-ids=100000001,100000002
```

- Pass `--order-ids` with a comma-separated list to limit the export.
- Omit the option to export the orders referenced by mass-actions/filters (extend the command to plug in custom logic as needed).

Both entry points call the same export service (`Model\Export\ExtendedExport`), so configuration changes apply everywhere.

---

## 🧠 Under the Hood

- **Streaming Query:** Uses Zend DB to stream rows with `PDO::FETCH_ASSOC` so CSVs can scale to large order sets.
- **Dynamic Joins:** Order attributes, product attributes, and extension tables are all joined conditionally. Missing metadata logs a warning and writes empty values to keep the CSV structure stable.
- **Configuration Parsing:** Serialized data is deserialized with Magento’s serializer first, then falls back to PHP `unserialize` with strict options. Both JSON and legacy serialized formats are supported.
- **Column Safety:** Before selecting any column, the exporter checks `tableColumnExists`. If the column/table vanished, the CSV still renders with an empty column so downstream tools don’t break.
- **Timezone Handling:** Order timestamps (`created_at`) are converted from UTC to the configured store timezone so exported dates match what you see in the admin.
- **Tax Columns:** Each CSV row now includes both the order-level tax total and the per-item (line) tax amount for easier reconciliation.
- **Discount Columns:** Similar to tax, discount amounts are exported twice—once for the full order and once for each line item—so finance teams can cross-check totals versus per-item promotions.
- **Row Totals:** Each row carries a `Row Subtotal` (pre-discount) and a `Row Total (Incl. Discount)` so you can compare list price versus the final charged amount at a glance.

---

## 🤝 Contributing

1. Fork the repo and create a feature branch.
2. Follow Magento coding standards; keep changes backward compatible.
3. Add/update documentation and automated tests where practical.
4. Submit a PR with a clear description of the feature or fix.

Bug reports and feature requests are welcome through GitHub issues.

---

## 📄 License

Distributed under the MIT License. See the repository for the full license text.
