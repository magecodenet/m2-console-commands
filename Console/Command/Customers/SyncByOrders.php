<?php
/**
 * Copyright © 2016 MageCode. All rights reserved.
 */

namespace MageCode\ConsoleCommands\Console\Command\Customers;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\AddressFactory;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Indexer\IndexerRegistry;

class SyncByOrders extends Command
{
    const ORDER_COLLECTION_LIMIT = 50;

    const ADDRESSES_LIMIT = 2;

    const STORE_ID_ARGUMENT = 'store-id';

    const ADDRESSES_LIMIT_ARGUMENT = 'addresses-limit';

    const PAGE_START_ARGUMENT = 'page-start';

    const PAGE_END_ARGUMENT = 'page-end';

    protected $state;

    protected $storeManager;

    protected $orderCollectionFactory;

    protected $customerRegistry;

    protected $customerFactory;

    protected $customerAddressFactory;

    protected $groupManagement;

    protected $customerAddressDataFactory;

    protected $customerRegionDataFactory;

    protected $customerAddressMetadata;

    protected $dataObjectHelper;

    protected $_customerAttributes = null;

    protected $_addressesLimit = self::ADDRESSES_LIMIT;

    protected $_customerAddressesCount = [];

    protected $_results = [
        'storesCount' => 0,
        'ordersCount' => 0,
        'errorsCount' => 0,
    ];

    public function __construct(
        State $state,
        StoreManagerInterface $storeManager,
        OrderCollectionFactory $orderCollectionFactory,
        CustomerRegistry $customerRegistry,
        CustomerFactory $customerFactory,
        AddressFactory $customerAddressFactory,
        GroupManagementInterface $groupManagement,
        AddressInterfaceFactory $customerAddressDataFactory,
        RegionInterfaceFactory $customerRegionDataFactory,
        AddressMetadataInterface $customerAddressMetadata,
        DataObjectHelper $dataObjectHelper,
        IndexerRegistry $indexerRegistry
    )
    {
        $this->storeManager = $storeManager;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->customerRegistry = $customerRegistry;
        $this->customerFactory = $customerFactory;
        $this->customerAddressFactory = $customerAddressFactory;
        $this->groupManagement = $groupManagement;
        $this->customerAddressDataFactory = $customerAddressDataFactory;
        $this->customerRegionDataFactory = $customerRegionDataFactory;
        $this->customerAddressMetadata = $customerAddressMetadata;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->indexerRegistry = $indexerRegistry;

        $state->setAreaCode('frontend');

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('magecode:customers:syncByOrders')
            ->setDescription('Create / Update customers records based on current sales orders')
            ->setDefinition([
                new InputArgument(
                    self::STORE_ID_ARGUMENT,
                    InputArgument::OPTIONAL,
                    'Store ID'
                ),
                new InputArgument(
                    self::PAGE_START_ARGUMENT,
                    InputArgument::OPTIONAL,
                    'Page Start'
                ),
                new InputArgument(
                    self::PAGE_END_ARGUMENT,
                    InputArgument::OPTIONAL,
                    'Page End'
                ),
                new InputArgument(
                    self::ADDRESSES_LIMIT_ARGUMENT,
                    InputArgument::OPTIONAL,
                    'Addresses Limit'
                ),
            ]);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $stores = $this->storeManager->getStores();
        $storesData = [];
        foreach ($stores as $store) {
            $storesData[$store->getId()] = 'ID ' . $store->getId() . ': [' . $store->getCode() . '] ' . $store->getName();
        }

        $storeIds = array_keys($stores);
        if ($input->hasArgument(self::STORE_ID_ARGUMENT) && $storeId = (int)$input->getArgument(self::STORE_ID_ARGUMENT)) {
            if (!in_array($storeId, $storeIds)) {
                $output->writeln('<info>Available Stores:</info>');
                $output->writeln('<info>' . implode("\n", $storesData) . '</info>');
                throw new \Exception('Store ID "' . $storeId . '" not found');
            }
            $storeIds = [$storeId];
        }

        $pageStart = $input->getArgument(self::PAGE_START_ARGUMENT);
        $pageEnd = $input->getArgument(self::PAGE_END_ARGUMENT);

        if (is_numeric($input->getArgument(self::ADDRESSES_LIMIT_ARGUMENT))) {
            $this->_addressesLimit = $input->getArgument(self::ADDRESSES_LIMIT_ARGUMENT);
        }

        foreach ($storeIds as $storeId) {
            $output->writeln('<info>Update Store ' . $storesData[$storeId] . '</info>');
            $this->processStoreCustomers($output, $this->storeManager->getStore($storeId), $pageStart, $pageEnd);
            $this->_results['storesCount']++;
        }

        $output->writeln('<info> --- Reindex Customer Grid --- </info>');
        $indexer = $this->indexerRegistry->get(Customer::CUSTOMER_GRID_INDEXER_ID);
        $indexer->reindexAll();

        $output->writeln('<info> --- Results --- </info>');
        $output->writeln('<info>Updated Stores: ' . $this->_results['storesCount'] . '</info>');
        $output->writeln('<info>Updated Orders: ' . $this->_results['ordersCount'] . '</info>');
        $output->writeln('<info>Errors Count: ' . $this->_results['errorsCount'] . '</info>');
        $output->writeln('<info> --- COMPLETE --- </info>');
    }

    protected function processStoreCustomers(OutputInterface $output, StoreInterface $store, $pageStart = null, $pageEnd = null)
    {
        $ordersCollection = $this->orderCollectionFactory->create();
        $ordersCollection->addFieldToFilter('store_id', $store->getId());
        $ordersCollection->setOrder('updated_at', 'DESC');

        $totalCount = $ordersCollection->getTotalCount();
        $ordersCollection->setPageSize(self::ORDER_COLLECTION_LIMIT);
        $lastPageNumber = $ordersCollection->getLastPageNumber();

        if ($pageStart) {
            if ($pageStart > $lastPageNumber) {
                throw new \Exception('Incorrect page-start argument. Pages Total: ' . $lastPageNumber);
            }
            if (!$pageEnd) {
                $pageEnd = $pageStart;
            }
        } else {
            $pageStart = 1;
        }

        if ($pageEnd) {
            if ($pageEnd > $lastPageNumber) {
                throw new \Exception('Incorrect page-end argument. Pages Total: ' . $lastPageNumber);
            }
        } else {
            $pageEnd = $lastPageNumber;
        }

        $output->writeln('<info>Orders Total: ' . $totalCount . '</info>');
        $output->writeln('<info>Pages Total: ' . $lastPageNumber . '</info>');
        for ($page = $pageStart; $page <= $pageEnd; $page++) {
            $output->writeln('<info> --- Page #: ' . $page . ' --- </info>');
            $ordersCollection->clear();
            $ordersCollection->setPage($page, self::ORDER_COLLECTION_LIMIT);
            foreach ($ordersCollection as $order) {
                $output->writeln('<info> --- Order [' . $order->getId() . '] --- </info>');
                try {
                    $customer = $this->getOrderCustomer($output, $order, $store);
                    if ($customer->getId() != $order->getCustomerId()) {
                        $output->writeln('<comment>Set New Customer Id: [' . $customer->getId() . ']</comment>');
                        $order->setCustomerId($customer->getId());
                        $order->save();
                    }
                    $this->updateCustomerAddresses($output, $order, $customer);
                    $this->_results['ordersCount']++;
                } catch (\Exception $e) {
                    $this->_results['errorsCount']++;
                    $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
                }
            }
        }
    }

    protected function getOrderCustomer(OutputInterface $output, OrderInterface $order, StoreInterface $store)
    {
        $orderCustomerEmail = strtolower($order->getCustomerEmail());
        if (!$orderCustomerEmail) {
            $this->_results['errorsCount']++;
            throw new \Exception('Customer Email not found');
        }
        $output->writeln('<comment>Order Customer: [' . $order->getCustomerId() . '] ' . $orderCustomerEmail . '</comment>');
        if ($customerId = $order->getCustomerId()) {
            try {
                $customer = $this->customerRegistry->retrieve($customerId);
                $customerEmail = strtolower($customer->getEmail());
                $output->writeln('<comment>Customer By Id: [' . $customerId . '] ' . $customerEmail . '</comment>');
                if ($customerEmail == $orderCustomerEmail) {
                    return $customer;
                }
                $this->_results['errorsCount']++;
                throw new \Exception('Customer By Id: Email not match with order ');
            } catch (\Exception $e) {
                $output->writeln('<comment>' . $e->getMessage() . '</comment>');
            }
        }

        try {
            $customer = $this->customerRegistry->retrieveByEmail($orderCustomerEmail, $store->getWebsiteId());
            $output->writeln('<comment>Customer By Email: [' . $customer->getId() . '] ' . $orderCustomerEmail . '</comment>');
            return $customer;
        } catch (\Exception $e) {
            $output->writeln('<comment>Customer By Email: Not found</comment>');

            $customer = $this->customerFactory->create();
            $customer->setWebsiteId($store->getWebsiteId());
            $customer->setStoreId($store->getId());
            $customer->setFirstname($order->getCustomerFirstname());
            $customer->setLastname($order->getCustomerLastname());
            $customer->setEmail($orderCustomerEmail);
            try {
                $customer->setGroupId($this->groupManagement->getDefaultGroup()->getId());
            } catch (\Exception $e) {
            }
            $customer->save();

            $output->writeln('<comment>Create Customer: [' . $customer->getId() . '] ' . $customer->getEmail() . '</comment>');

            return $customer;
        }

        $this->_results['errorsCount']++;
        throw new \Exception('Can\'t get customer: ' . $orderCustomerEmail);
    }

    protected function updateCustomerAddresses(OutputInterface $output, OrderInterface $order, Customer $customer)
    {
        $output->writeln('<comment>Update Customer Addresses: [' . $customer->getId() . '] ' . $customer->getEmail() . '</comment>');

        $customerId = $customer->getId();

        $customerData = $customer->getDataModel();
        $customerAddresses = $customerData->getAddresses();

        if (!isset($this->_customerAddressesCount[$customerId])) {
            $this->_customerAddressesCount[$customerId] = count($customerAddresses);
        }

        $customerAddressesIds = [];
        foreach ($customerAddresses as $customerAddress) {
            $customerAddressesIds[] = $customerAddress->getId();
        }

        $savedAddresses = [];

        $defaultAddressId = null;

        foreach ($order->getAddresses() as $orderAddress) {

            if ($orderCustomerAddressId = $orderAddress->getCustomerAddressId()) {
                if (in_array($orderCustomerAddressId, $customerAddressesIds)) {
                    if (!$defaultAddressId) {
                        $defaultAddressId = $orderCustomerAddressId;
                    }
                    continue;
                }

                if (array_key_exists($orderCustomerAddressId, $savedAddresses)) {
                    $orderAddress->setEmail($customer->getEmail());
                    $orderAddress->setCustomerId($customer->getId());
                    $orderAddress->setCustomerAddressId($savedAddresses[$orderCustomerAddressId]);
                    $orderAddress->save();
                    continue;
                }
            }

            if ($this->_customerAddressesCount[$customerId] >= $this->_addressesLimit) {
                continue;
            }

            $orderAddressData = [];
            $cutomerRegionDataObject = $this->customerRegionDataFactory->create();
            foreach ($this->_getCustomerAddressAttributes() as $attributeCode) {
                switch ($attributeCode) {
                    case AddressInterface::STREET:
                        $orderAddressData[$attributeCode] = $orderAddress->getDataUsingMethod($attributeCode);
                        break;
                    case AddressInterface::REGION_ID:
                        $cutomerRegionDataObject->setRegionId($orderAddress->getData($attributeCode));
                        break;
                    case AddressInterface::REGION:
                        $cutomerRegionDataObject->setRegion($orderAddress->getData($attributeCode));
                        break;
                    default:
                        $orderAddressData[$attributeCode] = $orderAddress->getData($attributeCode);
                        break;
                }
            }

            $customerAddressDataObject = $this->customerAddressDataFactory->create();
            $this->dataObjectHelper->populateWithArray(
                $customerAddressDataObject,
                $orderAddressData,
                '\Magento\Customer\Api\Data\AddressInterface'
            );

            $customerAddressDataObject->setRegion($cutomerRegionDataObject);

            $customerAddress = $this->customerAddressFactory->create();
            $customerAddress->updateData($customerAddressDataObject);

            $customerAddress->setCustomerId($customer->getId());
            $customerAddress->save();

            $savedAddresses[$orderAddress->getCustomerAddressId()] = $customerAddress->getId();

            $orderAddress->setEmail($customer->getEmail());
            $orderAddress->setCustomerId($customer->getId());
            $orderAddress->setCustomerAddressId($customerAddress->getId());
            $orderAddress->save();

            if (!$defaultAddressId) {
                $defaultAddressId = $customerAddress->getId();
            }

            $this->_customerAddressesCount[$customerId]++;
        }

        if ($defaultAddressId && (!$customer->getDefaultBilling() || !$customer->getDefaultShipping())) {
            if (!$customer->getDefaultBilling()) {
                $customer->setDefaultBilling($defaultAddressId);
            }
            if (!$customer->getDefaultShipping()) {
                $customer->setDefaultShipping($defaultAddressId);
            }
            $customer->save();
        }
    }

    protected function _getCustomerAddressAttributes()
    {
        if ($this->_customerAttributes === null) {
            $this->_customerAttributes = [];
            $customerAttributes = $this->customerAddressMetadata->getAllAttributesMetadata();
            foreach ($customerAttributes as $attribute) {
                $this->_customerAttributes[] = $attribute->getAttributeCode();
            }
        }
        return $this->_customerAttributes;
    }
}
