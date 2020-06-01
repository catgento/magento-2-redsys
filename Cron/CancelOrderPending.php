<?php

namespace Catgento\Redsys\Cron;

use Catgento\Redsys\Logger\Logger;
use Catgento\Redsys\Model\ConfigInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Class CancelOrderPending
 */
class CancelOrderPending
{

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var FilterGroup
     */
    private $filterGroup;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ConfigInterface
     */
    protected $scopeConfig;

    /**
     * CancelOrderPending constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroup $filterGroup
     * @param ConfigInterface $scopeConfig
     * @param Logger $logger
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        FilterGroup $filterGroup,
        ConfigInterface $scopeConfig,
        Logger $logger
    )
    {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->filterGroup = $filterGroup;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * @throws \Exception
     */
    public function execute()
    {
        $enabled = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_CANCEL_PENDING_ORDERS, ScopeInterface::SCOPE_STORE);

        if ($enabled) {
            $today = date("Y-m-d h:i:s");
            $to = strtotime('-10 min', strtotime($today));
            $to = date('Y-m-d h:i:s', $to);

            $filterGroupDate = $this->filterGroup;
            $filterGroupStatus = clone($filterGroupDate);

            $this->logger->info('Retrieving orders to cancel');

            $filterDate = $this->filterBuilder
                ->setField('updated_at')
                ->setConditionType('to')
                ->setValue($to)
                ->create();
            $filterStatus = $this->filterBuilder
                ->setField('status')
                ->setConditionType('eq')
                ->setValue('pending')
                ->create();

            $filterGroupDate->setFilters([$filterDate]);
            $filterGroupStatus->setFilters([$filterStatus]);

            $searchCriteria = $this->searchCriteriaBuilder->setFilterGroups(
                [$filterGroupDate, $filterGroupStatus]
            );
            $searchResults = $this->orderRepository->getList($searchCriteria->create());

            /** @var Order $order */
            foreach ($searchResults->getItems() as $order) {
                $payment = $order->getPayment();
                $method = $payment->getMethodInstance();
                $methodCode = $method->getCode();

                $this->logger->info('Checking order: ' . $order->getIncrementId());
                $this->logger->info('Code: ' . $methodCode);

                if ($methodCode == 'redsys') {
                    $this->logger->info('Canceling order: ' . $order->getIncrementId());
                    $comment = __('Order cancelled because it was idle for more than 10 minutes');
                    $order->cancel();
                    $order->addStatusHistoryComment($comment)
                        ->setIsCustomerNotified(false);
                    $order->save();
                }
            }
        }
    }
}
