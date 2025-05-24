<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline;

use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimeline;
use System\Base\BasePackage;

class MfPortfoliostimeline extends BasePackage
{
    protected $modelToUse = AppsFintechMfPortfoliostimeline::class;

    protected $packageName = 'mfportfoliostimeline';

    public $mfportfoliostimeline;

    protected $today;

    protected $portfolioPackage;

    protected $timeline;

    public function init()
    {
        $this->today = (\Carbon\Carbon::now(new \DateTimeZone('Asia/Kolkata')))->toDateString();

        parent::init();

        $this->ffStore = $this->ff->store($this->ffStoreToUse);
        $this->ffStore->setValidateData(false);

        return $this;
    }

    public function getPortfoliotimelineByPortfolio($portfolio)
    {
        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

        $portfolio = $this->portfolioPackage->getById($portfolio['id']);

        if (!$portfolio) {
            $this->addResponse('Timeline for Portfolio does not exists. Contact developer as this should not have happened.', 1);

            return false;
        }

        if ($this->config->databasetype === 'db') {
            $conditions =
                [
                    'conditions'    => 'portfolio_id = :portfolio_id:',
                    'bind'          =>
                        [
                            'portfolio_id'       => (int) $portfolio['id'],
                        ]
                ];
        } else {
            $conditions =
                [
                    'conditions'    => ['portfolio_id', '=', (int) $portfolio['id']]
                ];
        }

        $timeline = $this->getByParams($conditions);

        if (isset($timeline) && isset($timeline[0])) {
            $this->timeline = $timeline[0];
        } else {
            return false;
        }

        //get from  opcache
        if ($this->opCache) {
            if ($cachedTimeline = $this->opCache->getCache($this->timeline['id'], 'mfportfoliostimeline')) {
                $this->timeline = $cachedTimeline;
            }
        }

        return $this->timeline;
    }

    public function getPortfoliotimelineByPortfolioAndTimeline($portfolio, $getTimelineDate = null, $force = false)
    {
        set_time_limit(600);

        $this->getPortfoliotimelineByPortfolio($portfolio);

        if (!$this->timeline) {
            return false;
        }

        $beforeStartDateRequested = null;
        if ($getTimelineDate) {
            if ((\Carbon\Carbon::parse($getTimelineDate))->lt(\Carbon\Carbon::parse($portfolio['start_date']))) {
                $beforeStartDateRequested = true;

                $getTimelineDate = $portfolio['start_date'];
            }
        } else {
            $getTimelineDate = $portfolio['start_date'];
        }

        if ((\Carbon\Carbon::parse($getTimelineDate))->gt(\Carbon\Carbon::parse($this->today))) {
            $getTimelineDate = $this->today;
        }

        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);
        $endDate = null;
        $afterEndDateRequested = null;
        //If investements are closed, we change the date to last transaction.
        if ($getTimelineDate !== $portfolio['start_date']) {
            $investmentsClosed = true;

            foreach ($portfolio['investments'] as $investment) {
                if ($investment['status'] === 'open') {
                    $investmentsClosed = false;

                    break;
                }
            }

            if ($investmentsClosed) {
                $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'date', preserveKey: true, order: SORT_DESC);

                if ((\Carbon\Carbon::parse($getTimelineDate))->gt(\Carbon\Carbon::parse($this->helper->first($portfolio['transactions'])['date']))) {
                    $afterEndDateRequested = true;
                }

                if ((\Carbon\Carbon::parse($getTimelineDate))->gte(\Carbon\Carbon::parse($this->helper->first($portfolio['transactions'])['date']))) {
                    $endDate = $getTimelineDate = $this->helper->first($portfolio['transactions'])['date'];
                }
            } else {
                $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'latest_value_date', preserveKey: true, order: SORT_DESC);

                if ((\Carbon\Carbon::parse($getTimelineDate))->gt(\Carbon\Carbon::parse($this->helper->first($portfolio['transactions'])['latest_value_date']))) {
                    $afterEndDateRequested = true;
                }

                if ((\Carbon\Carbon::parse($getTimelineDate))->gte(\Carbon\Carbon::parse($this->helper->first($portfolio['transactions'])['latest_value_date']))) {
                    $endDate = $getTimelineDate = $this->helper->first($portfolio['transactions'])['latest_value_date'];
                }
            }
        }

        //We need to recalculate here as well if we change any transactions.
        if (isset($this->timeline['snapshots'][$getTimelineDate]) && !$force) {
            $timelinePortfolio = $this->timeline['snapshots'][$getTimelineDate];
        } else {
            $timelinePortfolio = $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $portfolio['id']], false, $getTimelineDate);
        }

        $timelinePortfolio['beforeStartDateRequested'] = $beforeStartDateRequested;
        $timelinePortfolio['afterEndDateRequested'] = $afterEndDateRequested;
        $timelinePortfolio['end_date'] = $endDate;

        if ($timelinePortfolio) {
            if (isset($this->portfolioPackage)) {
                $this->addResponse(
                    $this->portfolioPackage->packagesData->responseMessage,
                    $this->portfolioPackage->packagesData->responseCode,
                    $this->portfolioPackage->packagesData->responseData ?? []
                );
            }

            if (!isset($this->timeline['performance_chunks'][$getTimelineDate]) || $force) {
                $this->createChunks($getTimelineDate);

                $this->update($this->timeline);

                //Add to opcache
                if ($this->opCache) {
                    $this->opCache->setCache($this->timeline['id'], $this->timeline, 'mfportfoliostimeline');
                }
            }

            $timelinePortfolio['performance_chunks'] = $this->timeline['performance_chunks'][$getTimelineDate];

            return $timelinePortfolio;
        }

        return false;
    }

    public function timelineNeedsGeneration($portfolio)
    {
        $this->getPortfoliotimelineByPortfolio($portfolio);

        if (isset($this->timeline['snapshots'])) {
            $numberOfSnapshots = count($this->timeline['snapshots']);

            $startEndDates = (\Carbon\CarbonPeriod::between($portfolio['start_date'], $this->today))->toArray();
            $numberOfDays = count($startEndDates);

            if ($numberOfSnapshots != $numberOfDays) {
                $this->registerProgressMethods($portfolio, $startEndDates);

                return true;
            }
        }

        return false;
    }

    public function getAvailableTimelineBrowserOptions()
    {
        return
            [
                'day' =>
                [
                    'id'    => 'day',
                    'name'  => 'DAY'
                ],
                'week' =>
                [
                    'id'    => 'week',
                    'name'  => 'WEEK'
                ],
                'month' =>
                [
                    'id'    => 'month',
                    'name'  => 'MONTH'
                ],
                'year' =>
                [
                    'id'    => 'year',
                    'name'  => 'YEAR'
                ],
                'transaction' =>
                [
                    'id'    => 'transaction',
                    'name'  => 'TRANSACTION'
                ]
            ];
    }

    public function getPortfolioTimelineDateByBrowseAction($portfolio, $data)
    {
        if (isset($data['jump']) && isset($data['browse'])) {
            $timelineDate = \Carbon\Carbon::parse($data['timelineDate']);

            if ($data['browse'] === 'transaction') {
                if ($data['jump'] === 'previous') {
                    $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'date', preserveKey: true, order: SORT_DESC);
                } else if ($data['jump'] === 'next') {
                    $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'date', preserveKey: true);
                }

                foreach ($portfolio['transactions'] as $transaction) {
                    if ($data['jump'] === 'previous') {
                        if ((\Carbon\Carbon::parse($transaction['date']))->lt($timelineDate)) {
                            $timelineDate = $transaction['date'];

                            break;
                        }
                    } else if ($data['jump'] === 'next') {
                        if ((\Carbon\Carbon::parse($transaction['date']))->gt($timelineDate)) {
                            $timelineDate = $transaction['date'];

                            break;
                        }
                    }
                }

                //No match found
                if ($timelineDate instanceof (\Carbon\Carbon::class)) {
                    if ($data['jump'] === 'previous') {
                        $transactionEndDate = $this->helper->first($portfolio['transactions'])['date'];
                    } else if ($data['jump'] === 'next') {
                        $transactionEndDate = $this->helper->last($portfolio['transactions'])['date'];
                    }

                    $timelineDate = $timelineDate->toDateString();
                }
            } else {
                if ($data['jump'] === 'previous') {
                    $method = 'sub' . $data['browse'];
                } else if ($data['jump'] === 'next') {
                    $method = 'add' . $data['browse'];
                }

                $timelineDate = $timelineDate->$method()->toDateString();
            }
        } else {
            $timelineDate = $data['timelineDate'];
        }

        $timelinePortfolio = $this->getPortfoliotimelineByPortfolioAndTimeline($portfolio, $timelineDate);

        if (!$timelinePortfolio) {
            return false;
        }

        if (isset($timelinePortfolio['beforeStartDateRequested']) || isset($timelinePortfolio['afterEndDateRequested']) || isset($transactionEndDate)) {
            if (isset($timelinePortfolio['beforeStartDateRequested'])) {
                $this->addResponse('Request out of bounds', 2, ['start_date' => $timelinePortfolio['start_date']]);
            } else if (isset($timelinePortfolio['afterEndDateRequested'])) {
                $this->addResponse('Request out of bounds', 2, ['end_date' => $timelinePortfolio['end_date']]);
            } else if ($transactionEndDate) {
                $this->addResponse('Request out of bounds', 2, ['transaction_end_date' => $transactionEndDate]);
            }

            return true;
        }

        $this->addResponse('Ok', 0, ['browse_date' => $timelineDate]);

        return true;
    }

    protected function createChunks($timelineDate)
    {
        $timelineDateKeys = array_keys($this->timeline['snapshots']);
        $timelineDateKey = array_search($timelineDate, $timelineDateKeys);
        $portfolioSnapshots = array_slice($this->timeline['snapshots'], 0, $timelineDateKey + 1);

        $portfolioSnapshots = msort(array: $portfolioSnapshots, key: 'timelineDate');

        $totalSnapshots = count($portfolioSnapshots);

        if ($totalSnapshots > 1) {
            if ($totalSnapshots > 7) {
                $forWeek = $totalSnapshots - 7;
            } else {
                $forWeek = $totalSnapshots;
            }

            $this->timeline['performance_chunks'][$timelineDate]['week'] = [];
            for ($forWeek; $forWeek < $totalSnapshots; $forWeek++) {
                $this->timeline['performance_chunks'][$timelineDate]['week'][$portfolioSnapshots[$forWeek]['timelineDate']] = [];
                $weeklyPerformance = &$this->timeline['performance_chunks'][$timelineDate]['week'][$portfolioSnapshots[$forWeek]['timelineDate']];
                $weeklyPerformance['timelineDate'] = $portfolioSnapshots[$forWeek]['timelineDate'];
                $weeklyPerformance['invested_amount'] = $portfolioSnapshots[$forWeek]['invested_amount'];
                $weeklyPerformance['return_amount'] = $portfolioSnapshots[$forWeek]['return_amount'];
                $weeklyPerformance['profit_loss'] = $portfolioSnapshots[$forWeek]['profit_loss'];
            }
        }

        if ($totalSnapshots > 7) {
            if ($totalSnapshots > 30) {
                $forMonth = $totalSnapshots - 30;
            } else {
                $forMonth = $totalSnapshots;
            }

            $this->timeline['performance_chunks'][$timelineDate]['month'] = [];
            for ($forMonth; $forMonth < $totalSnapshots; $forMonth++) {
                $this->timeline['performance_chunks'][$timelineDate]['month'][$portfolioSnapshots[$forMonth]['timelineDate']] = [];
                $monthlyPerformance = &$this->timeline['performance_chunks'][$timelineDate]['month'][$portfolioSnapshots[$forMonth]['timelineDate']];
                $monthlyPerformance['timelineDate'] = $portfolioSnapshots[$forMonth]['timelineDate'];
                $monthlyPerformance['invested_amount'] = $portfolioSnapshots[$forMonth]['invested_amount'];
                $monthlyPerformance['return_amount'] = $portfolioSnapshots[$forMonth]['return_amount'];
                $monthlyPerformance['profit_loss'] = $portfolioSnapshots[$forMonth]['profit_loss'];
            }
        }

        if ($totalSnapshots > 30) {
            if ($totalSnapshots > 365) {
                $forYear = $totalSnapshots - 365;
            } else {
                $forYear = $totalSnapshots;
            }

            $this->timeline['performance_chunks'][$timelineDate]['year'] = [];
            for ($forYear; $forYear < $totalSnapshots; $forYear++) {
                $this->timeline['performance_chunks'][$timelineDate]['year'][$portfolioSnapshots[$forYear]['timelineDate']] = [];
                $yearlyPerformance = &$this->timeline['performance_chunks'][$timelineDate]['year'][$portfolioSnapshots[$forYear]['timelineDate']];
                $yearlyPerformance['timelineDate'] = $portfolioSnapshots[$forYear]['timelineDate'];
                $yearlyPerformance['invested_amount'] = $portfolioSnapshots[$forYear]['invested_amount'];
                $yearlyPerformance['return_amount'] = $portfolioSnapshots[$forYear]['return_amount'];
                $yearlyPerformance['profit_loss'] = $portfolioSnapshots[$forYear]['profit_loss'];
            }
        }

        if ($totalSnapshots > 365) {
            if ($totalSnapshots > 1095) {
                $forThreeYear = $totalSnapshots - 1095;
            } else {
                $forThreeYear = $totalSnapshots;
            }

            $this->timeline['performance_chunks'][$timelineDate]['threeYear'] = [];
            for ($forThreeYear; $forThreeYear < $totalSnapshots; $forThreeYear++) {
                $this->timeline['performance_chunks'][$timelineDate]['threeYear'][$portfolioSnapshots[$forThreeYear]['timelineDate']] = [];
                $threeYearPerformance = &$this->timeline['performance_chunks'][$timelineDate]['threeYear'][$portfolioSnapshots[$forThreeYear]['timelineDate']];
                $threeYearPerformance['timelineDate'] = $portfolioSnapshots[$forThreeYear]['timelineDate'];
                $threeYearPerformance['invested_amount'] = $portfolioSnapshots[$forThreeYear]['invested_amount'];
                $threeYearPerformance['return_amount'] = $portfolioSnapshots[$forThreeYear]['return_amount'];
                $threeYearPerformance['profit_loss'] = $portfolioSnapshots[$forThreeYear]['profit_loss'];
            }
        }

        if ($totalSnapshots > 1095) {
            if ($totalSnapshots > 1825) {
                $forFiveYear = $totalSnapshots - 1825;
            } else {
                $forFiveYear = $totalSnapshots;
            }

            $this->timeline['performance_chunks'][$timelineDate]['fiveYear'] = [];
            for ($forFiveYear; $forFiveYear < $totalSnapshots; $forFiveYear++) {
                $this->timeline['performance_chunks'][$timelineDate]['fiveYear'][$portfolioSnapshots[$forFiveYear]['timelineDate']] = [];
                $fiveYearPerformance = &$this->timeline['performance_chunks'][$timelineDate]['fiveYear'][$portfolioSnapshots[$forFiveYear]['timelineDate']];
                $fiveYearPerformance['timelineDate'] = $portfolioSnapshots[$forFiveYear]['timelineDate'];
                $fiveYearPerformance['invested_amount'] = $portfolioSnapshots[$forFiveYear]['invested_amount'];
                $fiveYearPerformance['return_amount'] = $portfolioSnapshots[$forFiveYear]['return_amount'];
                $fiveYearPerformance['profit_loss'] = $portfolioSnapshots[$forFiveYear]['profit_loss'];
            }
        }

        if ($totalSnapshots > 3652) {
            if ($totalSnapshots > 3652) {
                $forTenYear = $totalSnapshots - 3652;
            } else {
                $forTenYear = $totalSnapshots;
            }

            $this->timeline['performance_chunks'][$timelineDate]['tenYear'] = [];
            for ($forTenYear; $forTenYear < $totalSnapshots; $forTenYear++) {
                $this->timeline['performance_chunks'][$timelineDate]['tenYear'][$portfolioSnapshots[$forTenYear]['timelineDate']] = [];
                $tenYearPerformance = &$this->timeline['performance_chunks'][$timelineDate]['tenYear'][$portfolioSnapshots[$forTenYear]['timelineDate']];
                $tenYearPerformance['timelineDate'] = $portfolioSnapshots[$forTenYear]['timelineDate'];
                $tenYearPerformance['invested_amount'] = $portfolioSnapshots[$forTenYear]['invested_amount'];
                $tenYearPerformance['return_amount'] = $portfolioSnapshots[$forTenYear]['return_amount'];
                $tenYearPerformance['profit_loss'] = $portfolioSnapshots[$forTenYear]['profit_loss'];
            }
        }

        $this->timeline['performance_chunks'][$timelineDate]['all'] = [];
        for ($forAll = 0; $forAll < $totalSnapshots; $forAll++) {
            $this->timeline['performance_chunks'][$timelineDate]['all'][$portfolioSnapshots[$forAll]['timelineDate']] = [];
            $allPerfornace = &$this->timeline['performance_chunks'][$timelineDate]['all'][$portfolioSnapshots[$forAll]['timelineDate']];
            $allPerfornace['timelineDate'] = $portfolioSnapshots[$forAll]['timelineDate'];
            $allPerfornace['invested_amount'] = $portfolioSnapshots[$forAll]['invested_amount'];
            $allPerfornace['return_amount'] = $portfolioSnapshots[$forAll]['return_amount'];
            $allPerfornace['profit_loss'] = $portfolioSnapshots[$forAll]['profit_loss'];
        }
    }

    protected function registerProgressMethods($portfolio, $startEndDates)
    {
        if ($this->basepackages->progress->checkProgressFile('mfportfolio')) {
            $this->basepackages->progress->deleteProgressFile('mfportfolio');
        }

        $progressMethods = [];

        foreach ($startEndDates as $startEndDate) {
            array_push($progressMethods,
                [
                    'method'    => 'generatePortfolioTimeline',
                    'text'      => 'Generate portfolio timeline for ' . $startEndDate->toDateString() . '...',
                    'args'      => [$portfolio['id'], $startEndDate->toDateString()]
                ]
            );
        }

        $this->basepackages->progress->registerMethods($progressMethods);
    }

    protected function withProgress($method, $arguments)
    {
        if (method_exists($this, $method)) {
            $arguments['progressMethod'] = $method;

            $arguments = [$arguments];

            $this->basepackages->progress->updateProgress($method, null, false);

            $call = call_user_func_array([$this, $method], $arguments);

            $this->basepackages->progress->updateProgress($method, $call, false);

            return $call;
        }

        return false;
    }

    public function processTimelineNeedsGeneration($data)
    {
        $progressFile = $this->basepackages->progress->checkProgressFile('mfportfolio');

        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

        $this->timeline = $this->getPortfoliotimelineByPortfolio(['id' => $data['portfolio_id']]);

        set_time_limit(600);//10 mins

        foreach ($progressFile['allProcesses'] as $process) {
            if ($this->withProgress($process['method'], $process['args']) === false) {
                $this->addResponse(
                    $this->portfolioPackage->packagesData->responseMessage,
                    $this->portfolioPackage->packagesData->responseCode,
                    $this->portfolioPackage->packagesData->responseData ?? []
                );

                return false;
            }
        }

        return true;
    }

    protected function saveGeneratePortfolioTimeline()
    {
    }

    protected function generatePortfolioTimeline($args)
    {
        $portfolioId = $args[0];
        $startEndDate = $args[1];

        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

        if (!isset($this->timeline['snapshots'][$startEndDate])) {
            $this->timeline['snapshots'][$startEndDate] = $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $portfolioId], false, $startEndDate);

            $this->createChunks($startEndDate);
        }

        if ($this->update($this->timeline)) {
            //Add to opcache
            if ($this->opCache) {
                $this->opCache->setCache($this->timeline['id'], $this->timeline, 'mfportfoliostimeline');
            }

            return true;
        }

        return false;
    }
}