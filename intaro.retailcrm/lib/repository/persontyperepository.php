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

use Bitrix\Sale\Internals\PersonTypeTable;

/**
 * Class PersonTypeRepository
 *
 * @package Intaro\RetailCrm\Repository
 */
class PersonTypeRepository extends AbstractRepository
{
    /**
     * @param array $select
     * @param array $where
     *
     * @return \Bitrix\Main\Type\Collection|null|Bitrix\Sale\Internals\EO_PersonType_Collection
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getCollectionByWhere(array $select = ['*'], array $where = [])
    {
        return PersonTypeTable::query()
            ->setSelect($select)
            ->where($where)
            ->fetchCollection();
    }
}
