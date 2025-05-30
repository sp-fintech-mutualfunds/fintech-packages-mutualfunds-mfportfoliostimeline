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

    public $snapshots_ids;

    public $performance_chunks_ids;

    public $mode;

    public $monthly_months;

    public $monthly_day;

    public $weekly_days;
}