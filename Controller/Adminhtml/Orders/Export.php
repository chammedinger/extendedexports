<?php

namespace CHammedinger\ExtendedExports\Controller\Adminhtml\Orders;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use CHammedinger\ExtendedExports\Model\Export\ExtendedExport;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Response\Http\FileFactory;

class Export extends Action
{
    protected $extendedExport;
    protected $logger;
    protected $messageManager;
    protected $fileFactory;

    public function __construct(
        Context $context,
        ExtendedExport $extendedExport,
        \Psr\Log\LoggerInterface $logger,
        ManagerInterface $messageManager,
        FileFactory $fileFactory
    ) {

        $this->extendedExport = $extendedExport;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->fileFactory = $fileFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $res = $this->extendedExport->export([], $this->getRequest());
            if ($res['status'] != 'success') {
                $this->messageManager->addErrorMessage($res['message'] ?? 'An error occurred while exporting orders.');
                return $resultRedirect->setPath('sales/order');
            }

            $path = "{$res['folder']}{$res['filename']}";
            $fileName = $res['filename'];
            $folder = $res['folder'];

            if (!file_exists($path)) {
                $this->messageManager->addErrorMessage('An error occurred while exporting orders. File not found.');
                return $resultRedirect->setPath('sales/order');
            }

            // return $this->fileFactory->create(
            //     $fileName,
            //     [
            //         'type' => 'filename',
            //         'value' => $path,
            //     ],
            //     \Magento\Framework\App\Filesystem\DirectoryList::ROOT,
            //     'application/octet-stream',
            //     '' // content length will be dynamically calculated
            // );

            return $this->fileFactory->create(
                $res['filename'],
                [
                    'type' => 'filename',
                    'value' => $path,
                    'rm' => false, // remove file after download
                ],
                \Magento\Framework\App\Filesystem\DirectoryList::ROOT,
                'application/octet-stream',
                null // content length will be dynamically calculated
            );
        } catch (\Exception $e) {
            $this->logger->error('ExtendedExports - Custom export action failed: ' . $e);
            $this->messageManager->addErrorMessage('An error occurred while exporting orders. ' . $e->getMessage());

            /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $resultRedirect->setPath('sales/order');
        }
    }
}
