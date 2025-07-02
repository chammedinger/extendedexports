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

    public function __construct(
        FileFactory $fileFactory,
        CollectionFactory $orderCollectionFactory,
        DirectoryList $directoryList,
        RawFactory $resultRawFactory,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ProductCollectionFactory $productCollectionFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->fileFactory             = $fileFactory;
        $this->orderCollectionFactory  = $orderCollectionFactory;
        $this->directoryList           = $directoryList;
        $this->resultRawFactory        = $resultRawFactory;
        $this->logger                  = $logger;
        $this->scopeConfig             = $scopeConfig;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager            = $storeManager;
    }

    public function export($orderIds = [], $request = null)
    {
        try {
            $excludedOrderIds = [];
            if ($request->getParam('excluded') && $request->getParam('excluded') != 'false') {
                $excludedOrderIds = $request->getParam('excluded');
            }

            $this->logger->info('Request parameters for export: ' . json_encode($request->getParams(), JSON_PRETTY_PRINT));

            if ($request->getParam('selected') && $request->getParam('selected') != 'false') {
                $orderIds = array_merge($orderIds, $request->getParam('selected'));
            } elseif ($request->getParam('filters')) {
                $filters = $request->getParam('filters');

                if (isset($filters['entity_id'])) {
                    $orderIds = array_merge($orderIds, $filters['entity_id']);
                }

                if (isset($filters['created_at'])) {
                    $from = isset($filters['created_at']['from']) ? date('Y-m-d 00:00:00', strtotime($filters['created_at']['from'])) : null;
                    $to = isset($filters['created_at']['to']) ? date('Y-m-d 23:59:59', strtotime($filters['created_at']['to'])) : null;

                    $timezone = $this->scopeConfig->getValue('general/locale/timezone', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

                    if ($from) {
                        $from = new \DateTime($from, new \DateTimeZone($timezone));
                        $from->setTimezone(new \DateTimeZone('UTC'));
                        $from = $from->format('Y-m-d H:i:s');
                    }

                    if ($to) {
                        $to = new \DateTime($to, new \DateTimeZone($timezone));
                        $to->setTimezone(new \DateTimeZone('UTC'));
                        $to = $to->format('Y-m-d H:i:s');
                    }

                    $this->logger->info('Date range for export: from ' . $from . ' to ' . $to);

                    $orderCollection = $this->orderCollectionFactory->create();
                    $orderCollection->addAttributeToSelect('*');
                    if ($from && $to) {
                        $orderCollection->addAttributeToFilter('created_at', ['from' => $from, 'to' => $to]);
                    } else if ($from) {
                        $orderCollection->addAttributeToFilter('created_at', ['from' => $from]);
                    } else if ($to) {
                        $orderCollection->addAttributeToFilter('created_at', ['to' => $to]);
                    }
                }

                // Add filter for purchase point
                if (isset($filters['purchase_point'])) {
                    // purchase_point is usually stored as 'store_id'
                    if (!isset($orderCollection)) {
                        $orderCollection = $this->orderCollectionFactory->create();
                        $orderCollection->addAttributeToSelect('*');
                    }
                    $orderCollection->addAttributeToFilter('store_id', ['in' => (array)$filters['purchase_point']]);
                }

                // Add filter for grand_total (base and purchased)
                if (isset($filters['grand_total_base'])) {
                    if (!isset($orderCollection)) {
                        $orderCollection = $this->orderCollectionFactory->create();
                        $orderCollection->addAttributeToSelect('*');
                    }
                    $grandTotalBase = $filters['grand_total_base'];
                    $from = isset($grandTotalBase['from']) ? $grandTotalBase['from'] : null;
                    $to = isset($grandTotalBase['to']) ? $grandTotalBase['to'] : null;
                    if ($from !== null && $to !== null) {
                        $orderCollection->addAttributeToFilter('base_grand_total', ['from' => $from, 'to' => $to]);
                    } elseif ($from !== null) {
                        $orderCollection->addAttributeToFilter('base_grand_total', ['from' => $from]);
                    } elseif ($to !== null) {
                        $orderCollection->addAttributeToFilter('base_grand_total', ['to' => $to]);
                    }
                }
                if (isset($filters['grand_total_purchased'])) {
                    if (!isset($orderCollection)) {
                        $orderCollection = $this->orderCollectionFactory->create();
                        $orderCollection->addAttributeToSelect('*');
                    }
                    $grandTotalPurchased = $filters['grand_total_purchased'];
                    $from = isset($grandTotalPurchased['from']) ? $grandTotalPurchased['from'] : null;
                    $to = isset($grandTotalPurchased['to']) ? $grandTotalPurchased['to'] : null;
                    if ($from !== null && $to !== null) {
                        $orderCollection->addAttributeToFilter('grand_total', ['from' => $from, 'to' => $to]);
                    } elseif ($from !== null) {
                        $orderCollection->addAttributeToFilter('grand_total', ['from' => $from]);
                    } elseif ($to !== null) {
                        $orderCollection->addAttributeToFilter('grand_total', ['to' => $to]);
                    }
                }

                // add filter for order status
                if (isset($filters['status'])) {
                    if (!isset($orderCollection)) {
                        $orderCollection = $this->orderCollectionFactory->create();
                        $orderCollection->addAttributeToSelect('*');
                    }
                    $orderCollection->addAttributeToFilter('status', ['in' => (array)$filters['status']]);
                }

                // add filter for purchase point
                if (isset($filters['purchase_point'])) {
                    if (!isset($orderCollection)) {
                        $orderCollection = $this->orderCollectionFactory->create();
                        $orderCollection->addAttributeToSelect('*');
                    }
                    $validStoreIds = [];
                    foreach ((array)$filters['purchase_point'] as $storeId) {
                        try {
                            $this->storeManager->getStore($storeId);
                            $validStoreIds[] = $storeId;
                        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                            // skip invalid store
                        }
                    }
                    if (!empty($validStoreIds)) {
                        $orderCollection->addAttributeToFilter('store_id', ['in' => $validStoreIds]);
                    }
                }

                // add filter for store_id
                if (isset($filters['store_id'])) {
                    if (!isset($orderCollection)) {
                        $orderCollection = $this->orderCollectionFactory->create();
                        $orderCollection->addAttributeToSelect('*');
                    }
                    $validStoreIds = [];
                    foreach ((array)$filters['store_id'] as $storeId) {
                        try {
                            $this->storeManager->getStore($storeId);
                            $validStoreIds[] = $storeId;
                        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                            // skip invalid store
                        }
                    }
                    if (!empty($validStoreIds)) {
                        $orderCollection->addAttributeToFilter('store_id', ['in' => $validStoreIds]);
                    }
                }
            }

            if (count($orderIds) > 0) {
                $orderCollection = $this->orderCollectionFactory->create();
                $orderCollection->addAttributeToSelect('*');
                $orderCollection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
            }

            if (!isset($orderCollection)) {
                $orderCollection = $this->orderCollectionFactory->create();
                $orderCollection->addAttributeToSelect('*');
            }

            if ($orderCollection->getSize() == 0) {
                return [
                    'status' => 'error',
                    'message' => 'No orders found to export. Please select orders to export.'
                ];
            }

            $orderCollection->setPageSize(100); // Process 100 orders at a time
            $currentPage = 1;

            $fileName = 'extended_orders_export.csv';
            $folder = $this->directoryList->getRoot() . "/storage/extendedexports/export/";

            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            $filePath = $folder . $fileName;

            // Clear the file by opening it in write mode
            $fh = fopen($filePath, 'w');
            fclose($fh);

            // Reopen the file in append mode
            $fh = fopen($filePath, 'a');

            // Write headers
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
            ], ";", '"');

            do {
                $orderCollection->setCurPage($currentPage);
                $orderCollection->load();

                // Collect product IDs from the current batch of order items
                $productIds = [];
                foreach ($orderCollection as $order) {
                    foreach ($order->getAllItems() as $item) {
                        $productIds[] = $item->getProductId();
                    }
                }

                // Load products in bulk with only the required attribute
                $productCollection = $this->productCollectionFactory->create()
                    ->addAttributeToSelect('bizbloqs_group')
                    ->addFieldToFilter('entity_id', ['in' => $productIds]);

                // Map product IDs to their bizbloqs_group values
                $productData = [];
                foreach ($productCollection as $product) {
                    $productData[$product->getId()] = $product->getData('bizbloqs_group');
                }

                // Process orders and write to CSV
                foreach ($orderCollection as $order) {
                    if (in_array($order->getEntityId(), $excludedOrderIds)) {
                        continue;
                    }

                    foreach ($order->getAllItems() as $item) {
                        $bizbloqsGroup = $productData[$item->getProductId()] ?? ''; // Use preloaded data

                        try {
                            $storeName = $order->getStore()->getName(); // Ensure store name is loaded
                        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                            $storeName = 'Unknown Store';
                        }

                        fputcsv($fh, [
                            $order->getIncrementId(),
                            $storeName, // Use the store name from the order
                            // Convert UTC order date to Europe/Amsterdam timezone
                            (new \DateTime($order->getCreatedAt(), new \DateTimeZone('UTC')))
                                ->setTimezone(new \DateTimeZone('Europe/Amsterdam'))
                                ->format('Y-m-d H:i:s'),
                            $order->getCustomerEmail(),
                            $order->getGrandTotal(),
                            $order->getShippingAmount(),
                            $order->getShippingTaxAmount(),
                            $order->getShippingAddress() ? $order->getShippingAddress()->getCountryId() : '',
                            $order->getTotalRefunded(),
                            $order->getStatus(),
                            $item->getItemId(),
                            $item->getProductId(),
                            $item->getName(),
                            $item->getSku(),
                            $item->getPrice(),
                            $item->getQtyOrdered(),
                            $item->getRowTotal(),
                            $item->getTaxAmount(),
                            $bizbloqsGroup // Use the preloaded attribute value
                        ], ";", '"');
                    }
                }

                $currentPage++;
                $orderCollection->clear(); // Clear the collection to free memory
            } while ($currentPage <= $orderCollection->getLastPageNumber());

            fclose($fh);

            return [
                'status' => 'success',
                'filename' => $fileName,
                'folder' => $folder
            ];
        } catch (\Exception $e) {
            $this->logger->error('[ERROR] ExtendedExports - Export -' . $e);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
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
