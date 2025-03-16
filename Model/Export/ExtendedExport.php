<?php

namespace CHammedinger\ExtendedExports\Model\Export;

use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList; // Correct import
use Magento\Framework\App\ResponseInterface;

use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class ExtendedExport
{
    protected $fileFactory;
    protected $orderCollectionFactory;
    protected $directoryList;

    public function __construct(
        FileFactory $fileFactory,
        CollectionFactory $orderCollectionFactory,
        DirectoryList $directoryList,
        RawFactory $resultRawFactory,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->fileFactory = $fileFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->directoryList = $directoryList;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    public function export($orderIds = [], $request = null)
    {
        try {

            $excludedOrderIds = [];
            if ($request->getParam('excluded') && $request->getParam('excluded') != 'false') {
                // $this->logger->info('Custom export action called with excluded order ids: ' . json_encode($request->getParam('excluded')));
                $excludedOrderIds = $request->getParam('excluded');
            }

            // extract possible order ids from the request params
            if ($request->getParam('selected') && $request->getParam('selected') != 'false') {
                // $this->logger->info('Custom export action called with selected order ids: ' . json_encode($request->getParam('selected')));
                $orderIds = array_merge($orderIds, $request->getParam('selected'));
            } elseif ($request->getParam('filters')) {
                // $this->logger->info('Custom export action called with filter: ' . json_encode($request->getParam('filters')));
                $filters = $request->getParam('filters');

                if (isset($filters['entity_id'])) {
                    $orderIds = array_merge($orderIds, $filters['entity_id']);
                }

                if (isset($filters['increment_id'])) {
                    $orderIds = array_merge($orderIds, $filters['increment_id']);
                }

                if (isset($filters['created_at'])) {
                    $from = date('Y-m-d 00:00:00', strtotime($filters['created_at']['from']));
                    $to = date('Y-m-d 23:59:59', strtotime($filters['created_at']['to']));

                    // get the currently set timezone from Magento
                    $timezone = $this->scopeConfig->getValue('general/locale/timezone', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

                    // convert dates to UTC timezone
                    $from = new \DateTime($from, new \DateTimeZone($timezone));
                    $from->setTimezone(new \DateTimeZone('UTC'));
                    $from = $from->format('Y-m-d H:i:s');

                    $to = new \DateTime($to, new \DateTimeZone($timezone));
                    $to->setTimezone(new \DateTimeZone('UTC'));
                    $to = $to->format('Y-m-d H:i:s');

                    // Fetch order collection
                    $orderCollection = $this->orderCollectionFactory->create();
                    $orderCollection->addAttributeToSelect('*');
                    $orderCollection->addAttributeToFilter('created_at', ['from' => $from, 'to' => $to]);
                    // $orderCollection->addFieldToSelect(['entity_id', 'increment_id', 'customer_email', 'grand_total', 'status']);
                }
            }

            if (count($orderIds) > 0) {
                $this->logger->info('Custom export action called with order ids: ' . json_encode($orderIds));
                // Fetch order collection
                $orderCollection = $this->orderCollectionFactory->create();
                $orderCollection->addAttributeToSelect('*');
                $orderCollection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
                // $orderCollection->addFieldToSelect(['entity_id', 'increment_id', 'customer_email', 'grand_total', 'status']);
            }

            if ($orderCollection->getSize() == 0) {
                return [
                    'status' => 'error',
                    'message' => 'No orders found to export. Please select orders to export.'
                ];
            }

            $orderData = [];
            $ordersCount = 0;
            foreach ($orderCollection as $order) {

                if (in_array($order->getEntityId(), $excludedOrderIds)) {
                    continue;
                }

                $ordersCount++;

                foreach ($order->getAllItems() as $item) {
                    $orderData[] = [
                        'Order ID' => $order->getIncrementId(),
                        'Customer Email' => $order->getCustomerEmail(),
                        'Total' => $order->getGrandTotal(),
                        'Status' => $order->getStatus(),
                        'Item ID' => $item->getItemId(),
                        'Product ID' => $item->getProductId(),
                        'Product Name' => $item->getName(),
                        'SKU' => $item->getSku(),
                        'Price' => $item->getPrice(),
                        'Quantity' => $item->getQtyOrdered(),
                        'Row Total' => $item->getRowTotal(),
                        'VAT' => $item->getTaxAmount(),
                    ];
                }
            }

            $fileName = 'extended_orders_export.csv';
            $folder = $this->directoryList->getRoot() . "/storage/extendedexports/export/";

            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            $filePath = $folder . $fileName;
            $this->str_putcsv($orderData, $filePath);

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
