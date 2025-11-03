<?php

namespace CHammedinger\ExtendedExports\Model\Export;

use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\ScopeInterface;

class ExtendedExport
{
    protected $fileFactory;
    protected $orderCollectionFactory;
    protected $directoryList;
    protected $productCollectionFactory;
    protected $logger;
    protected $scopeConfig;
    protected $resultRawFactory;
    protected $storeManager;
    private $resource;
    private SerializerInterface $serializer;

    public function __construct(
        FileFactory $fileFactory,
        CollectionFactory $orderCollectionFactory,
        DirectoryList $directoryList,
        RawFactory $resultRawFactory,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ProductCollectionFactory $productCollectionFactory,
        StoreManagerInterface $storeManager,
        ResourceConnection $resource,
        SerializerInterface $serializer
    ) {
        $this->fileFactory             = $fileFactory;
        $this->orderCollectionFactory  = $orderCollectionFactory;
        $this->directoryList           = $directoryList;
        $this->resultRawFactory        = $resultRawFactory;
        $this->logger                  = $logger;
        $this->scopeConfig             = $scopeConfig;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager            = $storeManager;
        $this->resource                = $resource;
        $this->serializer              = $serializer;
    }

    public function export($orderIds = [], $request = null)
    {
        try {
            $customAttributes = [
                // 'bizbloqs_group',
            ];

            $orderAttributes = [
                // 'tracking_data',
                // 'gift_message_fee',
            ];

            $filters = [];

            // --- build orderIds from selected or filters ---
            $excludedOrderIds = [];
            if ($request->getParam('excluded') && $request->getParam('excluded') !== 'false') {
                $excludedOrderIds = $request->getParam('excluded');
            }

            if ($request->getParam('selected') && $request->getParam('selected') !== 'false') {
                $orderIds = array_merge($orderIds, $request->getParam('selected'));
            } elseif ($request->getParam('filters')) {
                $filters = $request->getParam('filters');
                if (isset($filters['entity_id'])) {
                    $orderIds = array_merge($orderIds, $filters['entity_id']);
                }
            }

            // --- prepare CSV file handle ---
            $fileName = 'extended_orders_export.csv';
            $folder   = $this->directoryList->getRoot() . '/storage/extendedexports/export/';
            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            $filePath = $folder . $fileName;
            $fh = fopen($filePath, 'w');
            fclose($fh);
            $fh = fopen($filePath, 'a');
            // setup db connection and dynamic metadata
            $conn = $this->resource->getConnection();
            $extensionTables = $this->resolveExtensionTableMappings($conn);
            $productAttributes = $this->resolveProductAttributes($conn);
            if (!empty($productAttributes)) {
                $customAttributes = array_map(static function (array $attribute): string {
                    return $attribute['code'];
                }, $productAttributes);
            }
            $orderAttributes = $this->resolveOrderAttributes($conn);
            $orderAttributeMap = [];
            foreach ($orderAttributes as $meta) {
                $column = $meta['column'] ?? '';
                if ($column === '' || isset($orderAttributeMap[$column])) {
                    continue;
                }
                $orderAttributeMap[$column] = $meta;
            }
            $orderAttributes = array_values($orderAttributeMap);
            $orderAttributeColumns = array_keys($orderAttributeMap);
            // write headers
            $baseHeaders = [
                'Order ID',
                'Store',
                'Order Date',
                'Customer Email',
                'Total',
                'Discount Amount',
                'Discount Description',
                'Discount Code',
                'Charged Shipping Cost',
                'VAT Charged Shipping Cost',
                'Ship to Country',
                'Refunded Amount',
                'Status',
                'Payment Method',
                'Item ID',
                'Product ID',
                'Product Name',
                'SKU',
                'Price',
                'Quantity',
                'Row Total',
                'VAT',
            ];
            $headers = $baseHeaders;
            // NEW: add order attribute headers first
            foreach ($orderAttributes as $attrMeta) {
                $headers[] = $attrMeta['label'];
            }
            // existing: add product attribute headers
            foreach ($productAttributes as $attributeMeta) {
                $label = $attributeMeta['label'] ?? $attributeMeta['code'];
                $headers[] = sprintf('%s (%s)', $label, $attributeMeta['code']);
            }
            foreach ($extensionTables as $extColumn) {
                $headers[] = $extColumn['header'];
            }
            fputcsv($fh, $headers, ';', '"');

            // 2) resolve product entity type and attribute meta once
            $eType = $conn->fetchOne(
                "SELECT entity_type_id FROM {$conn->getTableName('eav_entity_type')}
                 WHERE entity_type_code='catalog_product'"
            );

            // Sanitize and dedupe list
            $customAttributes = array_values(array_unique(array_filter($customAttributes)));

            $attributesInfo = [];
            if (!empty($customAttributes)) {
                // Use the select builder so IN (?) binds arrays correctly
                $attrSelect = $conn->select()
                    ->from($conn->getTableName('eav_attribute'), ['attribute_code', 'attribute_id', 'backend_type'])
                    ->where('entity_type_id = ?', $eType)
                    ->where('attribute_code IN (?)', $customAttributes);

                $attrRows = $conn->fetchAll($attrSelect);

                foreach ($attrRows as $r) {
                    $attributesInfo[$r['attribute_code']] = [
                        'id'    => (int)$r['attribute_id'],
                        'type'  => $r['backend_type'], // varchar|int|decimal|text|datetime|static
                        'table' => $r['backend_type'] === 'static'
                            ? $conn->getTableName('catalog_product_entity')
                            : $conn->getTableName('catalog_product_entity_' . $r['backend_type']),
                    ];
                }
            }

            $o = $conn->getTableName('sales_order');
            $i = $conn->getTableName('sales_order_item');
            $a = $conn->getTableName('sales_order_address');

            $select = $conn->select()
                ->from(['o' => $o], [
                    'increment_id',
                    'store_name',
                    'created_at',
                    'customer_email',
                    'grand_total',
                    'discount_amount',
                    'discount_description',
                    'coupon_code',
                    'shipping_amount',
                    'shipping_tax_amount',
                    'total_refunded',
                    'status'
                ])
                ->joinLeft(['a' => $a], "a.parent_id = o.entity_id AND a.address_type = 'shipping'", ['ship_to_country' => 'country_id'])
                ->joinLeft(['p' => $conn->getTableName('sales_order_payment')], 'p.parent_id = o.entity_id', ['payment_method' => 'method'])
                ->join(['i' => $i], 'i.order_id = o.entity_id', [
                    'item_id','product_id','name','sku','price','qty_ordered','row_total','tax_amount'
                ]);

            // NEW: add order-level attribute columns safely (only if column exists)
            $oTableResolved = $conn->getTableName('sales_order');
            foreach ($orderAttributeColumns as $columnName) {
                if ($conn->tableColumnExists($oTableResolved, $columnName)) {
                    $select->columns([$columnName => new \Zend_Db_Expr("o.`{$columnName}`")]);
                } else {
                    // keep CSV shape stable if column missing
                    $select->columns([$columnName => new \Zend_Db_Expr('NULL')]);
                }
            }

            foreach ($extensionTables as $extConfig) {
                $select->joinLeft(
                    [$extConfig['alias'] => $extConfig['table']],
                    sprintf(
                        '%s = %s',
                        $conn->quoteIdentifier([$extConfig['alias'], $extConfig['key_column']]),
                        $conn->quoteIdentifier(['o', $extConfig['join_field']])
                    ),
                    []
                );

                $select->columns([
                    $extConfig['column_alias'] => new \Zend_Db_Expr(
                        $conn->quoteIdentifier([$extConfig['alias'], $extConfig['value_column']])
                    )
                ]);
            }

            // 3) dynamic attribute joins with store fallback (store 0 -> order store)
            //    COALESCE(store.value, default.value) as {attrCode}
            $joinedCpe = false; // only join cpe once if any static attrs
            $idx = 0;
            foreach ($customAttributes as $attrCode) {
                if (!isset($attributesInfo[$attrCode])) {
                    // attribute not found: output NULL column
                    $select->columns([$attrCode => new \Zend_Db_Expr('NULL')]);
                    $idx++;
                    continue;
                }

                $meta = $attributesInfo[$attrCode];

                if ($meta['type'] === 'static') {
                    if (!$joinedCpe) {
                        $select->joinLeft(
                            ['cpe' => $meta['table']],
                            'cpe.entity_id = i.product_id',
                            []
                        );
                        $joinedCpe = true;
                    }
                    $select->columns([$attrCode => new \Zend_Db_Expr("cpe.`{$attrCode}`")]);
                } else {
                    $def = "attrd_{$idx}";
                    $sto = "attrs_{$idx}";
                    $tbl = $meta['table'];
                    $attId = (int)$meta['id'];

                    // default (store 0)
                    $select->joinLeft(
                        [$def => $tbl],
                        "{$def}.entity_id = i.product_id AND {$def}.attribute_id = {$attId} AND {$def}.store_id = 0",
                        []
                    );
                    // store-specific (order's store_id)
                    $select->joinLeft(
                        [$sto => $tbl],
                        "{$sto}.entity_id = i.product_id AND {$sto}.attribute_id = {$attId} AND {$sto}.store_id = o.store_id",
                        []
                    );
                    // prefer store value, fallback to default
                    $select->columns([
                        $attrCode => new \Zend_Db_Expr("COALESCE({$sto}.value, {$def}.value)")
                    ]);
                }

                $idx++;
            }

            $select->order('o.entity_id');

            // apply entity_id filter if any
            if (!empty($orderIds)) {
                $select->where('o.entity_id IN(?)', $orderIds);
            }

            // exclude specific order IDs
            if (!empty($excludedOrderIds)) {
                $select->where('o.entity_id NOT IN(?)', $excludedOrderIds);
            }

            // apply created_at filter
            if (!empty($filters['created_at'])) {
                $tz    = $this->scopeConfig->getValue('general/locale/timezone', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
                $from  = $filters['created_at']['from'] ?? null;
                $to    = $filters['created_at']['to'] ?? null;
                if ($from) {
                    $fdt = (new \DateTime($from . ' 00:00:00', new \DateTimeZone($tz)))
                        ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
                if ($to) {
                    $tdt = (new \DateTime($to . ' 23:59:59', new \DateTimeZone($tz)))
                        ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
                if (isset($fdt) && isset($tdt)) {
                    $select
                        ->where('o.created_at >= ?', $fdt)
                        ->where('o.created_at <= ?', $tdt);
                } elseif (isset($fdt)) {
                    $select->where('o.created_at >= ?', $fdt);
                } elseif (isset($tdt)) {
                    $select->where('o.created_at <= ?', $tdt);
                }
            }
            // apply store filters
            if (!empty($filters['purchase_point'])) {
                $select->where('o.store_id IN(?)', (array)$filters['purchase_point']);
            }
            if (!empty($filters['store_id'])) {
                $select->where('o.store_id IN(?)', (array)$filters['store_id']);
            }
            // apply grand totals
            if (!empty($filters['grand_total_base'])) {
                $g = $filters['grand_total_base'];
                if (isset($g['from'], $g['to'])) {
                    $select->where('o.base_grand_total BETWEEN ? AND ?', [$g['from'], $g['to']]);
                } elseif (isset($g['from'])) {
                    $select->where('o.base_grand_total >= ?', $g['from']);
                } elseif (isset($g['to'])) {
                    $select->where('o.base_grand_total <= ?', $g['to']);
                }
            }
            if (!empty($filters['grand_total_purchased'])) {
                $g = $filters['grand_total_purchased'];
                if (isset($g['from'], $g['to'])) {
                    $select->where('o.grand_total BETWEEN ? AND ?', [$g['from'], $g['to']]);
                } elseif (isset($g['from'])) {
                    $select->where('o.grand_total >= ?', $g['from']);
                } elseif (isset($g['to'])) {
                    $select->where('o.grand_total <= ?', $g['to']);
                }
            }
            // apply status
            if (!empty($filters['status'])) {
                $select->where('o.status IN(?)', (array)$filters['status']);
            }

            // stream rows
            $stmt = $conn->query($select);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                // convert UTC created_at to Europe/Amsterdam
                $dt = new \DateTime($row['created_at'], new \DateTimeZone('UTC'));
                $row['created_at'] = $dt
                    ->setTimezone(new \DateTimeZone('Europe/Amsterdam'))
                    ->format('Y-m-d H:i:s');

                $csvRow = [
                    $row['increment_id'],
                    $row['store_name'],
                    $row['created_at'],
                    $row['customer_email'],
                    $row['grand_total'],
                    $row['discount_amount'],
                    $row['discount_description'] ?? '',
                    $row['coupon_code'] ?? '',
                    $row['shipping_amount'],
                    $row['shipping_tax_amount'],
                    $row['ship_to_country'],
                    $row['total_refunded'],
                    $row['status'],
                    $row['payment_method'],
                    $row['item_id'],
                    $row['product_id'],
                    $row['name'],
                    $row['sku'],
                    $row['price'],
                    $row['qty_ordered'],
                    $row['row_total'],
                    $row['tax_amount'],
                ];
                // NEW: append order attribute values
                foreach ($orderAttributes as $attrMeta) {
                    $column = $attrMeta['column'];
                    $csvRow[] = $row[$column] ?? '';
                }
                // existing: append product attribute values
                foreach ($productAttributes as $attributeMeta) {
                    $code = $attributeMeta['code'];
                    $csvRow[] = $row[$code] ?? '';
                }
                foreach ($extensionTables as $extConfig) {
                    $csvRow[] = $row[$extConfig['column_alias']] ?? '';
                }
                fputcsv($fh, $csvRow, ';', '"');
            }
            fclose($fh);

            return [
                'status'   => 'success',
                'filename' => $fileName,
                'folder'   => $folder
            ];
        } catch (\Exception $e) {
            $this->logger->error('[ERROR] ExtendedExports - Export - ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Determine which product attributes should be exported based on configuration.
     */
    private function resolveProductAttributes(AdapterInterface $conn): array
    {
        $configValue = $this->scopeConfig->getValue(
            'extendedexports/general/product_attributes',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($configValue)) {
            return [];
        }

        $rows = $this->deserializeExtensionTableConfig($configValue);
        if (empty($rows) || !is_array($rows)) {
            return [];
        }

        $entityTypeId = $conn->fetchOne(
            "SELECT entity_type_id FROM {$conn->getTableName('eav_entity_type')} WHERE entity_type_code = 'catalog_product'"
        );

        if (!$entityTypeId) {
            return [];
        }

        $attributeTable = $conn->getTableName('eav_attribute');

        $attributes = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string)($row['attribute_code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }

            $attributeMeta = $conn->fetchRow(
                $conn->select()
                    ->from($attributeTable, ['attribute_code', 'frontend_label'])
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->where('attribute_code = ?', $code)
            );

            if (!$attributeMeta) {
                $this->logger->warning(
                    sprintf('ExtendedExports: Product attribute "%s" not found, skipping.', $code)
                );
                continue;
            }

            $label = $attributeMeta['frontend_label'] ?: $attributeMeta['attribute_code'];

            $attributes[] = [
                'code'  => $attributeMeta['attribute_code'],
                'label' => $label,
            ];

            $seen[$attributeMeta['attribute_code']] = true;
        }

        return $attributes;
    }

    private function resolveOrderAttributes(AdapterInterface $conn): array
    {
        $configValue = $this->scopeConfig->getValue(
            'extendedexports/general/order_attributes',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($configValue)) {
            return [];
        }

        $rows = $this->deserializeExtensionTableConfig($configValue);
        if (empty($rows) || !is_array($rows)) {
            return [];
        }

        $tableName = $conn->getTableName('sales_order');

        try {
            $columns = $conn->describeTable($tableName);
        } catch (\Throwable $exception) {
            $this->logger->warning('ExtendedExports: Unable to describe sales_order table.', ['exception' => $exception]);
            return [];
        }

        $attributes = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $column = trim((string)($row['column_name'] ?? ''));
            if ($column === '') {
                continue;
            }

            if (!isset($columns[$column])) {
                $this->logger->warning(
                    sprintf('ExtendedExports: Order attribute column "%s" not found on sales_order.', $column)
                );
                continue;
            }

            $label = ucwords(str_replace('_', ' ', $column));

            $attributes[] = [
                'column' => $column,
                'label'  => sprintf('%s (%s)', $label, $column),
            ];
        }

        return $attributes;
    }

    /**
     * Build join metadata for the extension table configuration.
     */
    private function resolveExtensionTableMappings(AdapterInterface $conn): array
    {
        $configValue = $this->scopeConfig->getValue(
            'extendedexports/general/extension_tables',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($configValue)) {
            return [];
        }

        $rows = $this->deserializeExtensionTableConfig($configValue);
        if (empty($rows) || !is_array($rows)) {
            return [];
        }

        $mappings = [];
        $index = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $tableId = trim((string)($row['table_name'] ?? ''));
            $keyColumn = trim((string)($row['key'] ?? ''));
            $valueColumn = trim((string)($row['export_value'] ?? ''));

            if ($tableId === '' || $keyColumn === '' || $valueColumn === '') {
                continue;
            }

            $tableName = $conn->getTableName($tableId);
            if (!$conn->isTableExists($tableName)) {
                $this->logger->warning(
                    sprintf('ExtendedExports: Table "%s" does not exist, skipping configuration entry.', $tableId)
                );
                continue;
            }

            try {
                $columns = $conn->describeTable($tableName);
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    sprintf('ExtendedExports: Unable to describe table "%s".', $tableName),
                    ['exception' => $exception]
                );
                continue;
            }

            if (!isset($columns[$keyColumn])) {
                $this->logger->warning(
                    sprintf('ExtendedExports: Key column "%s" missing on table "%s".', $keyColumn, $tableId)
                );
                continue;
            }

            if (!isset($columns[$valueColumn])) {
                $this->logger->warning(
                    sprintf('ExtendedExports: Export value column "%s" missing on table "%s".', $valueColumn, $tableId)
                );
                continue;
            }

            $alias = sprintf('ext_table_%d', $index);
            $columnAlias = sprintf('extension_table_value_%d', $index);

            $mappings[] = [
                'alias'        => $alias,
                'table'        => $tableName,
                'key_column'   => $keyColumn,
                'value_column' => $valueColumn,
                'column_alias' => $columnAlias,
                'join_field'   => $this->determineJoinField($columns[$keyColumn]),
                'header'       => $this->formatExtensionHeader($tableId, $valueColumn),
            ];

            $index++;
        }

        return $mappings;
    }

    private function deserializeExtensionTableConfig($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($value);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\InvalidArgumentException $exception) {
            // ignore and fall back to PHP unserialize
        } catch (\Throwable $exception) {
            // ignore and fall back to PHP unserialize
        }

        try {
            $decoded = unserialize($value, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'ExtendedExports: Unable to unserialize extension table configuration.',
                ['exception' => $exception]
            );
        }

        return [];
    }

    private function determineJoinField(array $columnMeta): string
    {
        $dataType = strtolower((string)($columnMeta['DATA_TYPE'] ?? ''));
        $numericTypes = ['int', 'integer', 'smallint', 'bigint', 'mediumint', 'tinyint', 'decimal', 'float', 'double'];

        return in_array($dataType, $numericTypes, true) ? 'entity_id' : 'increment_id';
    }

    private function formatExtensionHeader(string $tableId, string $column): string
    {
        $columnLabel = ucwords(str_replace('_', ' ', $column));

        return sprintf('%s (%s)', $columnLabel, $tableId);
    }

    /**
     * Convert a multi-dimensional, associative array to CSV data
     * @param  array $data the array of data
     * @return string       CSV text
     */
    function str_putcsv($data, $file = null)
    {
        # Generate CSV data from array
        if (!is_null($file)) {
            $fh = fopen($file, 'w');
        } else {
            $fh = fopen('php://temp', 'rw');    # don't create a file, attempt
            # to use memory instead
        }

        # write out the headers
        if (current($data))
            fputcsv($fh, array_keys(current($data)), ";", '"');
        else
            fputcsv($fh, array_keys([]), ";", '"');

        # write out the data
        foreach ($data as $row) {
            fputcsv($fh, $row, ";", '"');
        }
        if (is_null($file)) {
            rewind($fh);
            $csv = stream_get_contents($fh);
        }
        fclose($fh);

        if (!is_null($file)) {
            return $file;
        } else {
            return $csv;
        }
    }
}
