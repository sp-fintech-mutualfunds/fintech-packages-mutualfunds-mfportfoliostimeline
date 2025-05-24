<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline\Model;

use System\Base\BaseModel;

class AppsFintechMfPortfoliostimeline extends BaseModel
{
    public $id;

    public $portfolio_id;

    public $snapshots;

    public $performance_chunks;
}