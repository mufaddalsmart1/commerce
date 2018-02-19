<?php

namespace craft\commerce\services;

use Craft;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use craft\commerce\events\SaleMatchEvent;
use craft\commerce\helpers\Currency;
use craft\commerce\models\Sale;
use craft\commerce\Plugin;
use craft\commerce\records\Sale as SaleRecord;
use craft\commerce\records\SaleCategory as SaleCategoryRecord;
use craft\commerce\records\SalePurchasable as SalePurchasableRecord;
use craft\commerce\records\SaleUserGroup as SaleUserGroupRecord;
use craft\db\Query;
use craft\elements\Category;
use yii\base\Component;
use yii\base\Exception;

/**
 * Sale service.
 *
 * @property Sale[] $allSales
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Sales extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SaleMatchEvent This event is raised after a sale has matched all other conditions
     */
    const EVENT_BEFORE_MATCH_PURCHASABLE_SALE = 'beforeMatchPurchasableSale';

    // Properties
    // =========================================================================

    /**
     * @var Sale[]
     */
    private $_allSales;

    /**
     * @var Sale[]
     */
    private $_allActiveSales;

    // Public Methods
    // =========================================================================

    /**
     * @param int $id
     * @return Sale|null
     */
    public function getSaleById($id)
    {
        foreach ($this->getAllSales() as $sale) {
            if ($sale->id == $id) {
                return $sale;
            }
        }

        return null;
    }

    /**
     * @return Sale[]
     */
    public function getAllSales(): array
    {
        if (null === $this->_allSales) {
            $sales = (new Query())->select(
                'sales.id,
                sales.name,
                sales.description,
                sales.dateFrom,
                sales.dateTo,
                sales.discountType,
                sales.discountAmount,
                sales.allGroups,
                sales.allPurchasables,
                sales.allCategories,
                sales.enabled,
                sp.purchasableId,
                spt.categoryId,
                sug.userGroupId')
                ->from('{{%commerce_sales}} sales')
                ->leftJoin('{{%commerce_sale_purchasables}} sp', '[[sp.saleId]] = [[sales.id]]')
                ->leftJoin('{{%commerce_sale_categories}} spt', '[[spt.saleId]] = [[sales.id]]')
                ->leftJoin('{{%commerce_sale_usergroups}} sug', '[[sug.saleId]] = [[sales.id]]')
                ->all();

            $allSalesById = [];
            $purchasables = [];
            $categories = [];
            $groups = [];

            foreach ($sales as $sale) {
                $id = $sale['id'];
                if ($sale['purchasableId']) {
                    $purchasables[$id][] = $sale['purchasableId'];
                }

                if ($sale['categoryId']) {
                    $categories[$id][] = $sale['categoryId'];
                }

                if ($sale['userGroupId']) {
                    $groups[$id][] = $sale['userGroupId'];
                }

                unset($sale['purchasableId'], $sale['userGroupId'], $sale['categoryId']);

                if (!isset($allSalesById[$id])) {
                    $allSalesById[$id] = new Sale($sale);
                }
            }

            foreach ($allSalesById as $id => $sale) {
                $sale->setPurchasableIds($purchasables[$id] ?? []);
                $sale->setCategoryIds($categories[$id] ?? []);
                $sale->setUserGroupIds($groups[$id] ?? []);
            }

            $this->_allSales = $allSalesById;
        }

        return $this->_allSales;
    }

    /**
     * Populates a sale's relations.
     *
     * @param Sale $sale
     */
    public function populateSaleRelations(Sale $sale)
    {
        $rows = (new Query())->select(
            'sp.purchasableId,
            spt.categoryId,
            sug.userGroupId')
            ->from('{{%commerce_sales}} sales')
            ->leftJoin('{{%commerce_sale_purchasables}} sp', '[[sp.saleId]]=[[sales.id]]')
            ->leftJoin('{{%commerce_sale_categories}} spt', '[[spt.saleId]]=[[sales.id]]')
            ->leftJoin('{{%commerce_sale_usergroups}} sug', '[[sug.saleId]]=[[sales.id]]')
            ->where(['[[sales.id]]' => $sale->id])
            ->all();

        $purchasableIds = [];
        $categoryIds = [];
        $userGroupIds = [];

        foreach ($rows as $row) {
            if ($row['purchasableId']) {
                $purchasableIds[] = $row['purchasableId'];
            }

            if ($row['categoryId']) {
                $categoryIds[] = $row['categoryId'];
            }

            if ($row['userGroupId']) {
                $userGroupIds[] = $row['userGroupId'];
            }
        }

        $sale->setPurchasableIds($purchasableIds);
        $sale->setCategoryIds($categoryIds);
        $sale->setUserGroupIds($userGroupIds);
    }

    /**
     * Returns the sales that match the purchasable.
     *
     * @param PurchasableInterface $purchasable
     * @param Order|null $order
     * @return Sales[]
     */
    public function getSalesForPurchasable(PurchasableInterface $purchasable, Order $order = null): array
    {
        $matchedSales = [];

        foreach ($this->_getAllEnabledSales() as $sale) {
            if ($this->matchPurchasableAndSale($purchasable, $sale, $order)) {
                $matchedSales[] = $sale;
            }
        }

        return $matchedSales;
    }

    /**
     * Returns the salePrice of the purchasable based on all the sales.
     *
     * @param PurchasableInterface $purchasable
     * @param Order|null $order
     * @return float
     */
    public function getSalePriceForPurchasable(PurchasableInterface $purchasable, Order $order = null): float
    {
        $sales = $this->getSalesForPurchasable($purchasable, $order);
        $salePrice = $purchasable->getPrice();

        /** @var Sale $sale */
        foreach ($sales as $sale) {
            $salePrice = Currency::round($salePrice + $sale->calculateTakeoff($purchasable->getPrice()));

            // Cannot have a sale that makes the price negative.
            if ($salePrice < 0) {
                $salePrice = 0;
            }
        }

        return $salePrice;
    }

    /**
     * @param PurchasableInterface $purchasable
     * @param Sale $sale
     * @param Order $order
     * @return bool
     */
    public function matchPurchasableAndSale(PurchasableInterface $purchasable, Sale $sale, Order $order = null): bool
    {
        // can't match something not promotable
        if (!$purchasable->getIsPromotable()) {
            return false;
        }

        // Purchsable ID match
        if (!$sale->allPurchasables && !\in_array($purchasable->getPurchasableId(), $sale->getPurchasableIds(), false)) {
            return false;
        }

        // Category match
        $relatedTo = ['sourceElement' => $purchasable->getPromotionRelationSource()];
        $relatedCategories = Category::find()->relatedTo($relatedTo)->ids();
        $saleCategories = $sale->getCategoryIds();
        $purchasableIsRelateToOneOrMoreCategories = (bool)array_intersect($relatedCategories, $saleCategories);
        if (!$sale->allCategories && !$purchasableIsRelateToOneOrMoreCategories) {
            return false;
        }


        if ($order) {

            $user = $order->getUser();

            if (!$sale->allGroups) {
                // We must pass a real user to getCurrentUserGroupIds, otherwise the current user is used.
                if (null === $user) {
                    return false;
                }
                // User groups of the order's user
                $userGroups = Plugin::getInstance()->getCustomers()->getUserGroupIdsForUser($user);
                if (!$userGroups || !array_intersect($userGroups, $sale->getUserGroupIds())) {
                    return false;
                }
            }
        }

        if (!$order) {
            if (!$sale->allGroups) {
                // User groups of the currently logged in user
                $userGroups = Plugin::getInstance()->getCustomers()->getUserGroupIdsForUser();
                if (!$userGroups || !array_intersect($userGroups, $sale->getUserGroupIds())) {
                    return false;
                }
            }
        }

        // Are we dealing with the current session outside of any cart/order context
        if (!$order) {
            if (!$sale->allGroups) {
                $userGroups = Plugin::getInstance()->getCustomers()->getUserGroupIdsForUser();
                if (!$userGroups || !array_intersect($userGroups, $sale->getUserGroupIds())) {
                    return false;
                }
            }
        }

        $date = new \DateTime();

        if ($order) {
            // Date we care about in the context of an order is the date the order was placed.
            // If the order is still a cart, use the current date time.
            $date = $order->isCompleted ? $order->dateOrdered : new \DateTime();
        }

        if ($sale->dateFrom && $sale->dateFrom >= $date) {
            return false;
        }

        if ($sale->dateTo && $sale->dateTo <= $date) {
            return false;
        }

        $saleMatchEvent = new SaleMatchEvent(['sale' => $this]);

        // Raising the 'beforeMatchPurchasableSale' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_MATCH_PURCHASABLE_SALE)) {
            $this->trigger(self::EVENT_BEFORE_MATCH_PURCHASABLE_SALE, $saleMatchEvent);
        }

        return $saleMatchEvent->isValid;
    }

    /**
     * @param Sale $model
     * @param array $groups ids
     * @param array $categories ids
     * @param array $purchasables ids
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public function saveSale(Sale $model, array $groups, array $categories, array $purchasables): bool
    {
        if ($model->id) {
            $record = SaleRecord::findOne($model->id);

            if (!$record) {
                throw new Exception(Craft::t('commerce', 'No sale exists with the ID “{id}”',
                    ['id' => $model->id]));
            }
        } else {
            $record = new SaleRecord();
        }

        $fields = [
            'name',
            'description',
            'dateFrom',
            'dateTo',
            'discountType',
            'discountAmount',
            'enabled'
        ];
        foreach ($fields as $field) {
            $record->$field = $model->$field;
        }

        $record->allGroups = $model->allGroups = empty($groups);
        $record->allCategories = $model->allCategories = empty($categories);
        $record->allPurchasables = $model->allPurchasables = empty($purchasables);

        $record->validate();
        $model->addErrors($record->getErrors());

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            if (!$model->hasErrors()) {
                $record->save(false);
                $model->id = $record->id;

                SaleUserGroupRecord::deleteAll(['saleId' => $model->id]);
                SalePurchasableRecord::deleteAll(['saleId' => $model->id]);
                SaleCategoryRecord::deleteAll(['saleId' => $model->id]);

                foreach ($groups as $groupId) {
                    $relation = new SaleUserGroupRecord();
                    $relation->userGroupId = $groupId;
                    $relation->saleId = $model->id;
                    $relation->save();
                }

                foreach ($categories as $categoryId) {
                    $relation = new SaleCategoryRecord;
                    $relation->categoryId = $categoryId;
                    $relation->saleId = $model->id;
                    $relation->save();
                }

                foreach ($purchasables as $purchasableId) {
                    $relation = new SalePurchasableRecord();
                    $relation->purchasableId = $purchasableId;
                    $purchasable = Craft::$app->getElements()->getElementById($purchasableId);
                    $relation->purchasableType = \get_class($purchasable);
                    $relation->saleId = $model->id;
                    $relation->save();
                }

                $transaction->commit();

                return true;
            }
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

        $transaction->rollBack();

        return false;
    }

    /**
     * @param $id
     * @return bool
     * @throws \Exception
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function deleteSaleById($id): bool
    {
        $sale = SaleRecord::findOne($id);

        if ($sale) {
            return $sale->delete();
        }

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array|Sale[]
     */
    private function _getAllEnabledSales(): array
    {
        if (null === $this->_allActiveSales) {
            $sales = $this->getAllSales();
            $activeSales = [];
            foreach ($sales as $sale) {
                if ($sale->enabled) {
                    $activeSales[] = $sale;
                }
            }

            $this->_allActiveSales = $activeSales;
        }

        return $this->_allActiveSales;
    }
}
