<?php


namespace CHammedinger\ExtendedExports\Console\Command;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class ProductAttributesCleanUp
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Export extends \Symfony\Component\Console\Command\Command
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;
    /**
     * @var \Magento\Framework\Filter\FilterManager
     */
    protected $filterManager;
    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;
    /**
     * @var \Magento\Framework\Filesystem\DirectoryList
     */
    protected $_dir;
    /**
     * @var \CHammedinger\ExtendedExports\Model\Export\ExtendedExport
     */
    protected $extendedExport;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Filter\FilterManager $filterManager,
        \Magento\Framework\App\State $state,
        \Magento\Framework\Filesystem\DirectoryList $dir,
        \CHammedinger\ExtendedExports\Model\Export\ExtendedExport $extendedExport
    ) {
        $this->_logger = $logger;
        $this->filterManager = $filterManager;
        $this->state = $state;
        $this->_dir = $dir;
        $this->extendedExport = $extendedExport;

        parent::__construct();
    }

    private const ORDER_IDS = 'order_ids';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('extendedexports:export');
        $this->setDescription('Try export.');

        $this->addOption(
            self::ORDER_IDS,
            null,
            InputOption::VALUE_REQUIRED,
            'ORDER_IDS'
        );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML); // or \Magento\Framework\App\Area::AREA_FRONTEND, depending on your needs

        $order_ids = $input->getOption(self::ORDER_IDS);
        $order_ids = explode(',', $order_ids);

        $this->extendedExport->export($order_ids);
        $output->writeln('<info>finished :)</info>');
    }
}
