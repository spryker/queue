<?php

namespace SprykerFeature\Zed\CmsExporter\Persistence;

use Propel\Runtime\ActiveQuery\ModelCriteria;

interface CmsExporterQueryContainerInterface
{
    /**
     * @param ModelCriteria $expandableQuery
     *
     * @return ModelCriteria
     */
    public function expandCmsPageQuery(ModelCriteria $expandableQuery);
}