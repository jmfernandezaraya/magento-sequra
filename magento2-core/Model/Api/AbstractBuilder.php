<?php
/**
 * Copyright © 2017 SeQura Engineering. All rights reserved.
 */

namespace Sequra\Core\Model\Api;

abstract class AbstractBuilder implements BuilderInterface
{
    const STATE_CONFIRMED = 'confirmed';
    const STATE_APPROVED = 'approved';
    const STATE_NEEDS_REVIEW = 'needs_review';
    const STATE_ON_HOLD = 'on_hold';
    const STATE_CANCELLED = 'cancelled';
    public static $centsPerWhole = 100;
    /**
     * Merchant Id in SeQura
     * @var string
     */
    protected $merchant_id;
    /**
     * Built data container
     * @var array
     */
    protected $data;
    /**
     * Limit for DR
     * @var int
     */
    protected $limit = null;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * Order object or Quote Object
     *
     * @var \Magento\Framework\Model\AbstractModel
     */
    protected $order;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var \Magento\Framework\Locale\ResolverInterface
     */
    protected $localeResolver;

    /**
     * @var \Magento\Framework\Module\ResourceInterface
     */
    protected $moduleResource;

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        \Magento\Framework\Module\ResourceInterface $moduleResource,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->orderFactory = $orderFactory;
        $this->productRepository = $productRepository;
        $this->urlBuilder = $urlBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->localeResolver = $localeResolver;
        $this->moduleResource = $moduleResource;
        $this->logger = $logger;
    }
    
    public function setStoreId(int $storeId):BuilderInterface {
        $this->storeId = $storeId;
        return $this;
    }

    public function setLimit(?int $limit):BuilderInterface {
        $this->limit = $limit;
        return $this;
    }

    public function setMerchantId(string $merchant_id):BuilderInterface {
        $this->merchant_id = $merchant_id;
        return $this;
    }

    public function addMerchantReferences(bool $both = true ):BuilderInterface
    {
        unset($this->data['merchant_reference']);
        $this->data['merchant_reference']['order_ref_1'] = $this->order->getIncrementId();
        if($both) {
            $this->data['merchant_reference']['order_ref_2'] = $this->order->getId();
        }
        return $this;
    }

    public function setState(string $state):BuilderInterface
    {
        $this->data['state'] = $state;
        return $this;
    }

    public function setQuoteAsOrder(\Magento\Quote\Api\Data\CartInterface $quote):BuilderInterface
    {
        $this->order = $quote;
        return $this;
    }

    public function setOrder(\Magento\Sales\Api\Data\OrderInterface $order):BuilderInterface
    {
        $this->order = $order;
        return $this;
    }

    protected function getConfigData($field, $storeId = null)
    {
        $path = 'sequra/core/' . $field;

        return $this->getGlobalConfigData($path, $storeId);
    }

    protected function getGlobalConfigData($path, $storeId = null)
    {
        return $this->scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function merchant()
    {
        if(!$this->merchant_id){
            $this->merchant_id = $this->getConfigData('merchant_ref',$this->getStoreId());
        }
        return [
            'id' => $this->merchant_id,
        ];
    }
    protected function getStoreId(){
        if($this->order){
            return $this->order->getStoreId();
        }
        if($this->quote){
            return $this->quote->getStoreId();
        }
    }
    abstract public function deliveryAddress();

    abstract public function invoiceAddress();

    public function items()
    {
        return array_merge(
            $this->productItem(),
            $this->extraItems(),
            $this->handlingItems()
        );
    }

    abstract public function productItem();

    public function extraItems()
    {
        $items = [];
        //order discounts
        $discount_with_tax = $this->getDiscountInclTax();
        if ($discount_with_tax < 0) {
            $item = [];
            $item["type"] = "discount";
            $item["reference"] = self::notNull($this->order->getCouponCode());
            $item["name"] = 'Descuento';
            $item["total_with_tax"] = $discount_with_tax;
            $items[] = $item;
        }
        return $items;
    }

    public static function notNull($value1)
    {
        return is_null($value1) ? '' : $value1;
    }

    public static function integerPrice($price)
    {
        if(!is_numeric($price)){
            return 0;
        }
        return intval(round(self::$centsPerWhole * $price));
    }

    public function handlingItems()
    {
        $items = [];
        $deliveryMethod = $this->getDeliveryMethod();

        if (!$deliveryMethod['provider']) {
            return [];
        }

        $incl_tax = $this->getShippingInclTax();

        $handling = [
            'type' => 'handling',
            'reference' => $deliveryMethod['provider'],
            'name' => $deliveryMethod['name'],
            'total_with_tax' => self::integerPrice($incl_tax),
        ];

        $items[] = $handling;

        return $items;
    }

    public function getDeliveryMethod()
    {
        $shippingMethod = $this->getShippingMethod();
        $carrier = explode('_', $shippingMethod, 2);
        $title = $this->scopeConfig->getValue('carriers/' . $carrier[0] . '/title');

        return [
            'name' => self::notNull(isset($carrier[1]) ? $carrier[1] : 'Envío'),
            'days' => self::notNull($title),
            'provider' => self::notNull($carrier[0]),
        ];
    }

    abstract public function getShippingMethod();

    abstract public function getShippingInclTax();

    abstract public function getDiscountInclTax();

    public function address($address)
    {
        $data = [];
        $data['given_names'] = self::notNull($address->getFirstname());
        $data['surnames'] = self::notNull($address->getLastname());
        $data['company'] = self::notNull($address->getCompany());
        $street = $address->getStreet();
        $data['address_line_1'] = (string)self::notNull($street[0] . (isset($street[1]) ? ", " . $street[1] : ''));
        if (isset($street[2])) {
            $data['address_line_2'] = (string)self::notNull($street[2] . (isset($street[3]) ? ", " . $street[3] : ''));
        } else {
            $data['address_line_2'] = '';
        }
        $data['postal_code'] = self::notNull($address->getPostcode());
        $data['city'] = self::notNull($address->getCity());
        $data['country_code'] = self::notNull($address->getCountryId());
        // OPTIONAL
        $data['state'] = self::notNull($address->getRegion());
        $data['phone'] = self::notNull($address->getTelephone());
        $data['mobile_phone'] = self::notNull($address->getFax());
        $data['vat_number'] = self::notNull($address->getVatId());

        return $data;
    }

    public function customer()
    {
        $customer = $this->getObjWithCustomerData();
        $data = [];
        $data['given_names'] = self::notNull($customer->getFirstname());
        $data['surnames'] = self::notNull($customer->getLastname());
        $data['email'] = self::notNull($customer->getEmail());
        if (!$data['email']) {
            $data['email'] = self::notNull($this->order->getData('customer_email'));
        }
        // OPTIONAL
        $company = $customer->getCompany();
        if (!is_null($company)) {
            $data['company'] = self::notNull($company);
        }
        $vat = $customer->getTaxvat();
        if (!is_null($vat)) {
            $data['vat_number'] = self::notNull($vat);
            $data['nin'] = self::notNull($vat);
        }
        $dob = $customer->getDob();
        if (!is_null($dob)) {
            $data['date_of_birth'] = self::dateOrBlank($dob);
        }
        $data['ref'] = self::notNull($customer->getId());
        if ($title = $customer->getPrefix()) {
            $data['title'] = str_replace(
                ['sra', 'dña', 'srta', 'sr', 'd'],
                ['mrs', 'mrs', 'miss', 'mr', 'mr'],
                strtolower(trim($title, '.'))
            );
        }

        return $data;
    }

    abstract public function getObjWithCustomerData();

    public static function dateOrBlank($date)
    {
        return $date ? date_format(date_create($date), 'Y-m-d') : '';
    }

    public function fillOptionalProductItemFields($product)
    {
        $item = [];
        if (is_object($product)) {
            $item["description"] = self::notNull($product->getDescription());
            $item["product_id"] = self::notNull($product->getId());
            $item["url"] = self::notNull($product->getProductUrl());
            //@todo
            /*			$categoryIds         = $product->getCategoryIds();
                        if ( count( $categoryIds ) ) {
                            $firstCategoryId = $categoryIds[0];
                            $_category       = Mage::getModel( 'catalog/category' )->load( $firstCategoryId );
                            if ( $_category->getName() ) {
                                $item["category"] = self::notNull( $_category->getName() );
                            }
                        }
                        if ( $product->getResource()->getAttribute( 'manufacturer' ) ) {
                            if ( $manufacturer = $product->getAttributeText( 'manufacturer' ) ) {
                                $item["manufacturer"] = $manufacturer;
                            }
                        }
            */
        }

        return $item;
    }

    public function gui()
    {
        $data = [
            'layout' => $this->isMobile() ? 'mobile' : 'desktop',
        ];

        return $data;
    }

    public static function isMobile()
    {
        $regex_match = "/(nokia|iphone|android|motorola|^mot\-|softbank|foma|docomo|kddi|up\.browser|up\.link|"
            . "htc|dopod|blazer|netfront|helio|hosin|huawei|novarra|CoolPad|webos|techfaith|palmsource|"
            . "blackberry|alcatel|amoi|ktouch|nexian|samsung|^sam\-|s[cg]h|^lge|ericsson|philips|sagem|wellcom|bunjalloo|maui|"
            . "symbian|smartphone|mmp|midp|wap|phone|windows ce|iemobile|^spice|^bird|^zte\-|longcos|pantech|gionee|^sie\-|portalmmm|"
            . "jig\s browser|hiptop|^ucweb|^benq|haier|^lct|opera\s*mobi|opera\*mini|320x320|240x320|176x220"
            . ")/i";

        if (preg_match($regex_match, strtolower($_SERVER['HTTP_USER_AGENT']))) {
            return true;
        }

        if (strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'application/vnd.wap.xhtml+xml') > 0 or
            isset($_SERVER['HTTP_X_WAP_PROFILE']) or
            isset($_SERVER['HTTP_PROFILE'])) {
            return true;
        }

        $mobile_ua = strtolower(substr($_SERVER['HTTP_USER_AGENT'], 0, 4));
        $mobile_agents = [
            'w3c ',
            'acs-',
            'alav',
            'alca',
            'amoi',
            'audi',
            'avan',
            'benq',
            'bird',
            'blac',
            'blaz',
            'brew',
            'cell',
            'cldc',
            'cmd-',
            'dang',
            'doco',
            'eric',
            'hipt',
            'inno',
            'ipaq',
            'java',
            'jigs',
            'kddi',
            'keji',
            'leno',
            'lg-c',
            'lg-d',
            'lg-g',
            'lge-',
            'maui',
            'maxo',
            'midp',
            'mits',
            'mmef',
            'mobi',
            'mot-',
            'moto',
            'mwbp',
            'nec-',
            'newt',
            'noki',
            'oper',
            'palm',
            'pana',
            'pant',
            'phil',
            'play',
            'port',
            'prox',
            'qwap',
            'sage',
            'sams',
            'sany',
            'sch-',
            'sec-',
            'send',
            'seri',
            'sgh-',
            'shar',
            'sie-',
            'siem',
            'smal',
            'smar',
            'sony',
            'sph-',
            'symb',
            't-mo',
            'teli',
            'tim-',
            'tosh',
            'tsm-',
            'upg1',
            'upsi',
            'vk-v',
            'voda',
            'wap-',
            'wapa',
            'wapi',
            'wapp',
            'wapr',
            'webc',
            'winw',
            'winw',
            'xda ',
            'xda-'
        ];

        if (in_array($mobile_ua, $mobile_agents)) {
            return true;
        }

        if (isset($_SERVER['ALL_HTTP']) && strpos(strtolower($_SERVER['ALL_HTTP']), 'OperaMini') > 0) {
            return true;
        }

        return false;
    }

    public function platform()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');

        $data = [
            'name' => 'Magento',
            'version' => self::notNull($productMetadata->getVersion()),
            'plugin_version' => $this->moduleResource->getDbVersion('Sequra_Core'),
            'php_version' => phpversion(),
            'php_os' => PHP_OS,
            'uname' => php_uname(),
            'db_name' => 'mysql',//@todo
            'db_version' => '5.7.x or later'//@todo
        ];

        return $data;
    }

    public function sign($value)
    {
        $storeId = $this->order?$this->order->getStoreId():null;
        return hash_hmac('sha256', $value, $this->getConfigData('user_secret',$storeId));
    }

    protected function fixRoundingProblems($order, $cart_name = 'cart')
    {
        $totals = \Sequra\PhpClient\Helper::totals($order[$cart_name]);
        $diff_with_tax = $order[$cart_name]['order_total_with_tax'] - $totals['with_tax'];
        /*Don't correct error bigger than 1 cent per line*/
        if (($diff_with_tax == 0) || count($order[$cart_name]['items']) < abs($diff_with_tax)) {
            return $order;
        }

        $item['type'] = 'discount';
        $item['reference'] = 'Ajuste';
        $item['name'] = 'Ajuste';
        $item['total_with_tax'] = $diff_with_tax;
        if ($diff_with_tax > 0) {
            $item['type'] = 'handling';
        }
        $order[$cart_name]['items'][] = $item;

        return $order;
    }

    abstract public function build():BuilderInterface;
    public function getData():array{
        return $this->data;
    }
}
