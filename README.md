# ExtendedExports for Magento 2

**ExtendedExports** is a Magento 2 extension that allows merchants to export custom order data to CSV with ease. Can be used as a basis for further development.

## 🚀 Features

- Export Magento orders with custom fields - Extend the code as needed
- Supports CSV format
- Easily extendable for custom export logic

## 📦 Installation

Install via Composer:

```bash
composer require chammedinger/extendedexports
bin/magento module:enable CHammedinger_ExtendedExports
bin/magento setup:upgrade
bin/magento cache:flush
