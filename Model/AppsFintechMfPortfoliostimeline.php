<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline\Model;

use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimelineSnapshots;
use System\Base\BaseModel;

class AppsFintechMfPortfoliostimeline extends BaseModel
{
    public $id;

    public $portfolio_id;

    public $recalculate;

    public $recalculate_from_date;

    public $mode;

    public $snapshots_ids;

    public $performance_chunks_ids;
}