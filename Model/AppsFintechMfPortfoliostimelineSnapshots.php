<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline\Model;

use System\Base\BaseModel;

class AppsFintechMfPortfoliostimelineSnapshots extends BaseModel
{
    public $id;

    public $timeline_id;

    public $date;

    public $snapshot;
}