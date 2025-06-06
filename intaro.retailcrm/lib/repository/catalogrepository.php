<?php

/**
 * @category Integration
 * @package  Intaro\RetailCrm\Repository
 * @author   RetailCRM <integration@retailcrm.ru>
 * @license  MIT
 * @link     http://retailcrm.ru
 * @see      http://retailcrm.ru/docs
 */

namespace Intaro\RetailCrm\Repository;

use Bitrix\Catalog\StoreBarcodeTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\SectionElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\SystemException;
use CCatalogGroup;
use CCatalogSku;
use CCatalogStoreBarCode;
use CIBlockElement;
use Intaro\RetailCrm\Icml\QueryParamsMolder;
use Intaro\RetailCrm\Model\Bitrix\Orm\CatalogIblockInfo;
use Intaro\RetailCrm\Model\Bitrix\Xml\SelectParams;
use RetailcrmConfigProvider;

/**
 * Class CatalogRepository
 * @package Intaro\RetailCrm\Repository
 */
class CatalogRepository
{
    /**
     * @var \Intaro\RetailCrm\Icml\QueryParamsMolder
     */
    private $builder;

    /**
     * @var bool
     */
    private $isLoadNotActive = false;

    /**
     * CatalogRepository constructor.
     */
    public function __construct()
    {
        $this->builder = new QueryParamsMolder();
    }

    public function setLoadNotActive(bool $isLoad)
    {
        $this->isLoadNotActive = $isLoad;
    }

    /**
     * Получение категорий, к которым относится товар
     *
     * @param int $offerId
     * @return array
     */
    public function getProductCategories(int $offerId): array
    {
        try {
            $categories = SectionElementTable::query()
                ->addSelect('IBLOCK_SECTION.ID')
                ->addSelect('IBLOCK_SECTION.CODE')
                ->where('IBLOCK_ELEMENT_ID', $offerId)
                ->fetchAll();
        } catch (ObjectPropertyException | ArgumentException | SystemException $exception) {
            return [];
        }

        return $categories;
    }

    /**
     * Returns products IDs with barcodes by infoblock id
     *
     * @return array
     */
    public function getBarcodes(): array
    {
        $barcodes  = [];

        try {
            $arBarCodes = StoreBarcodeTable::query()
                ->addSelect('PRODUCT_ID')
                ->addSelect('BARCODE')
                ->fetchAll();
        } catch (ObjectPropertyException | ArgumentException | SystemException $exception) {
            return [];
        }

        foreach ($arBarCodes as $arBarCode){
            $barcodes[$arBarCode['PRODUCT_ID']] = $arBarCode['BARCODE'];
        }

        return $barcodes;
    }

    /**
     * @param \Intaro\RetailCrm\Model\Bitrix\Xml\SelectParams      $param
     * @param \Intaro\RetailCrm\Model\Bitrix\Orm\CatalogIblockInfo $catalogIblockInfo
     * @return \CIBlockResult|int
     */
    public function getProductPage(SelectParams $param, CatalogIblockInfo $catalogIblockInfo)
    {
        return CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $this->builder->getWhereForOfferPart($param->parentId, $catalogIblockInfo, $this->isLoadNotActive),
            false,
            ['nPageSize' => $param->nPageSize, 'iNumPage' => $param->pageNumber, 'checkOutOfRange' => true],
            $param->allParams
        );
    }

    /**
     * @param int $iblockId
     * @return  \Bitrix\Main\ORM\Objectify\Collection|null
     */
    public function getCategoriesByIblockId(int $iblockId): ?Collection
    {
        try {
            return SectionTable::query()
                ->addSelect('*')
                ->where('IBLOCK_ID', $iblockId)
                ->fetchCollection();
        } catch (ObjectPropertyException | ArgumentException | SystemException $exception) {
            return null;
        }
    }

    /**
     * @param $iblockId
     * @return EntityObject|null
     */
    public function getIblockById($iblockId): ?EntityObject
    {
        try {
            return IblockTable::query()
                ->where('ID', $iblockId)
                ->fetchObject();
        } catch (ObjectPropertyException | ArgumentException | SystemException $exception) {
            return null;
        }
    }

    /**
     * Возвращает информацию об инфоблоке торговых предложений по ID инфоблока товаров
     *
     * @param int $productIblockId
     * @return \Intaro\RetailCrm\Model\Bitrix\Orm\CatalogIblockInfo
     */
    public function getCatalogIblockInfo(int $productIblockId, $useVatRateCatalog): CatalogIblockInfo
    {
        $catalogIblockInfo = new CatalogIblockInfo();
        $info              = CCatalogSKU::GetInfoByProductIBlock($productIblockId);

        if ($info === false) {
            $catalogIblockInfo->productIblockId = $productIblockId;

            return $catalogIblockInfo;
        }

        if ($useVatRateCatalog) {
            $dataBlock = \Bitrix\Catalog\CatalogIblockTable::getList([
                'filter' => ['IBLOCK_ID' => $productIblockId]
            ])->fetch();

            if (!empty($dataBlock['VAT_ID'])) {
                $vatRate = \Bitrix\Catalog\VatTable::getList([
                    'filter' => ['ID' => $dataBlock['VAT_ID']]
                ])->fetch();

                if ($vatRate) {
                    $catalogIblockInfo->vatRate = $vatRate['RATE'];
                }
            }
        }

        $catalogIblockInfo->skuIblockId     = $info['IBLOCK_ID'];
        $catalogIblockInfo->productIblockId = $info['PRODUCT_IBLOCK_ID'];
        $catalogIblockInfo->skuPropertyId   = $info['SKU_PROPERTY_ID'];

        return $catalogIblockInfo;
    }

    /**
     * @param int|null $profileId
     * @return int
     */
    public static function getBasePriceId(?int $profileId): int
    {
        $basePriceId = RetailcrmConfigProvider::getCatalogBasePriceByProfile($profileId);

        if (!$basePriceId) {
            $dbPriceType = CCatalogGroup::GetList(
                [],
                ['BASE' => 'Y'],
                false,
                false,
                ['ID']
            );

            $result = $dbPriceType->GetNext();
            return $result['ID'];
        }

        return $basePriceId;
    }
}
