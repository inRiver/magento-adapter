<?php

/**
 * @author InRiver <iif-magento@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Model\ResourceModel\CallbackOperation;

use Inriver\Adapter\Model\Data\CallbackOperation as Model;
use Inriver\Adapter\Model\ResourceModel\CallbackOperation as ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Class Collection Collection
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     * @noinspection ReturnTypeCanBeDeclaredInspection
     */
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
