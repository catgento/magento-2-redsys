<?php

namespace Catgento\Redsys\Controller\Result;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use Catgento\Redsys\Helper\Helper;
use Catgento\Redsys\Logger\Logger;
use Catgento\Redsys\Model\RedsysApi;
use Catgento\Redsys\Model\ConfigInterface;
use Catgento\Redsys\Model\Currency;

/**
 * Class Index
 * @package Catgento\Redsys\Controller\Result
 */
class Index extends Action implements CsrfAwareActionInterface, HttpPostActionInterface
{

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var ResultFactory
     */
    protected $resultRedirectFactory;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OrderSender
     */
    protected $orderSender;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var OrderInterface;
     */
    protected $order = null;

    /**
     * @var \Magento\Framework\DB\TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var string
     */
    protected $authorizationCode;

    /**
     * @var string
     */
    protected $responseCode;

    /**
     * @var Currency
     */
    protected $currency;

    /**
     * @var Currency
     */
    protected $currencyList;

    /**
     * @var string
     */
    protected $amount;

    /**
     * @var RedsysApi
     */
    protected $api = null;

    /**
     * Index constructor.
     * @param Context $context
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param ResultFactory $resultRedirectFactory
     * @param TransactionFactory $transactionFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param Currency $currencyList
     * @param Helper $helper
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        ResultFactory $resultRedirectFactory,
        TransactionFactory $transactionFactory,
        ScopeConfigInterface $scopeConfig,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        Currency $currencyList,
        Helper $helper,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->transactionFactory = $transactionFactory;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->currencyList = $currencyList;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface|null
     */
    public function execute()
    {
        if ($this->getRequest()->isPost()) {
            $this->process();
        } else {
            $resultRedirect = $this->resultRedirectFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('');
            return $resultRedirect;
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    protected function process()
    {
        try {
            $this->validate();
            $api = $this->getApi();
            $responseCode = intval($api->getParameter('Ds_Response'));

            if ($responseCode <= 99) {
                $this->processOrder();
                if (ConfigInterface::XML_PATH_AUTOINVOICE) {
                    $this->_registerPaymentCapture();
                }
            } else {
                $errorMessage = $this->helper->messageResponse($responseCode)." ".__("(response:%1)",$responseCode);
                $this->helper->cancelOrder($this->getOrder(), $errorMessage);
            }

        } catch (\Exception $e) {
            $this->logger->critical($e);
        }
    }

    /**
     *  Puts order in Processing State and Status
     */
    private function processOrder()
    {
        $order = $this->getOrder();

        $state = Order::STATE_PROCESSING;
        $status = $this->helper->getOrderStatusByState($order, $state);

        $order->setState($state);
        $order->setStatus($status);

        $api = $this->getApi();
        $this->responseCode = intval($api->getParameter('Ds_Response'));
        $this->authorizationCode = $api->getParameter('Ds_AuthorisationCode');
        $this->currency = $this->currencyList->getCurrencyFromCode($api->getParameter('Ds_Currency'));
        $message = __('PSP payment accepted. (response: %1, authorization: %1)', $this->responseCode, $this->authorizationCode);
        $order->addStatusHistoryComment($message);

        $this->orderRepository->save($order);

        // send Order email
        try {
            $this->orderSender->send($order, true);
        } catch (\Exception $e) {
            $this->logger->critical($e);
        }

    }

    /**
     * Process completed payment (either full or partial)
     *
     * @param bool $skipFraudDetection
     * @return void
     */
    protected function _registerPaymentCapture()
    {
        $order = $this->getOrder();

        $parentTransactionId = $this->authorizationCode;
        $payment = $order->getPayment();
        $payment->setTransactionId($parentTransactionId);
        $payment->setCurrencyCode($this->currency);
        $payment->setParentTransactionId($parentTransactionId);
        $payment->setShouldCloseParentTransaction(true);
        $payment->setIsTransactionClosed(1);

        $payment->registerCaptureNotification(
            $this->amount/100,
            true
        );

        // notify customer
        $invoice = $payment->getCreatedInvoice();
        if ($invoice && ConfigInterface::XML_PATH_SENDINVOICE) {
            $invoice->setTransactionId($parentTransactionId)
                ->save();

            $this->invoiceSender->send($invoice, true);
        }
    }

    /**
     * @return OrderInterface
     * @throws LocalizedException
     */
    private function getOrder()
    {
        if (is_null($this->order)) {
            $api = $this->getApi();
            $orderId = $api->getParameter('Ds_Order');
            $this->order = $this->helper->getOrderByIncrementId($orderId);
        }
        return $this->order;
    }

    /**
     * @return RedsysApi
     */
    private function getApi()
    {
        if (is_null($this->api)) {
            $data = $this->getRequest()->getParam("Ds_MerchantParameters");
            $this->api = new RedsysAPI();
            $this->api->decodeMerchantParameters($data);
        }
        return $this->api;
    }

    /**
     * @throws LocalizedException
     */
    private function validate()
    {
        $data = $this->getRequest()->getParam("Ds_MerchantParameters");
        $signatureResponse = $this->getRequest()->getParam("Ds_Signature");

        if (is_null($data) or is_null($signatureResponse)) {
            throw new LocalizedException(__('Incorrect response from Redsys.'));
        }

        $api = $this->getApi();
        $sha256key = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_KEY256, ScopeInterface::SCOPE_STORE);
        $signature = $api->createMerchantSignatureNotif($sha256key, $data);

        $orderId = $api->getParameter('Ds_Order');
        $merchantCode = $api->getParameter('Ds_MerchantCode');
        $terminal = $api->getParameter('Ds_Terminal');
        $transaction = $api->getParameter('Ds_TransactionType');

        $merchantCodeMagento = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_COMMERCE_NUM, ScopeInterface::SCOPE_STORE);
        $terminalMagento = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_TERMINAL, ScopeInterface::SCOPE_STORE);
        $transactionMagento = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_TRANSACTION_TYPE, ScopeInterface::SCOPE_STORE);

        if ($signature !== $signatureResponse
            or !isset($orderId)
            or $transaction != $transactionMagento
            or $merchantCode != $merchantCodeMagento
            or intval(strval($terminalMagento)) != intval(strval($terminal))
        ) {
            throw new LocalizedException(__('Errors in POST data'));
        }

        $this->amount = $api->getParameter('Ds_Amount');
        $orderId = $api->getParameter('Ds_Order');
        $order = $this->getOrder($orderId);

        $transaction_amount = number_format($order->getBaseGrandTotal(), 2, '', '');
        $amountOrder = (float)$transaction_amount;
        if ($amountOrder != $this->amount) {
            throw new LocalizedException(__("Amount is diferent"));
        }
    }
}