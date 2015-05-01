<?php

namespace SprykerFeature\Zed\CategoryExporter\Persistence\QueryExpander;

use Propel\Runtime\ActiveQuery\Criterion\BasicCriterion;
use Propel\Runtime\ActiveQuery\Join;
use SprykerFeature\Zed\Category\Persistence\CategoryQueryContainer;
use SprykerFeature\Zed\Category\Persistence\Propel\Map\SpyCategoryAttributeTableMap;
use SprykerFeature\Zed\Category\Persistence\Propel\Map\SpyCategoryNodeTableMap;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use SprykerEngine\Zed\Touch\Persistence\Propel\Map\SpyTouchTableMap;

class NavigationQueryExpander
{
    /**
     * @var int
     */
    protected $localeId;

    /**
     * @var CategoryQueryContainer
     */
    protected $categoryQueryContainer;

    /**
     * @param CategoryQueryContainer $categoryQueryContainer
     * @param int $localeId
     */
    public function __construct(CategoryQueryContainer $categoryQueryContainer, $localeId)
    {
        $this->localeId = $localeId;
        $this->categoryQueryContainer = $categoryQueryContainer;
    }

    /**
     * @param ModelCriteria $expandableQuery
     *
     * @return ModelCriteria
     */
    public function expandQuery(ModelCriteria $expandableQuery)
    {
        $expandableQuery->clearSelectColumns();

        $join = new Join();
        $join
            ->setLeftTableName(SpyTouchTableMap::TABLE_NAME)
            ->setRightTableName(SpyCategoryNodeTableMap::TABLE_NAME)
            ->setJoinCondition(new BasicCriterion(new Criteria(), 'is_root', '1'))
        ;
        $expandableQuery
            ->addJoinObject($join);

        $expandableQuery->addJoin(
            SpyCategoryNodeTableMap::COL_FK_CATEGORY,
            SpyCategoryAttributeTableMap::COL_FK_CATEGORY,
            Criteria::LEFT_JOIN
        );

        $expandableQuery->addAnd(
            SpyCategoryAttributeTableMap::COL_FK_LOCALE,
            $this->localeId,
            Criteria::EQUAL
        );

        $expandableQuery = $this->categoryQueryContainer->joinCategoryQueryWithChildrenCategories($expandableQuery, 'rootChildren', 'rootChild');
        $expandableQuery = $this->categoryQueryContainer->joinLocalizedRelatedCategoryQueryWithAttributes($expandableQuery, 'rootChildren', 'rootChild');
        $expandableQuery = $this->categoryQueryContainer->joinCategoryQueryWithChildrenCategories($expandableQuery, 'categoryChildren', 'child', 'rootChildren');
        $expandableQuery = $this->categoryQueryContainer->joinLocalizedRelatedCategoryQueryWithAttributes($expandableQuery, 'categoryChildren', 'child');
        $expandableQuery = $this->categoryQueryContainer->joinCategoryQueryWithParentCategories($expandableQuery, true, false, 'rootChildren');
        $expandableQuery = $this->categoryQueryContainer->joinLocalizedRelatedCategoryQueryWithAttributes($expandableQuery, 'categoryParents', 'parent');
        $expandableQuery = $this->categoryQueryContainer->joinRelatedCategoryQueryWithUrls($expandableQuery, 'categoryChildren', 'child');
        $expandableQuery = $this->categoryQueryContainer->joinRelatedCategoryQueryWithUrls($expandableQuery, 'categoryParents', 'parent');
        $expandableQuery = $this->categoryQueryContainer->joinCategoryQueryWithUrls($expandableQuery, 'rootChildren');
        $expandableQuery = $this->categoryQueryContainer->selectCategoryAttributeColumns($expandableQuery, 'rootChildrenAttributes');

        $expandableQuery->withColumn(
            'rootChildren.id_category_node',
            'node_id'
        );

        $expandableQuery->orderBy('depth', Criteria::DESC);
        $expandableQuery->orderBy('descendant_id', Criteria::DESC);
        $expandableQuery->groupBy('node_id');

        return $expandableQuery;
    }
}
