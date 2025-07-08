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

    public function __construct(
        FileFactory $fileFactory,
        CollectionFactory $orderCollectionFactory,
        DirectoryList $directoryList,
        RawFactory $resultRawFactory,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ProductCollectionFactory $productCollectionFactory,
        StoreManagerInterface $storeManager,
        ResourceConnection $resource
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
    }

    public function export($orderIds = [], $request = null)
    {
        try {
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
            // write headers
            fputcsv($fh, [
                'Order ID',
                'Store',
                'Order Date',
                'Customer Email',
                'Total',
                'Charged Shipping Cost',
                'VAT Charged Shipping Cost',
                'Ship to Country',
                'Refunded Amount',
                'Status',
                'Item ID',
                'Product ID',
                'Product Name',
                'SKU',
                'Price',
                'Quantity',
                'Row Total',
                'VAT',
                'Bizbloqs Group'
            ], ';', '"');

            // --- direct DB stream using PDO cursor ---
            $conn = $this->resource->getConnection();

            // find attribute_id for bizbloqs_group
            $eType  = $conn->fetchOne(
                "SELECT entity_type_id FROM {$conn->getTableName('eav_entity_type')}
                 WHERE entity_type_code='catalog_product'"
            );
            // 1) fetch both id and backend_type
            $attr = $conn->fetchRow(
                "SELECT attribute_id, backend_type
                   FROM {$conn->getTableName('eav_attribute')}
                  WHERE entity_type_id = ?
                    AND attribute_code    = 'bizbloqs_group'",
                $eType
            );
            $attrId      = (int)$attr['attribute_id'];
            $tableSuffix = $attr['backend_type'];   // e.g. 'varchar', 'int', etc.
            $valueTable  = $conn->getTableName("catalog_product_entity_{$tableSuffix}");

            $o = $conn->getTableName('sales_order');
            $i = $conn->getTableName('sales_order_item');
            $b = $conn->getTableName('catalog_product_entity_int');
            $a = $conn->getTableName('sales_order_address');

            $select = $conn->select()
                ->from(['o' => $o], [
                    'increment_id',
                    'store_name',
                    'created_at',
                    'customer_email',
                    'grand_total',
                    'shipping_amount',
                    'shipping_tax_amount',
                    'total_refunded',
                    'status'
                ])
                // join shipping address to get country_id
                ->joinLeft(
                    ['a' => $a],
                    "a.parent_id = o.entity_id AND a.address_type = 'shipping'",
                    ['ship_to_country' => 'country_id']
                )
                ->join(
                    ['i' => $i],
                    'i.order_id = o.entity_id',
                    [
                        'item_id',
                        'product_id',
                        'name',
                        'sku',
                        'price',
                        'qty_ordered',
                        'row_total',
                        'tax_amount'
                    ]
                )
                ->joinLeft(
                    ['b' => $valueTable],
                    "b.entity_id    = i.product_id
                     AND b.attribute_id = {$attrId}
                     AND b.store_id     = 0",
                    ['bizbloqs_group' => 'value']
                )
                ->order('o.entity_id');

            // apply entity_id filter if any
            if (!empty($orderIds)) {
                $select->where('o.entity_id IN(?)', $orderIds);
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
                if (isset($fdt, $tdt)) {
                    $select->where('o.created_at BETWEEN ? AND ?', [$fdt, $tdt]);
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

                fputcsv($fh, [
                    $row['increment_id'],
                    $row['store_name'],
                    $row['created_at'],
                    $row['customer_email'],
                    $row['grand_total'],
                    $row['shipping_amount'],
                    $row['shipping_tax_amount'],
                    $row['ship_to_country'],    // correct position
                    $row['total_refunded'],
                    $row['status'],
                    $row['item_id'],
                    $row['product_id'],
                    $row['name'],
                    $row['sku'],
                    $row['price'],
                    $row['qty_ordered'],
                    $row['row_total'],
                    $row['tax_amount'],
                    $row['bizbloqs_group'],
                ], ';', '"');
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
