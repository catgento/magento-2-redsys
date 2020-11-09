<?php

namespace Catgento\Redsys\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\SessionFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Catgento\Redsys\Logger\Logger;
use Catgento\Redsys\Helper\Helper;
use Catgento\Redsys\Helper\CountryIsoHelper;

/**
 * Class RedsysFactory
 * @package Catgento\Redsys\Model
 */
class RedsysFactory
{

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var UrlInterface
     */
    protected $url;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var OrderInterface
     */
    protected $order = null;

    /**
     * @var CountryIsoHelper
     */
    protected $countryIsoHelper;
    /**
     * @var SessionFactory
     */
    private $customerSession;

    /**
     * RedsysFactory constructor.
     * @param ScopeConfigInterface $scopeConfig
     * @param CheckoutSession $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Helper $helper
     * @param UrlInterface $url
     * @param Logger $logger
     * @param CountryIsoHelper $countryIsoHelper
     * @param SessionFactory $customerSession
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        Helper $helper,
        UrlInterface $url,
        Logger $logger,
        CountryIsoHelper $countryIsoHelper,
        SessionFactory $customerSession
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->helper = $helper;
        $this->url = $url;
        $this->logger = $logger;
        $this->countryIsoHelper = $countryIsoHelper;
        $this->customerSession = $customerSession;
    }

    /**
     * @return OrderInterface
     */
    private function getOrder()
    {
        if (is_null($this->order)) {
            $orderId = $this->checkoutSession->getLastRealOrderId();
            $this->order = $this->orderFactory->create()->loadByIncrementId($orderId);
        }
        return $this->order;
    }

    /**
     * @return float
     */
    private function getRedsysAmount()
    {
        $transaction_amount = number_format($this->getOrder()->getBaseGrandTotal(), 2, '', '');
        return (float)$transaction_amount;
    }

    /**
     * @return string
     */
    private function getRedsysOrderNumber()
    {
        $orderId = $this->getOrder()->getIncrementId();
        return strval($orderId);
    }

    /**
     * @return string
     */
    private function getRedsysProducts()
    {
        $order = $this->getOrder();
        $products = '';
        foreach ($order->getAllVisibleItems() as $itemId => $item) {
            $products .= $item->getName();
            $products .= "X" . $item->getQtyToInvoice();
            $products .= "/";
        }
        return $products;
    }

    /**
     * @return string
     */
    private function getRedsysCustomer()
    {
        $order = $this->getOrder();
        return $order->getCustomerFirstname()." ".$order->getCustomerLastname()."/ ".__("Email: ").$order->getCustomerEmail();
    }

    /**
     * @return \Catgento\Redsys\Model\RedsysApi
     */
    public function createRedsysObject()
    {
        // Get all module Configurations
        $commerce_name = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_COMMERCE_NAME, ScopeInterface::SCOPE_STORE);
        $commerce_num = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_COMMERCE_NUM, ScopeInterface::SCOPE_STORE);
        $terminal = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_TERMINAL, ScopeInterface::SCOPE_STORE);
        $trans = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_TRANSACTION_TYPE, ScopeInterface::SCOPE_STORE);

        // Redirect Result URL
        $orderId = $this->getOrder()->getIncrementId();
        $commerce_url = $this->url->getUrl('redsys/result', ['order_id' => $orderId]);
        $KOcommerce_url = $this->url->getUrl('redsys/koresult', ['order_id' => $orderId]);
        $OKcommerce_url = $this->url->getUrl('redsys/okresult', ['order_id' => $orderId]);

        // Setting Parameters to Redsys
        $redsysObj = new RedsysApi();
        $redsysObj->setParameter("DS_MERCHANT_AMOUNT", $this->getRedsysAmount());
        $redsysObj->setParameter("DS_MERCHANT_ORDER", $this->getRedsysOrderNumber());
        $redsysObj->setParameter("DS_MERCHANT_MERCHANTCODE", $commerce_num);
        $redsysObj->setParameter("DS_MERCHANT_CURRENCY", $this->helper->getCurrency($this->getOrder()));
        $redsysObj->setParameter("DS_MERCHANT_TRANSACTIONTYPE", $trans);
        $redsysObj->setParameter("DS_MERCHANT_TERMINAL", $terminal);
        $redsysObj->setParameter("DS_MERCHANT_MERCHANTURL", $commerce_url);
        $redsysObj->setParameter("DS_MERCHANT_URLOK", $OKcommerce_url);
        $redsysObj->setParameter("DS_MERCHANT_URLKO", $KOcommerce_url);
        $redsysObj->setParameter("Ds_Merchant_ConsumerLanguage", $this->helper->getLanguage());
        $redsysObj->setParameter("Ds_Merchant_ProductDescription", $this->getRedsysProducts());
        $redsysObj->setParameter("Ds_Merchant_Titular", $this->getRedsysCustomer());
        $redsysObj->setParameter("Ds_Merchant_MerchantData", sha1($commerce_url));
        $redsysObj->setParameter("Ds_Merchant_MerchantName", $commerce_name);
        $redsysObj->setParameter("Ds_Merchant_PayMethods", ConfigInterface::REDSYS_PAYMETHODS);
        $redsysObj->setParameter("Ds_Merchant_Module", "catgento_redsys");
        $redsysObj->setParameter("DS_MERCHANT_EMV3DS", $this->generateMerchantEMV3DSData());

        return $redsysObj;
    }

    public function generateMerchantEMV3DSData()
    {
        $order = $this->getOrder();

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $emv3dsData['cardholderName'] = $order->getData('customer_firstname') . ' ' . $order->getData('customer_lastname');
        $emv3dsData['Email'] = $order->getData('customer_email');

        // Shipping
        $shippingStreet = $shippingAddress->getStreet();
        $emv3dsData['shipAddrLine1'] = $shippingStreet[0];
        if (isset($shippingStreet[1])) {
            $emv3dsData['shipAddrLine2'] = $shippingStreet[1];
        }
        $emv3dsData['shipAddrCity'] = $shippingAddress->getCity();
        $emv3dsData['shipAddrPostCode'] = $shippingAddress->getPostcode();
        $emv3dsData['shipAddrCountry'] = $this->countryIsoHelper->getCountryNumericCode($billingAddress->getCountryId());

        // Billing
        $billingStreet = $billingAddress->getStreet();
        $emv3dsData['billAddrLine1'] = $billingStreet[0];
        if (isset($billingStreet[1])) {
            $emv3dsData['billAddrLine2'] = $billingStreet[1];
        }
        $emv3dsData['billAddrCity'] = $billingAddress->getCity();
        $emv3dsData['billAddrPostCode'] = $billingAddress->getPostcode();
        $emv3dsData['billAddrCountry'] = $this->countryIsoHelper->getCountryNumericCode($billingAddress->getCountryId());

        $emv3dsData['threeDSRequestorAuthenticationInfo'] = '01';
        if ($this->isLoggedIn()) {
            $emv3dsData['threeDSRequestorAuthenticationInfo'] = '02';
        }

        return $emv3dsData;
    }

    /**
     * @return mixed
     */
    public function isLoggedIn()
    {
        return $this->customerSession->create()->isLoggedIn();
    }
}