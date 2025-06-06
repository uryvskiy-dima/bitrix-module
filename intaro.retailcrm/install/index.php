<?php

/**
 * Module Install/Uninstall script
 * Module name: intaro.retailcrm
 * Class name:  intaro_retailcrm
 */
global $MESS;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sale\Delivery\Services\Manager;
use Bitrix\Sale\EventActions;
use Bitrix\Sale\Internals\OrderTable;
use Intaro\RetailCrm\Component\ConfigProvider;
use Intaro\RetailCrm\Component\Constants;
use Intaro\RetailCrm\Service\CurrencyService;
use RetailCrm\ApiClient;
use RetailCrm\Exception\CurlException;
use RetailCrm\Http\Client;
use RetailCrm\Response\ApiResponse;
use Intaro\RetailCrm\Component\Advanced\InstallerTrait;
use Intaro\RetailCrm\Component\Advanced\LoyaltyInstaller;

Loader::IncludeModule('highloadblock');

IncludeModuleLangFile(__FILE__);

if (class_exists('intaro_retailcrm')) {
    return false;
}

require_once(__DIR__ . '/../lib/component/advanced/installertrait.php');

class intaro_retailcrm extends CModule
{
    use InstallerTrait;

    public const V5 = 'v5';
    public $MODULE_ID = 'intaro.retailcrm';
    public $OLD_MODULE_ID = 'intaro.intarocrm';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;
    public $RETAIL_CRM_API;
    public $RETAIL_CRM_EXPORT = 'retailcrm';
    public $INSTALL_PATH;

    public function __construct()
    {
        $arModuleVersion = [];
        $path = str_replace("\\", '/', __FILE__);
        $path = substr($path, 0, strlen($path) - strlen('/index.php'));
        $this->INSTALL_PATH = $path;

        include($path . '/version.php');

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = GetMessage('RETAIL_MODULE_NAME');
        $this->MODULE_DESCRIPTION = GetMessage('MODULE_DESCRIPTION');
        $this->PARTNER_NAME = GetMessage('MODULE_PARTNER_NAME');
        $this->PARTNER_URI = GetMessage('MODULE_PARTNER_URI');
    }

    public function loadDeps()
    {
        if (!class_exists('RetailcrmConstants')) {
            require_once __DIR__ . '/../classes/general/RetailcrmConstants.php';
        }

        if (!class_exists('RetailcrmConfigProvider')) {
            require_once __DIR__ . '/../classes/general/RetailcrmConfigProvider.php';
        }

        if (!class_exists('RetailcrmDependencyLoader')) {
            require_once __DIR__ . '/../classes/general/RetailcrmDependencyLoader.php';
        }
    }

    /**
     * Functions DoInstall and DoUninstall are
     * All other functions are optional
     */
    function DoInstall()
    {
        try {
            require_once('install_deps.php');
        } catch (Error $exception) {
            RCrmActions::eventLog(
                'RetailCRM module install error',
                'intaro.retailcrm',
                $exception->getCode() . ': ' . $exception->getMessage()
            );

            return false;
        }

        global $APPLICATION, $step, $arResult;

        if (!in_array('curl', get_loaded_extensions(), true)) {
            $APPLICATION->ThrowException(GetMessage('RETAILCRM_CURL_ERR'));
            return false;
        }

        $infoSale = CModule::CreateModuleObject('sale')->MODULE_VERSION;
        if (version_compare($infoSale, '16', '<=')) {
            $APPLICATION->ThrowException(GetMessage('SALE_VERSION_ERR'));

            return false;
        }

        if (!Loader::includeModule('sale')) {
            return false;

        }

        if (!date_default_timezone_get() && !ini_get('date.timezone')) {
            $APPLICATION->ThrowException(GetMessage('DATE_TIMEZONE_ERR'));

            return false;
        }

        $step = (int) $_REQUEST['step'];

        if (file_exists($this->INSTALL_PATH . '/../classes/general/config/options.xml')) {
            $options = simplexml_load_file($this->INSTALL_PATH . '/../classes/general/config/options.xml');

            foreach ($options->contragents->contragent as $contragent) {
                $type['NAME'] = $APPLICATION->ConvertCharset((string)$contragent, 'utf-8', SITE_CHARSET);
                $type['ID'] = (string)$contragent['id'];
                $arResult['contragentType'][] = $type;
                unset ($type);
            }
            foreach($options->fields->field as $field) {
                $type['NAME'] = $APPLICATION->ConvertCharset((string)$field, 'utf-8', SITE_CHARSET);
                $type['ID'] = (string)$field['id'];

               if (!$field['group']) {
                    $arResult['orderProps'][] = $type;
                } else {
                    $groups = explode(',', (string) $field['group']);
                    foreach ($groups as $group) {
                        $type['GROUP'][] = trim($group);
                    }
                    $arResult['legalDetails'][] = $type;
                }
                unset($type);
            }
        }

        $this->installExport();
        $this->subscriptionSetup();

        if ($step == 11) {
            $arResult['arSites'] = RCrmActions::getSitesList();
            if (count($arResult['arSites']) < 2) {
                $step = 2;
            }
        }
        if ($step <= 1) {
            if (!CModule::IncludeModule('sale')) {
                $arResult['errCode'] = 'ERR_SALE';
            }

            if (!CModule::IncludeModule('iblock')) {
                $arResult['errCode'] = 'ERR_IBLOCK';
            }

            if (!CModule::IncludeModule('catalog')) {
                $arResult['errCode'] = 'ERR_CATALOG';
            }

            if ($api_host = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_API_HOST_OPTION , 0)) {
                $arResult['API_HOST'] = $api_host;
            }
            if ($api_key = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_API_KEY_OPTION, 0)) {
                $arResult['API_KEY'] = $api_key;
            }

            $APPLICATION->IncludeAdminFile(
                GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
            );
        } elseif ($step == 11) {
            //new page
            if (!CModule::IncludeModule('sale')) {
                $arResult['errCode'] = 'ERR_SALE';
            }

            if (!CModule::IncludeModule('iblock')) {
                $arResult['errCode'] = 'ERR_IBLOCK';
            }

            if (!CModule::IncludeModule('catalog')) {
                $arResult['errCode'] = 'ERR_CATALOG';
            }

            if (isset($arResult['errCode']) && $arResult['errCode']) {
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                );

                return false;
            }

            $api_host = htmlspecialchars(trim($_POST[Constants::CRM_API_HOST_OPTION]));
            $api_key = htmlspecialchars(trim($_POST[Constants::CRM_API_KEY_OPTION]));

            // form correct url
            $api_host = parse_url($api_host);

            if ($api_host['scheme'] !== 'https') {
                $api_host['scheme'] = 'https';
            }

            $api_host = $api_host['scheme'] . '://' . $api_host['host'];

            if (!$api_host || !$api_key) {
                $arResult['errCode'] = 'ERR_FIELDS_API_HOST';
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                );

                return false;
            }

            $shopResponse = $this->getReferenceShops($api_host, $api_key);

            if (isset($shopResponse['sitesList'])) {
                $arResult['sitesList'] = $shopResponse['sitesList'];
            } elseif (isset($shopResponse['errCode'])) {
                $arResult['errCode'] = $shopResponse['errCode'];
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                );

                return false;
            }

            COption::SetOptionString($this->MODULE_ID, Constants::CRM_API_HOST_OPTION, $api_host);
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_API_KEY_OPTION, $api_key);

            if ($sites_list = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_SITES_LIST, 0)) {
                $arResult['SITES_LIST'] = unserialize($sites_list);
            }

            $APPLICATION->IncludeAdminFile(
                GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step11.php'
            );
        } elseif ($step == 2) {
            if (!CModule::IncludeModule('sale')) {
                $arResult['errCode'] = 'ERR_SALE';
            }
            if (!CModule::IncludeModule('iblock')) {
                $arResult['errCode'] = 'ERR_IBLOCK';
            }
            if (!CModule::IncludeModule('catalog')) {
                $arResult['errCode'] = 'ERR_CATALOG';
            }

            if (isset($arResult['errCode']) && $arResult['errCode']) {
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                );

                return false;
            }

            $arResult['arSites'] = RCrmActions::getSitesList();

            if (count($arResult['arSites']) > 1) {
                $api_host = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_HOST_OPTION, 0);
                $api_key = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_KEY_OPTION, 0);
                $isEmptySites = true;

                foreach ($arResult['arSites'] as $site) {
                    $siteCode[$site['LID']] = null;

                    if ($_POST['sites-id-' . $site['LID']] && !empty($_POST['sites-id-' . $site['LID']])) {
                        $siteCode[$site['LID']] = htmlspecialchars(trim($_POST['sites-id-' . $site['LID']]));
                        $isEmptySites = false;
                    }
                }

                if ($isEmptySites) {
                    $arResult['errCode'] = 'ERR_EMPTY_SITES_LIST';
                }

                $arResult['arCurrencySites'] = RCrmActions::getCurrencySites();
                $result = $this->getReferenceShops($api_host, $api_key);

                if (isset($result['errCode'])) {
                    $arResult['errCode'] = $result['errCode'];
                } else {
                    $arResult['sitesList'] = $result['sitesList'];
                }

                foreach ($arResult['arSites'] as $bitrixSite) {
                    $LID = $bitrixSite['LID'] ?? null;
                    $cmsCurrency = $arResult['arCurrencySites'][$LID] ?? null;

                    if (isset($arResult['sitesList'][$siteCode[$LID]])) {
                        $crmCurrency = $arResult['sitesList'][$siteCode[$LID]]['currency'] ?? null;
                        $arResult['errCode'] = CurrencyService::validateCurrency($cmsCurrency, $crmCurrency);
                    }
                }

                if (count($arResult['arSites']) != count($siteCode)) {
                    $arResult['errCode'] = 'ERR_FIELDS_API_HOST';
                }

                if (!empty($arResult['errCode'])) {
                    $APPLICATION->IncludeAdminFile(
                        GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step11.php'
                    );

                    return false;
                }

                $this->RETAIL_CRM_API = new ApiClient($api_host, $api_key);
                COption::SetOptionString($this->MODULE_ID, Constants::CRM_SITES_LIST, serialize($siteCode));
            } else {
                $api_host = htmlspecialchars(trim($_POST[Constants::CRM_API_HOST_OPTION]));
                $api_key = htmlspecialchars(trim($_POST[Constants::CRM_API_KEY_OPTION]));

                // form correct url
                $api_host = parse_url($api_host);
                if ($api_host['scheme'] != 'https') {
                    $api_host['scheme'] = 'https';
                }
                $api_host = $api_host['scheme'] . '://' . $api_host['host'];

                if (!$api_host || !$api_key) {
                    $arResult['errCode'] = 'ERR_FIELDS_API_HOST';
                    $APPLICATION->IncludeAdminFile(
                        GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                    );

                    return false;
                }

                $shopResponse = $this->getReferenceShops($api_host, $api_key);

                if (isset($shopResponse['sitesList'])) {
                    $arResult['sitesList'] = $shopResponse['sitesList'];
                } elseif (isset($shopResponse['errCode'])) {
                    $arResult['errCode'] = $shopResponse['errCode'];
                    $APPLICATION->IncludeAdminFile(
                        GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                    );

                    return false;
                }

                $this->RETAIL_CRM_API = new ApiClient($api_host, $api_key);
                COption::SetOptionString($this->MODULE_ID, Constants::CRM_API_HOST_OPTION, $api_host);
                COption::SetOptionString($this->MODULE_ID, Constants::CRM_API_KEY_OPTION, $api_key);
                COption::SetOptionString($this->MODULE_ID, Constants::CRM_SITES_LIST, serialize([]));

                try {
                    $credentials = $this->RETAIL_CRM_API->getCredentials();

                    COption::SetOptionString(
                        $this->MODULE_ID,
                        Constants::SITES_AVAILABLE,
                        $credentials->sitesAvailable[0] ?? ''
                    );
                } catch (ArgumentOutOfRangeException | CurlException $exception) {
                    $arResult['errCode'] = 'ERR_GET_SITE_CRM';

                    $APPLICATION->IncludeAdminFile(
                        GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                    );

                    return false;
                }

            }

            try {
                $credentials = $this->RETAIL_CRM_API->getCredentials()->getResponseBody();
                $residualRight = array_diff(Constants::REQUIRED_API_SCOPES, $credentials['scopes']);

                if (count($residualRight) !== 0) {
                    throw new \InvalidArgumentException(sprintf(GetMessage('ERR_403'), implode(', ', $residualRight)));
                }


                $arResult['orderTypesList'] = $this->RETAIL_CRM_API->orderTypesList()->orderTypes;
                $arResult['deliveryTypesList'] = $this->RETAIL_CRM_API->deliveryTypesList()->deliveryTypes;
                $arResult['deliveryServicesList'] = $this->RETAIL_CRM_API->deliveryServicesList()->deliveryServices;
                $arResult['paymentTypesList'] = $this->RETAIL_CRM_API->paymentTypesList()->paymentTypes;
                $arResult['paymentStatusesList'] = $this->RETAIL_CRM_API->paymentStatusesList()->paymentStatuses;
                $arResult['paymentList'] = $this->RETAIL_CRM_API->statusesList()->statuses;
                $arResult['paymentGroupList'] = $this->RETAIL_CRM_API->statusGroupsList()->statusGroups;
            } catch (CurlException $e) {
                RCrmActions::eventLog(
                    'intaro.retailcrm/install/index.php', 'RetailCrm\ApiClient::*List::CurlException',
                    $e->getCode() . ': ' . $e->getMessage()
                );
            } catch (\InvalidArgumentException $e) {
                $arResult['errCode'] = $e->getMessage();

                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                );

                return false;
            }

            $this->loadDeps();

            $availableSites = RetailcrmConfigProvider::getSitesList();

            if (!empty($availableSites)) {
                $availableSites = array_flip($availableSites);
            } else {
                $site = RetailcrmConfigProvider::getSitesAvailable();
                $availableSites[$site] = $site;
            }

            $arResult['deliveryTypesList'] = RetailCrmService::getAvailableTypes(
                $availableSites,
                $arResult['deliveryTypesList']
            );
            $arResult['paymentTypesList'] = RetailCrmService::getAvailableTypes(
                $availableSites,
                $arResult['paymentTypesList']
            );

            RetailcrmConfigProvider::setIntegrationDelivery(
                RetailCrmService::selectIntegrationDeliveries($arResult['deliveryTypesList'])
            );

            RetailcrmConfigProvider::setIntegrationPaymentTypes(
                RetailCrmService::selectIntegrationPayments($arResult['paymentTypesList'])
            );

            //bitrix personTypes
            $arResult['bitrixOrderTypesList'] = RCrmActions::OrderTypesList($arResult['arSites']);

            //bitrix deliveryList
            $arResult['bitrixDeliveryTypesList'] = RCrmActions::DeliveryList();

            //bitrix paymentList
            $arResult['bitrixPaymentTypesList'] = RCrmActions::PaymentList();

            //bitrix statusesList --statuses
            $arResult['bitrixStatusesList'] = RCrmActions::StatusesList();

            if ($order_types = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_ORDER_TYPES_ARR, 0)) {
                $arResult['ORDER_TYPES'] = array_flip(unserialize($order_types));
            }
            if ($delivery_types = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_DELIVERY_TYPES_ARR, 0)) {
                $arResult['DELIVERY_TYPES'] = array_flip(unserialize($delivery_types));
            }
            if ($payment_types = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_PAYMENT_TYPES, 0)) {
                $arResult['PAYMENT_TYPES'] = array_flip(unserialize($payment_types));
            }
            if ($payment_statuses = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_PAYMENT_STATUSES, 0)) {
                $arResult['PAYMENT_STATUSES'] = array_flip(unserialize($payment_statuses));
            }
            if ($payment = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_PAYMENT, 0)) {
                $arResult['PAYMENT'] = array_flip(unserialize($payment));
            }

            $APPLICATION->IncludeAdminFile(
                GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step2.php'
            );
        } elseif ($step == 3) {
            if (!CModule::IncludeModule('sale')) {
                $arResult['errCode'] = 'ERR_SALE';
            }

            if (isset($_POST['back']) && $_POST['back']) {
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step1.php'
                );
            }

            // api load
            $api_host = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_HOST_OPTION, 0);
            $api_key = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_KEY_OPTION, 0);
            $this->RETAIL_CRM_API = new ApiClient($api_host, $api_key);

            $useCrmOrderMethods = htmlspecialchars(trim($_POST['use_crm_order_methods'])) === 'Y' ? 'Y' : 'N';
            $crmOrderMethod = [];

            if ($useCrmOrderMethods === 'Y') {
                $crmOrderMethod = $_POST['crm_order_methods'];
            }
            COption::SetOptionString(
                $this->MODULE_ID,
                Constants::USE_CRM_ORDER_METHODS,
                $useCrmOrderMethods
            );

            COption::SetOptionString(
                $this->MODULE_ID,
                Constants::CRM_ORDER_METHODS,
                serialize(RCrmActions::clearArr(is_array($crmOrderMethod) ? $crmOrderMethod : []))
            );

            //bitrix orderTypesList
            $arResult['arSites'] = RCrmActions::getSitesList();
            $arResult['bitrixOrderTypesList'] = RCrmActions::OrderTypesList($arResult['arSites']);

            $orderTypesArr = [];
            foreach ($arResult['bitrixOrderTypesList'] as $orderType) {
                $orderTypesArr[$orderType['ID']] = htmlspecialchars(trim($_POST['order-type-' . $orderType['ID']]));
            }

            //bitrix deliveryTypesList
            $arResult['bitrixDeliveryTypesList'] = RCrmActions::DeliveryList();

            if (htmlspecialchars(trim($_POST['delivery-types-export'])) == 'false') {
                $deliveryTypesArr = [];
                foreach ($arResult['bitrixDeliveryTypesList'] as $delivery) {
                    $deliveryTypesArr[$delivery['ID']] = htmlspecialchars(trim($_POST['delivery-type-' . $delivery['ID']]));
                }
            } elseif (htmlspecialchars(trim($_POST['delivery-types-export'])) == 'true') {
                // send to intaro crm and save delivery types!
                $arDeliveryServiceAll = Manager::getActiveList();
                foreach ($arResult['bitrixDeliveryTypesList'] as $deliveryType) {
                    $load = true;
                    try {
                        $this->RETAIL_CRM_API->deliveryTypesEdit(RCrmActions::clearArr([
                            'code'         => $deliveryType['ID'],
                            'name'         => RCrmActions::toJSON($deliveryType['NAME']),
                            'defaultCost'  => $deliveryType['CONFIG']['MAIN']['PRICE'],
                            'description'  => RCrmActions::toJSON($deliveryType['DESCRIPTION']),
                            'paymentTypes' => '',
                        ]));
                    } catch (CurlException $e) {
                        $load = false;
                        RCrmActions::eventLog(
                            'intaro.crm/install/index.php', 'RetailCrm\ApiClient::deliveryTypeEdit::CurlException',
                            $e->getCode() . ': ' . $e->getMessage()
                        );
                    }
                    if ($load) {
                        $deliveryTypesArr[$deliveryType['ID']] = $deliveryType['ID'];
                        foreach ($arDeliveryServiceAll as $deliveryService) {
                            if ($deliveryService['PARENT_ID'] != 0 && $deliveryService['PARENT_ID'] == $deliveryType['ID']) {
                                try {
                                    $this->RETAIL_CRM_API->deliveryServicesEdit(RCrmActions::clearArr([
                                        'code'         => 'bitrix-' . $deliveryService['ID'],
                                        'name'         => RCrmActions::toJSON($deliveryService['NAME']),
                                        'deliveryType' => $deliveryType['ID'],
                                    ]));
                                } catch (CurlException $e) {
                                    RCrmActions::eventLog(
                                        'intaro.crm/install/index.php', 'RetailCrm\ApiClient::deliveryServiceEdit::CurlException',
                                        $e->getCode() . ': ' . $e->getMessage()
                                    );
                                }
                            }
                        }
                    }
                }
            }

            //bitrix paymentTypesList
            $arResult['bitrixPaymentTypesList'] = RCrmActions::PaymentList();

            $paymentTypesArr = [];
            foreach ($arResult['bitrixPaymentTypesList'] as $payment) {
                $paymentTypesArr[$payment['ID']] = htmlspecialchars(trim($_POST['payment-type-' . $payment['ID']]));
            }

            //bitrix statusesList
            $arResult['bitrixStatusesList'] = RCrmActions::StatusesList();

            $paymentStatusesArr = [];
            $canselOrderArr     = [];

            foreach ($arResult['bitrixStatusesList'] as $status) {
                $paymentStatusesArr[$status['ID']] = htmlspecialchars(trim($_POST['payment-status-' . $status['ID']]));
                if (trim($_POST['order-cansel-' . $status['ID']]) == 'Y') {
                    $canselOrderArr[] = $status['ID'];
                }
            }

            //form payment ids arr
            $paymentArr      = [];
            $paymentArr['Y'] = htmlspecialchars(trim($_POST['payment-Y']));
            $paymentArr['N'] = htmlspecialchars(trim($_POST['payment-N']));

            //new page
            //form orderProps
            $arResult['arProp'] = RCrmActions::OrderPropsList();
            $arResult['locationProp'] = RCrmActions::getLocationProps();

            $request = Application::getInstance()->getContext()->getRequest();

            if ($request->isHttps() === true) {
                COption::SetOptionString($this->MODULE_ID, Constants::PROTOCOL, 'https://');
            } else {
                COption::SetOptionString($this->MODULE_ID, Constants::PROTOCOL, 'http://');
            }

            // Set order types
            if (!empty($orderTypesArr)) {
                COption::SetOptionString(
                    $this->MODULE_ID,
                    Constants::CRM_ORDER_TYPES_ARR,
                    serialize(RCrmActions::clearArr($orderTypesArr)
                    )
                );
            }

            // Set delivery types
            if (!empty($deliveryTypesArr)) {
                COption::SetOptionString(
                    $this->MODULE_ID,
                    Constants::CRM_DELIVERY_TYPES_ARR,
                    serialize(RCrmActions::clearArr($deliveryTypesArr)
                    )
                );
            }

            // Set payment types
            if (!empty($paymentTypesArr)) {
                COption::SetOptionString(
                    $this->MODULE_ID,
                    Constants::CRM_PAYMENT_TYPES,
                    serialize(RCrmActions::clearArr($paymentTypesArr)
                    )
                );
            }

            // Set payment statuses
            if (!empty($paymentStatusesArr)) {
                COption::SetOptionString(
                    $this->MODULE_ID,
                    Constants::CRM_PAYMENT_STATUSES,
                    serialize(RCrmActions::clearArr($paymentStatusesArr)
                    )
                );
            }

            // Set payments
            if (!empty($paymentArr)) {
                COption::SetOptionString(
                    $this->MODULE_ID,
                    Constants::CRM_PAYMENT,
                    serialize(RCrmActions::clearArr($paymentArr)
                    )
                );
            }

            COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_LAST_ID, 0);
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_DISCHARGE, 1);
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_FAILED_IDS, serialize([]));

            // Set cansel order
            if (!empty($canselOrderArr)) {
                COption::SetOptionString(
                    $this->MODULE_ID,
                    Constants::CRM_CANCEL_ORDER,
                    serialize(RCrmActions::clearArr($canselOrderArr)
                    )
                );
            }

            if ($orderProps = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_ORDER_PROPS, 0)) {
                $arResult['ORDER_PROPS'] = unserialize($orderProps);
            }
            if ($legalDetails = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_LEGAL_DETAILS, 0)) {
                $arResult['LEGAL_DETAILS'] = unserialize($legalDetails);
            }
            if ($contragentType = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_CONTRAGENT_TYPE, 0)) {
                $arResult['CONTRAGENT_TYPES'] = unserialize($contragentType);
            }

            $APPLICATION->IncludeAdminFile(
                GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step3.php'
            );
        } elseif ($step == 4) {
            if (!CModule::IncludeModule('sale')) {
                $arResult['errCode'] = 'ERR_SALE';
            }

            if (isset($_POST['back']) && $_POST['back']) {
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step2.php'
                );
            }

            //order upload
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && (strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                && isset($_POST['ajax'])
                && $_POST['ajax'] == 1
            ) {
                $historyTime = Date('');
                $this->loadDeps();
                RetailCrmOrder::uploadOrders(); // each 50

                $lastUpOrderId = COption::GetOptionString($this->MODULE_ID, Constants::CRM_ORDER_LAST_ID, 0);
                $countLeft = (int) OrderTable::getCount(['>ID' => $lastUpOrderId]);
                $countAll = (int) OrderTable::getCount();

                if (!isset($_POST['finish'])) {
                    $finish = 0;
                } else {
                    $finish = (int) $_POST['finish'];
                }

                if (!$countAll) {
                    $percent = 100;
                } else {
                    $percent = round(100 - ($countLeft * 100 / $countAll), 1);
                }

                if (!$countLeft) {
                    $finish = 1;
                }

                $APPLICATION->RestartBuffer();
                header('Content-Type: application/x-javascript; charset=' . LANG_CHARSET);
                die(json_encode(['finish' => $finish, 'percent' => $percent]));
            }

            if (isset($_POST['back']) && $_POST['back']) {
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step2.php'
                );
            }

            //bitrix orderTypesList
            $orderTypesList = RCrmActions::OrderTypesList(RCrmActions::getSitesList());

            $orderTypesArr = [];
            $addressDetailOptions = [];

            foreach ($orderTypesList as $orderType) {
                $orderTypesArr[$orderType['ID']] = htmlspecialchars(trim($_POST['order-type-' . $orderType['ID']]));
                $addressDetailOptions[$orderType['ID']] = $_POST['address-detail-' . $orderType['ID']];
            }

            $orderPropsArr = [];
            foreach ($orderTypesList as $orderType) {
                $propsCount     = 0;
                $_orderPropsArr = [];
                foreach ($arResult['orderProps'] as $orderProp) {
                    if ((!(int) htmlspecialchars(trim($_POST['address-detail-' . $orderType['ID']]))) && $propsCount > 4){
                        break;
                    }
                    $_orderPropsArr[$orderProp['ID']] = htmlspecialchars(trim($_POST['order-prop-' . $orderProp['ID'] . '-' . $orderType['ID']]));
                    $propsCount++;
                }
                $orderPropsArr[$orderType['ID']] = $_orderPropsArr;
            }

            //legal details props
            $legalDetailsArr = [];
            foreach ($orderTypesList as $orderType) {
                $_legalDetailsArr = [];
                foreach ($arResult['legalDetails'] as $legalDetails) {
                    $_legalDetailsArr[$legalDetails['ID']] = htmlspecialchars(trim($_POST['legal-detail-' . $legalDetails['ID'] . '-' . $orderType['ID']]));
                }
                $legalDetailsArr[$orderType['ID']] = $_legalDetailsArr;
            }

            //contragents type list
            $contragentTypeArr = [];
            foreach ($orderTypesList as $orderType) {
                $contragentTypeArr[$orderType['ID']] = htmlspecialchars(trim($_POST['contragent-type-' . $orderType['ID']]));
            }

            COption::SetOptionString($this->MODULE_ID, Constants::CRM_ADDRESS_OPTIONS, serialize($addressDetailOptions));
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_PROPS, serialize(RCrmActions::clearArr($orderPropsArr)));
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_LEGAL_DETAILS, serialize(RCrmActions::clearArr($legalDetailsArr)));
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_CONTRAGENT_TYPE, serialize(RCrmActions::clearArr($contragentTypeArr)));

            $APPLICATION->IncludeAdminFile(
                GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step4.php'
            );

        } elseif ($step == 5) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && (strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                && isset($_POST['ajax'])
                && $_POST['ajax'] == 1
            ) {
                CModule::IncludeModule('highloadblock');
                $rsData               = HighloadBlockTable::getList(['filter' => ['TABLE_NAME' => $_POST['table']]]);
                $hlblockArr           = $rsData->Fetch();
                $hlblock              = HighloadBlockTable::getById($hlblockArr['ID'])->fetch();
                $entity               = HighloadBlockTable::compileEntity($hlblock);
                $hbFields             = $entity->getFields();
                $hlblockList['table'] = $hlblockArr['TABLE_NAME'];

                foreach ($hbFields as $hbFieldCode => $hbField) {
                    $hlblockList['fields'][] = $hbFieldCode;
                }

                $APPLICATION->RestartBuffer();
                header('Content-Type: application/x-javascript; charset=' . LANG_CHARSET);
                die(json_encode($hlblockList));
            }
            if (!CModule::IncludeModule('iblock')) {
                $arResult['errCode'] = 'ERR_IBLOCK';
            }
            if (!CModule::IncludeModule('catalog')) {
                $arResult['errCode'] = 'ERR_CATALOG';
            }
            if (!CModule::IncludeModule('sale')) {
                $arResult['errCode'] = 'ERR_SALE';
            }

            $api_host = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_HOST_OPTION, 0);
            $api_key = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_KEY_OPTION, 0);
            $api = new ApiClient($api_host, $api_key);

            $customerH = $this->historyLoad($api, 'customersHistory');
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_CUSTOMERS_HISTORY_SINCE_ID, $customerH);

            //new data
            if ($historyDate = COption::GetOptionString($this->OLD_MODULE_ID, 'order_history_date', 0)) {
                try {
                    $history = $api->ordersHistory(['startDate' => $historyDate]);
                } catch (CurlException $e) {
                    RCrmActions::eventLog(
                        'intaro.retailcrm/install/index.php', 'RetailCrm\RestApi::ordersHistory::CurlException',
                        $e->getCode() . ': ' . $e->getMessage()
                    );

                } catch (InvalidArgumentException $e) {
                    RCrmActions::eventLog(
                        'intaro.retailcrm/install/index.php', 'RetailCrm\RestApi::ordersHistory::InvalidArgumentException',
                        $e->getCode() . ': ' . $e->getMessage()
                    );
                }

                if (isset($history['history'])) {
                    $hIs    = (int) $history['history'][0]['id'] - 1;
                    $orderH = $hIs;
                } else {
                    $orderH = $this->historyLoad($api, 'ordersHistory');
                }
            } else {
                $orderH = $this->historyLoad($api, 'ordersHistory');
            }

            COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_HISTORY, $orderH);

            if ($orderLastId = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_ORDER_LAST_ID, 0)) {
                COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_LAST_ID, $orderLastId);
            } else {
                $dbOrder = OrderTable::GetList([
                    'order'  => ['ID' => 'DESC'],
                    'limit'  => 1,
                    'select' => ['ID'],
                ]);
                $arOrder = $dbOrder->fetch();
                if (!empty($arOrder['ID'])) {
                    COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_LAST_ID, $arOrder['ID']);
                } else {
                    COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_LAST_ID, 0);
                }
            }

            if ($orderFailedIds = COption::GetOptionString($this->OLD_MODULE_ID, Constants::CRM_ORDER_FAILED_IDS, 0)) {
                COption::SetOptionString($this->MODULE_ID, Constants::CRM_ORDER_FAILED_IDS, $orderFailedIds);
            }

            $arResult['PRICE_TYPES'] = [];

            $dbPriceType = CCatalogGroup::GetList(
                ['SORT' => 'ASC'], [], [], [], ['ID', 'NAME', 'BASE']
            );

            while ($arPriceType = $dbPriceType->Fetch()) {
                $arResult['PRICE_TYPES'][$arPriceType['ID']] = $arPriceType;
            }

            if (isset($_POST['back']) && $_POST['back']) {
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step3.php'
                );
            }

            $APPLICATION->IncludeAdminFile(
                GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step5.php'
            );
        } elseif ($step == 6) {
            if (!CModule::IncludeModule('iblock')) {
                $arResult['errCode'] = 'ERR_IBLOCK';
            }
            if (!CModule::IncludeModule('catalog')) {
                $arResult['errCode'] = 'ERR_CATALOG';
            }
            if (!CModule::IncludeModule('sale')) {
                $arResult['errCode'] = 'ERR_SALE';
            }

            if (isset($arResult['errCode']) && $arResult['errCode']) {
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step5.php'
                );

                return false;
            }

            if (isset($_POST['back']) && $_POST['back']) {
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step4.php'
                );
            }

            $hlblockModule = false;

            if (CModule::IncludeModule('highloadblock')) {
                $hlblockModule = true;
                $hlblockList   = [];
                $hlblockListDb = HighloadBlockTable::getList();

                while ($hlblockArr = $hlblockListDb->Fetch()) {
                    $hlblock = HighloadBlockTable::getById($hlblockArr['ID'])->fetch();
                    $entity = HighloadBlockTable::compileEntity($hlblock);
                    $hbFields = $entity->getFields();
                    $hlblockList[$hlblockArr['TABLE_NAME']]['LABEL'] = $hlblockArr['NAME'];

                    foreach ($hbFields as $hbFieldCode => $hbField) {
                        $hlblockList[$hlblockArr['TABLE_NAME']]['FIELDS'][] = $hbFieldCode;
                    }
                }
            }

            $iblockProperties = [
                'article'      => 'article',
                'manufacturer' => 'manufacturer',
                'color'        => 'color',
                'weight'       => 'weight',
                'size'         => 'size',
                'length'       => 'length',
                'width'        => 'width',
                'height'       => 'height',
                'picture'      => 'picture',
            ];

            $propertiesSKU     = [];
            $propertiesUnitSKU = [];
            $propertiesHbSKU   = [];

            foreach ($iblockProperties as $prop) {
                foreach ($_POST['iblockPropertySku'. '_' . $prop] as $iblock => $val) {
                    $propertiesSKU[$iblock][$prop] = $val;
                }
                foreach ($_POST['iblockPropertyUnitSku'. '_' . $prop] as $iblock => $val) {
                    $propertiesUnitSKU[$iblock][$prop] = $val;
                }

                if ($hlblockModule === true && $prop !== 'picture') {
                    foreach ($hlblockList as $tableName => $hb) {
                        foreach ($_POST['highloadblock' . $tableName . '_' . $prop] as $iblock => $val) {
                            $propertiesHbSKU[$tableName][$iblock][$prop] = $val;
                        }
                    }
                }
            }

            $propertiesProduct = [];
            $propertiesUnitProduct = [];
            $propertiesHbProduct = [];

            foreach ($iblockProperties as $prop) {
                foreach ($_POST['iblockPropertyProduct'. '_' . $prop] as $iblock => $val) {
                    $propertiesProduct[$iblock][$prop] = $val;
                }
                foreach ($_POST['iblockPropertyUnitProduct'. '_' . $prop] as $iblock => $val) {
                    $propertiesUnitProduct[$iblock][$prop] = $val;
                }

                if ($hlblockModule === true && $prop !== 'picture') {
                    foreach ($hlblockList as $tableName => $hb) {
                        foreach ($_POST['highloadblock_product' . $tableName . '_' . $prop] as $iblock => $val) {
                            $propertiesHbProduct[$tableName][$iblock][$prop] = $val;
                        }
                    }
                }
            }

            $iblocks = $_POST['iblockExport'] ?? null;
            $filename = $_POST['SETUP_FILE_NAME'] ?? null;
            $maxOffers = $_POST['maxOffersValue'] ?? null;
            $profileName = $_POST['SETUP_PROFILE_NAME'] ?? null;
            $loadPurchasePrice = $_POST['loadPurchasePrice'] ?? null;
            $useVatRateCatalog = $_POST['useVatRateCatalog'] ?? null;
            $loadInactiveProduct = $_POST['loadNonActivity'] ?? null;

            if ($iblocks === null) {
                $arResult['errCode'] = 'ERR_FIELDS_IBLOCK';
            }

            if ($profileName === null) {
                $arResult['errCode'] = 'ERR_FIELDS_PROFILE';
            }

            if ($filename === null) {
                $arResult['errCode'] = 'ERR_FIELDS_FILE';
            }

            if (isset($arResult['errCode']) && $arResult['errCode']) {
                $arOldValues = [
                    'iblockExport' => $iblocks,
                    'iblockPropertySku' => $propertiesSKU,
                    'iblockPropertyUnitSku' => $propertiesUnitSKU,
                    'iblockPropertyProduct' => $propertiesProduct,
                    'iblockPropertyUnitProduct' => $propertiesUnitProduct,
                    'SETUP_FILE_NAME' => $filename,
                    'SETUP_PROFILE_NAME' => $profileName,
                    'maxOffersValue' => $maxOffers,
                    'loadPurchasePrice' => $loadPurchasePrice,
                    'useVatRateCatalog' => $useVatRateCatalog,
                    'loadNonActivity' => $loadInactiveProduct,
                ];

                global $oldValues;

                $oldValues = $arOldValues;
                $APPLICATION->IncludeAdminFile(
                    GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step5.php'
                );

                return false;
            }

            RegisterModule($this->MODULE_ID);
            RegisterModuleDependences('sale', 'OnOrderUpdate', $this->MODULE_ID, 'RetailCrmEvent', 'onUpdateOrder');
            RegisterModuleDependences('main', 'OnAfterUserUpdate', $this->MODULE_ID, 'RetailCrmEvent', 'OnAfterUserUpdate');
            RegisterModuleDependences('sale', 'OnSaleOrderDeleted', $this->MODULE_ID, 'RetailCrmEvent', 'orderDelete');
            RegisterModuleDependences('sale', 'OnSalePaymentEntitySaved', $this->MODULE_ID, 'RetailCrmEvent', 'paymentSave');
            RegisterModuleDependences('sale', 'OnSalePaymentEntityDeleted', $this->MODULE_ID, 'RetailCrmEvent', 'paymentDelete');
            RegisterModuleDependences('main', 'OnAfterUserRegister', $this->MODULE_ID, 'RetailCrmEvent', 'OnAfterUserRegister');
            RegisterModuleDependences('main', 'OnAfterUserAdd', $this->MODULE_ID, 'RetailCrmEvent', 'OnAfterUserAdd');
            RegisterModuleDependences('sale', 'OnSaleOrderSaved', $this->MODULE_ID, 'RetailCrmEvent', 'orderSave', 99);

            COption::SetOptionString($this->MODULE_ID, Constants::CRM_CATALOG_BASE_PRICE, htmlspecialchars(trim($_POST['price-types'])));
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_INVENTORIES_UPLOAD, 'N');
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_PRICES_UPLOAD, 'N');
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_COLLECTOR, 'N');
            COption::SetOptionString($this->MODULE_ID, Constants::CRM_UA, 'N');

            //agent
            $dateAgent = new DateTime();
            $intAgent = new DateInterval('PT60S'); // PT60S - 60 sec;
            $dateAgent->add($intAgent);

            CAgent::AddAgent(
                'RCrmActions::orderAgent();',
                $this->MODULE_ID,
                'N',
                600, // interval - 10 min
                $dateAgent->format('d.m.Y H:i:s'), // date of first check
                'Y', // agent is active
                $dateAgent->format('d.m.Y H:i:s'), // date of first start
                30
            );

            COption::RemoveOption($this->MODULE_ID, Constants::CRM_CATALOG_BASE_PRICE);

            if (
                file_exists($_SERVER['DOCUMENT_ROOT']
                    . '/bitrix/php_interface/include/catalog_export/'
                    . $this->RETAIL_CRM_EXPORT
                    . '_run.php')
            ) {
                $dbProfile = CCatalogExport::GetList([], ['FILE_NAME' => $this->RETAIL_CRM_EXPORT]);

                if ($dbProfile instanceof CDBResult) {
                    $this->removeExportProfiles($dbProfile);

                }
            }


            $setupVars = $this->getProfileSetupVars(
                $iblocks,
                [
                    'iblockPropertySku' => $propertiesSKU,
                    'iblockPropertyUnitSku' => $propertiesUnitSKU,
                    'iblockPropertyProduct' => $propertiesProduct,
                    'iblockPropertyUnitProduct' => $propertiesUnitProduct,
                ],
                $propertiesHbSKU,
                $propertiesHbProduct,
                $filename,
                $maxOffers,
                $loadPurchasePrice,
                $useVatRateCatalog,
                $loadInactiveProduct
            );

            $profileId = CCatalogExport::Add([
                'LAST_USE'        => false,
                'FILE_NAME'       => $this->RETAIL_CRM_EXPORT,
                'NAME'            => $profileName,
                'DEFAULT_PROFILE' => 'N',
                'IN_MENU'         => 'N',
                'IN_AGENT'        => 'N',
                'IN_CRON'         => 'N',
                'NEED_EDIT'       => 'N',
                'SETUP_VARS'      => $setupVars,
            ]);

            if ((int) $profileId <= 0) {
                $arResult['errCode'] = 'ERR_IBLOCK';

                return false;
            }

            COption::SetOptionString(
                $this->MODULE_ID,
                Constants::CRM_CATALOG_BASE_PRICE . '_' . $profileId,
                htmlspecialchars(trim($_POST['price-types']))
            );

            $agentId = null;

            if (isset($_POST['NEED_CATALOG_AGENT'])) {
                $agentId = CAgent::AddAgent(
                    'CCatalogExport::PreGenerateExport(' . $profileId . ');',
                    'catalog',
                    'N'
                );

                CCatalogExport::Update($profileId, [
                    'IN_AGENT' => 'Y',
                ]);
            }

            if (isset($_POST['LOAD_NOW']) && $agentId === null) {
                CCatalogExport::PreGenerateExport($profileId);
            }

            $api_host = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_HOST_OPTION, 0);
            $api_key = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_KEY_OPTION, 0);
            $this->RETAIL_CRM_API = new ApiClient($api_host, $api_key);

            RCrmActions::sendConfiguration($this->RETAIL_CRM_API);

            $APPLICATION->IncludeAdminFile(
                GetMessage('MODULE_INSTALL_TITLE'), $this->INSTALL_PATH . '/step6.php'
            );
        }
    }

    public function DoUninstall()
    {
        global $APPLICATION;

        require_once($this->INSTALL_PATH . '/../classes/general/Http/Client.php');
        require_once($this->INSTALL_PATH . '/../classes/general/Response/ApiResponse.php');
        require_once($this->INSTALL_PATH . '/../classes/general/Exception/InvalidJsonException.php');
        require_once($this->INSTALL_PATH . '/../classes/general/Exception/CurlException.php');
        require_once($this->INSTALL_PATH . '/../classes/general/RCrmActions.php');
        require_once($this->INSTALL_PATH . '/../classes/general/Logger.php');
        require_once($this->INSTALL_PATH . '/../classes/general/ApiClient_v5.php');
        require_once($this->INSTALL_PATH . '/../classes/general/order/RetailCrmOrder_v5.php');
        require_once($this->INSTALL_PATH . '/../classes/general/history/RetailCrmHistory_v5.php');
        require_once($this->INSTALL_PATH . '/../lib/component/constants.php');
        require_once($this->INSTALL_PATH . '/../classes/general/cart/RetailCrmCart_v5.php');
        require_once($this->INSTALL_PATH . '/../lib/component/advanced/loyaltyinstaller.php');

        $api_host    = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_HOST_OPTION, 0);
        $api_key     = COption::GetOptionString($this->MODULE_ID, Constants::CRM_API_KEY_OPTION, 0);
        
        RCrmActions::sendConfiguration(new ApiClient($api_host, $api_key), false);

        CAgent::RemoveAgent('RCrmActions::orderAgent();', $this->MODULE_ID);
        CAgent::RemoveAgent('RetailCrmInventories::inventoriesUpload();', $this->MODULE_ID);
        CAgent::RemoveAgent('RetailCrmPrices::pricesUpload();', $this->MODULE_ID);

        COption::RemoveOption($this->MODULE_ID, Constants::CRM_API_HOST_OPTION);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_API_KEY_OPTION);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_DELIVERY_TYPES_ARR);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_INTEGRATION_DELIVERY);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_PAYMENT_TYPES);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_PAYMENT_STATUSES);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_PAYMENT);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_INTEGRATION_PAYMENT);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ORDER_LAST_ID);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ORDER_PROPS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ORDER_TYPES_ARR);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_LEGAL_DETAILS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_CONTRAGENT_TYPE);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_SITES_LIST);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ORDER_DISCHARGE);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ORDER_FAILED_IDS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_CUSTOMERS_HISTORY_SINCE_ID);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ORDER_HISTORY);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_CATALOG_BASE_PRICE);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_CURRENCY);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ADDRESS_OPTIONS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ORDER_NUMBERS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_CANCEL_ORDER);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_INVENTORIES_UPLOAD);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_STORES);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_SHOPS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_IBLOCKS_INVENTORIES);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_PRICES_UPLOAD);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_PRICES);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_PRICE_SHOPS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_IBLOCKS_PRICES);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_COLLECTOR);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_COLL_KEY);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_UA);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_UA_KEYS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_API_VERSION);
        COption::RemoveOption($this->MODULE_ID, Constants::HISTORY_TIME);
        COption::RemoveOption($this->MODULE_ID, Constants::CLIENT_ID);
        COption::RemoveOption($this->MODULE_ID, Constants::PROTOCOL);
        COption::RemoveOption($this->MODULE_ID, Constants::MATCHED_CUSTOM_PROPS);
        COption::RemoveOption($this->MODULE_ID, Constants::MATCHED_CUSTOM_USER_FIELDS);
        COption::RemoveOption($this->MODULE_ID, Constants::CRM_ORDER_METHODS);
        COption::RemoveOption($this->MODULE_ID, Constants::USE_CRM_ORDER_METHODS);

        if (CModule::IncludeModule('sale')) {
            UnRegisterModuleDependences('sale', 'OnSaleOrderSaved', $this->MODULE_ID, 'RetailCrmEvent', 'orderSave');
        }

        UnRegisterModuleDependences('sale', 'OnOrderUpdate', $this->MODULE_ID, 'RetailCrmEvent', 'onUpdateOrder');
        UnRegisterModuleDependences('main', 'OnAfterUserUpdate', $this->MODULE_ID, 'RetailCrmEvent', 'OnAfterUserUpdate');
        UnRegisterModuleDependences('sale', 'OnSaleOrderDeleted', $this->MODULE_ID, 'RetailCrmEvent', 'orderDelete');
        UnRegisterModuleDependences('main', 'OnBeforeProlog', $this->MODULE_ID, 'RetailCrmCollector', 'add');
        UnRegisterModuleDependences('main', 'OnBeforeProlog', $this->MODULE_ID, 'RetailCrmUa', 'add');
        UnRegisterModuleDependences('sale', 'OnSalePaymentEntitySaved', $this->MODULE_ID, 'RetailCrmEvent', 'paymentSave');
        UnRegisterModuleDependences('sale', 'OnSalePaymentEntityDeleted', $this->MODULE_ID, 'RetailCrmEvent', 'paymentDelete');
        UnRegisterModuleDependences('main', 'OnAfterUserRegister', $this->MODULE_ID, 'RetailCrmEvent', 'OnAfterUserRegister');
        UnRegisterModuleDependences('main', 'OnAfterUserAdd', $this->MODULE_ID, 'RetailCrmEvent', 'OnAfterUserAdd');

        if (
            CModule::IncludeModule('catalog')
            && file_exists($_SERVER['DOCUMENT_ROOT']
                . '/bitrix/php_interface/include/catalog_export/'
                . $this->RETAIL_CRM_EXPORT
                . '_run.php')
        ) {
            $dbProfile = CCatalogExport::GetList([], ['FILE_NAME' => $this->RETAIL_CRM_EXPORT]);

            if ($dbProfile instanceof CDBResult) {
                $this->removeExportProfiles($dbProfile);

            }
        }

        $this->deleteFiles();

        $loyalty = new LoyaltyInstaller();
        $loyalty->deleteLPEvents();

        UnRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            GetMessage('MODULE_UNINSTALL_TITLE'), $this->INSTALL_PATH . '/unstep1.php'
        );
    }

    public function deleteFiles(): void
    {
        $defaultSite = CSite::GetList($by, $sort, ['DEF' => 'Y'])->Fetch();

        unlink($_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/catalog_export/retailcrm_run.php');
        unlink($_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/catalog_export/retailcrm_setup.php');
        unlink($defaultSite['ABS_DOC_ROOT'] . '/retailcrm/agent.php');
        rmdir($defaultSite['ABS_DOC_ROOT'] . '/retailcrm/');
        DeleteDirFilesEx(
            $_SERVER['DOCUMENT_ROOT']
            . COption::GetOptionString('sale', 'path2user_ps_files')
            . 'retailcrmbonus'
        );
    }

    public function GetProfileSetupVars(
        $iblocks,
        $simpleProps,
        $propertiesHbSKU,
        $propertiesHbProduct,
        $filename,
        $maxOffers,
        $loadPurchasePrice,
        $useVatRateCatalog,
        $loadInactiveProduct
    ): string {
        $strVars = '';

        foreach ($iblocks as $key => $val) {
            $strVars .= 'iblockExport[' . $key . ']=' . $val . '&';
        }

        foreach ($simpleProps as $propType => $props) {
            $strVars = $this->addToStrVars($strVars, $propType, $props);
        }

        if ($propertiesHbSKU) {
            foreach ($propertiesHbSKU as $table => $arr) {
                $strVars = $this->addToStrVars($strVars, 'highloadblock' . $table, $arr);
            }
        }

        if ($propertiesHbProduct) {
            foreach ($propertiesHbProduct as $table => $arr) {
                $strVars = $this->addToStrVars($strVars, 'highloadblock_product' . $table, $arr);
            }
        }

        $additionalProperties = sprintf(
            'SETUP_FILE_NAME=%s&maxOffersValue=%s&loadPurchasePrice=%s&loadNonActivity=%s&useVatRateCatalog=%s',
            $filename,
            $maxOffers,
            $loadPurchasePrice,
            $loadInactiveProduct,
            $useVatRateCatalog
        );

        return $strVars . $additionalProperties;
    }

    /**
     * @param string $strVars
     * @param string $propType
     * @param array  $props
     *
     * @return string
     */
    public function addToStrVars(string $strVars, string $propType, array $props): string
    {
        foreach ($props as $iblock => $arr) {
            foreach ($arr as $id => $val) {
                $strVars .= $propType . '_' . $id . '[' . $iblock . ']=' . $val . '&';
            }
        }

        return $strVars;
    }

    public function historyLoad($api, $method)
    {
        $lastSinceId = 0;
        $startDate = new DateTime('-1 days');

        try {
            $historyResponse = $api->$method(['startDate' => $startDate->format('Y-m-d H:i:s')]);
        } catch (CurlException $e) {
            RCrmActions::eventLog(
                'RetailCrmHistory::m' . $method, 'RetailCrm\RestApi::' . $method . '::CurlException',
                $e->getCode() . ': ' . $e->getMessage()
            );

            return $lastSinceId;
        } catch (InvalidArgumentException $e) {
            RCrmActions::eventLog(
                'RetailCrmHistory::' . $method, 'RetailCrm\RestApi::' . $method . '::InvalidArgumentException',
                $e->getCode() . ': ' . $e->getMessage()
            );

            return $lastSinceId;
        }

        if (
            !$historyResponse instanceof ApiResponse
            || !$historyResponse->isSuccessful()
            || empty($historyResponse['history'])
            || empty($historyResponse['pagination'])
        ) {
            return $lastSinceId;
        }

        $startPage = $historyResponse['pagination']['currentPage'];
        $lastPage = $historyResponse['pagination']['totalPageCount'];

        for ($startPage; $startPage <= $lastPage; ++$startPage) {
            if ($historyResponse instanceof ApiResponse && !empty($historyResponse['history'])) {
                $history = $historyResponse['history'];
                $lastSinceId = end($history)['id'];

                $historyResponse = $api->$method(['sinceId' => $lastSinceId]);
            }
        }

        return $lastSinceId;
    }

    /**
     * Returns all sites connected to the current API key
     *
     * @param string $api_host
     * @param string $api_key
     *
     * @return array
     */
    private function getReferenceShops(string $api_host, string $api_key): array
    {
        global $APPLICATION;

        $client = new Client($api_host . '/api', ['apiKey' => $api_key]);
        $result = [];

        try {
            $credentials = $client->makeRequest('/credentials', 'GET')->getResponseBody();
            $residualRight = array_diff(Constants::REQUIRED_API_SCOPES, $credentials['scopes']);

            $siteResponse = $client->makeRequest('/v5/reference/sites', 'GET');
            $bitrixSites = RCrmActions::getSitesList();
            $currencySites = RCrmActions::getCurrencySites();
        } catch (CurlException $e) {
            RCrmActions::eventLog(
                'intaro.retailcrm/install/index.php', 'RetailCrm\ApiClient::sitesList',
                $e->getCode() . ': ' . $e->getMessage()
            );

            $result['errCode'] = 'ERR_' . $e->getCode();

            return $result;
        }

        // Проверка, что был получен корректный ответ
        if (isset($siteResponse) && $siteResponse->getStatusCode() === 200 && count($residualRight) === 0) {
            $sites = $siteResponse->sites ?? null;

            if ($sites === null) {
                $result['errCode'] = 'UNKNOWN_ERROR';
            }

            //Проверка количества магазинов, доступных по API
            if (count($bitrixSites) === 1 && count($sites) > 1) {
                $result['errCode'] = 'ERR_COUNT_SITES';
            }

            if (!isset($result['errCode']) && count($bitrixSites) === 1 ) {
                $LID = $bitrixSites[0]['LID'] ?? null;
                $cmsCurrency = $currencySites[$LID] ?? null;

                $crmSiteData = reset($sites);
                $crmCurrency = $crmSiteData['currency'] ?? null;

                $result['errCode'] = CurrencyService::validateCurrency($cmsCurrency, $crmCurrency);
            }

            if (empty($result['errCode'])) {
                ConfigProvider::setApiVersion(self::V5);

                $result['sitesList'] = $APPLICATION->ConvertCharsetArray($sites, 'utf-8', SITE_CHARSET);
            }
        } else {
            $result['errCode'] = sprintf(GetMessage('ERR_403'), implode(', ', $residualRight));
        }

        return $result;
    }

    /**
     * Remove ICML export profiles and the agent which ran that export.
     *
     * @param \CDBResult $dbProfile
     */
    private function removeExportProfiles(CDBResult $dbProfile): void
    {
        while ($arProfile = $dbProfile->Fetch()) {
            if ($arProfile['DEFAULT_PROFILE'] !== 'Y') {
                CAgent::RemoveAgent('CCatalogExport::PreGenerateExport(' . $arProfile['ID'] . ');', 'catalog');
                CCatalogExport::Delete($arProfile['ID']);
            }
        }
    }
}
