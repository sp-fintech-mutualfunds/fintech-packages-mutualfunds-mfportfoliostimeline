<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline;

use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Portfolios\Model\AppsFintechMfPortfoliosPerformancesChunks;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimeline;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimelineSnapshots;
use Apps\Fintech\Packages\Mf\Schemes\MfSchemes;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use System\Base\BasePackage;

class MfPortfoliostimeline extends BasePackage
{
    public $snapshotsModel = AppsFintechMfPortfoliostimelineSnapshots::class;

    public $performancesChunksModel = AppsFintechMfPortfoliosPerformancesChunks::class;

    public $portfolioSchemes = [];

    public $parsedCarbon = [];

    public $mfportfoliostimeline;

    public $timelineDateBeingProcessed;

    public $portfolio;

    protected $modelToUse = AppsFintechMfPortfoliostimeline::class;

    protected $packageName = 'mfportfoliostimeline';

    protected $today;

    protected $portfolioPackage;

    protected $timeline;

    protected $portfolioTransactionsDates = [];

    protected $portfolioInvestments = [];

    public function getTimeline()
    {
        return $this->timeline;
    }

    public function init()
    {
        $this->today = (\Carbon\Carbon::now(new \DateTimeZone('Asia/Kolkata')));

        $this->parsedCarbon[$this->today->toDateString()] = $this->today;

        $this->today = $this->today->toDateString();

        parent::init();

        $this->ffStore = $this->ff->store($this->ffStoreToUse);

        $this->ffStore->setValidateData(false);

        return $this;
    }

    public function forceRecalculateTimeline($portfolio, $transactionDate)
    {
        if (!isset($portfolio['timeline'])) {
            $this->getPortfoliotimelineByPortfolio($portfolio);

            if (!$this->timeline) {
                return false;
            }
        } else {
            $this->timeline = &$portfolio['timeline'];
        }

        $this->timeline['recalculate'] = true;
        $this->timeline['recalculate_from_date'] = $transactionDate;

        //Delete snapshots and chunks here
        $snapshotsIds = $this->timeline['snapshots_ids'];
        ksort($snapshotsIds);
        $timelineDateKeys = array_keys($snapshotsIds);
        $timelineDateKey = array_search($transactionDate, $timelineDateKeys);

        $portfolioSnapshots = array_slice($snapshotsIds, $timelineDateKey);

        if (count($portfolioSnapshots) > 0) {
            $this->switchModel($this->snapshotsModel);

            foreach ($portfolioSnapshots as $snapshotDate => $snapshotId) {
                $this->remove($snapshotId);

                unset($this->timeline['snapshots_ids'][$snapshotDate]);
            }
        }

        $this->switchModel();

        $this->update($this->timeline);
    }

    public function getPortfoliotimelineByPortfolio($portfolio, $getCached = true)
    {
        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

        $this->portfolio = $this->portfolioPackage->getById($portfolio['id']);

        if (!$this->portfolio) {
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

        //get from  opcache
        if ($this->opCache && $getCached) {
            // $conditions = array_merge($conditions, ['columns'=>['id']]);

            $timeline = $this->getByParams($conditions);
            if (isset($timeline) && isset($timeline[0])) {
                $this->timeline = $timeline[0];
            } else {
                return false;
            }

            // if (!$this->opCache->checkCache($this->timeline['id'], 'mfportfoliostimeline')) {
            //     $this->opCache->setCache($this->timeline['id'], $this->timeline, 'mfportfoliostimeline');
            // }

            // if ($cachedTimeline = $this->opCache->getCache($this->timeline['id'], 'mfportfoliostimeline')) {
            //     $this->timeline = $cachedTimeline;

            //     return $this->timeline;
            // }
        }

        $timeline = $this->getByParams($conditions);

        if (isset($timeline) && isset($timeline[0])) {
            $this->timeline = $timeline[0];
        } else {
            return false;
        }

        return $this->timeline;
    }

    public function getPortfoliotimelineByPortfolioAndTimeline($portfolio, $getTimelineDate = null, $force = false)
    {
        //Increase Exectimeout to 10 mins as this process takes time to extract and merge data.
        // if ((int) ini_get('max_execution_time') < 600) {
        //     set_time_limit(600);
        // }

        // //Increase memory_limit to 1G as the process takes a bit of memory to process the array.
        // if ((int) ini_get('memory_limit') < 1024) {
        //     ini_set('memory_limit', '1024M');
        // }

        if (!isset($portfolio['timeline'])) {
            $this->getPortfoliotimelineByPortfolio($portfolio);

            if (!$this->timeline) {
                return false;
            }
        }

        $this->timeline = &$portfolio['timeline'];

        $beforeStartDateRequested = null;
        if ($getTimelineDate) {
            if (!isset($this->parsedCarbon[$getTimelineDate])) {
                $this->parsedCarbon[$getTimelineDate] = \Carbon\Carbon::parse($getTimelineDate);
            }
            if (!isset($this->parsedCarbon[$portfolio['start_date']])) {
                $this->parsedCarbon[$portfolio['start_date']] = \Carbon\Carbon::parse($portfolio['start_date']);
            }

            if (($this->parsedCarbon[$getTimelineDate])->lt($this->parsedCarbon[$portfolio['start_date']])) {
                $beforeStartDateRequested = true;

                $getTimelineDate = $portfolio['start_date'];
            }
        } else {
            $getTimelineDate = $portfolio['start_date'];
        }

        if (($this->parsedCarbon[$getTimelineDate])->gt($this->parsedCarbon[$this->today])) {
            $getTimelineDate = $this->today;
        }

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

                if (!isset($this->parsedCarbon[$this->helper->first($portfolio['transactions'])['date']])) {
                    $this->parsedCarbon[$this->helper->first($portfolio['transactions'])['date']] = \Carbon\Carbon::parse($this->helper->first($portfolio['transactions'])['date']);
                }

                if (($this->parsedCarbon[$getTimelineDate])->gt($this->parsedCarbon[$this->helper->first($portfolio['transactions'])['date']])) {
                    $afterEndDateRequested = true;
                }

                if (($this->parsedCarbon[$getTimelineDate])->gte($this->parsedCarbon[$this->helper->first($portfolio['transactions'])['date']])) {
                    $endDate = $getTimelineDate = $this->helper->first($portfolio['transactions'])['date'];
                }
            } else {
                $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'latest_value_date', preserveKey: true, order: SORT_DESC);

                if (!isset($this->parsedCarbon[$this->helper->first($portfolio['transactions'])['latest_value_date']])) {
                    $this->parsedCarbon[$this->helper->first($portfolio['transactions'])['latest_value_date']] = \Carbon\Carbon::parse($this->helper->first($portfolio['transactions'])['latest_value_date']);
                }

                if (($this->parsedCarbon[$getTimelineDate])->gt($this->parsedCarbon[$this->helper->first($portfolio['transactions'])['latest_value_date']])) {
                    $afterEndDateRequested = true;
                }

                if (($this->parsedCarbon[$getTimelineDate])->gte($this->parsedCarbon[$this->helper->first($portfolio['transactions'])['latest_value_date']])) {
                    $endDate = $getTimelineDate = $this->helper->first($portfolio['transactions'])['latest_value_date'];
                }
            }
        }

        if (isset($this->timeline['snapshots_ids'][$getTimelineDate]) && !$force) {
            try {
                if ($this->localContent->fileExists('.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $this->timeline['snapshots_ids'][$getTimelineDate] . '.json')) {
                    $timelineSnapshot = $this->helper->decode($this->localContent->read('.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $this->timeline['snapshots_ids'][$getTimelineDate] . '.json'), true);
                }
            } catch (FilesystemException | UnableToReadFile | UnableToCheckExistence | \throwable $e) {
                $this->addResponse($e->getMessage(), 1);

                return false;
            }

            if ($timelineSnapshot) {
                $timelinePortfolio = $timelineSnapshot['snapshot'];
            }
        }

        if (!isset($timelinePortfolio)) {
            //We need to recalculate here as well if we change any transactions.
            $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

            $this->timelineDateBeingProcessed = $getTimelineDate;

            $timelinePortfolio = $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $portfolio['id']], false, $this);

            $timelinePortfolio['timelineDate'] = $getTimelineDate;

            $this->switchModel($this->snapshotsModel);

            $timelineSnapshot = [];

            $timelineSnapshot['timeline_id'] = $this->timeline['id'];
            $timelineSnapshot['date'] = $getTimelineDate;
            $timelineSnapshot['snapshot'] = $timelinePortfolio;

            // if (isset($timelineSnapshot['id'])) {
            //     $this->update($timelineSnapshot);
            // } else {
            //     $this->add($timelineSnapshot);
            // }

            // if (!isset($this->packagesData->last['id'])) {
            //     $this->addResponse('Could not insert/update timeline snapshot, contact developer', 1);

            //     return false;
            // }

            // $this->timeline['snapshots_ids'][$getTimelineDate] = $this->packagesData->last['id'];

            $timelineSnapshot['snapshot']['id'] = $this->getLastInsertedId() + 1;
            // trace([$timelineSnapshot['snapshot']['id']]);
            try {
                $this->localContent->write(
                    '.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $timelineSnapshot['snapshot']['id'] . '.json',
                    $this->helper->encode($timelineSnapshot)
                );

                $this->ffStore->count(true);
            } catch (FilesystemException | UnableToWriteFile | \throwable $e) {
                $this->addResponse($e->getMessage(), 1);

                return false;
            }

            if ($this->getLastInsertedId() !== $timelineSnapshot['snapshot']['id']) {
                $this->addResponse('Could not insert/update timeline snapshot, contact developer', 1);

                return false;
            }

            $this->timeline['snapshots_ids'][$getTimelineDate] = $timelineSnapshot['snapshot']['id'];

            // if (!$this->createSnapshotChunks($timelineSnapshot, $force)) {
            //     return false;
            // }
        }

        if ($timelinePortfolio) {
            $timelinePortfolio['beforeStartDateRequested'] = $beforeStartDateRequested;
            $timelinePortfolio['afterEndDateRequested'] = $afterEndDateRequested;
            $timelinePortfolio['end_date'] = $endDate;

            if ($force) {
                $this->addResponse('Recalculated timeline for ' . $getTimelineDate, 0);
            }

            //Update with ids
            $this->switchModel();

            try {
                $this->localContent->write(
                    '.ff/sp/apps_fintech_mf_portfoliostimeline/data/' . $this->timeline['id'] . '.json',
                    $this->helper->encode($this->timeline)
                );
            } catch (FilesystemException | UnableToWriteFile | \throwable $e) {
                $this->addResponse($e->getMessage(), 1);

                return false;
            }

            return $timelinePortfolio;
        }

        return false;
    }

    public function timelineNeedsGeneration($portfolio)
    {
        // return true;
        $this->portfolio = $portfolio;

        if (!isset($portfolio['timeline'])) {
            $this->getPortfoliotimelineByPortfolio($portfolio);

            if (!$this->timeline) {
                return false;
            }
        }

        $this->timeline = &$portfolio['timeline'];

        if (!isset($this->timeline['snapshots_ids']) || !isset($this->portfolio['performances_chunks'])) {
            return true;
        }

        if (count($this->timeline['snapshots_ids']) === 0 || count($this->portfolio['performances_chunks']) === 0) {
            return true;
        }

        if ($this->timeline['recalculate'] && $this->timeline['recalculate_from_date']) {
            return true;
        }

        return false;
    }

    public function getAvailableTimelineBrowserOptions()
    {
        return
            [
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
        $this->portfolio = $portfolio;

        if (isset($data['jump']) && isset($data['browse'])) {
            if (!isset($this->parsedCarbon[$data['timelineDate']])) {
                $this->parsedCarbon[$data['timelineDate']] = \Carbon\Carbon::parse($data['timelineDate']);
            }

            $timelineDate = $this->parsedCarbon[$data['timelineDate']];

            if ($data['browse'] === 'transaction') {
                if ($data['jump'] === 'previous') {
                    $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'date', preserveKey: true, order: SORT_DESC);
                } else if ($data['jump'] === 'next') {
                    $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'date', preserveKey: true);
                }

                foreach ($portfolio['transactions'] as $transaction) {
                    if (!isset($this->parsedCarbon[$transaction['date']])) {
                        $this->parsedCarbon[$transaction['date']] = \Carbon\Carbon::parse($transaction['date']);
                    }

                    if ($data['jump'] === 'previous') {
                        if (($this->parsedCarbon[$transaction['date']])->lt($timelineDate)) {
                            $timelineDate = $transaction['date'];

                            break;
                        }
                    } else if ($data['jump'] === 'next') {
                        if (($this->parsedCarbon[$transaction['date']])->gt($timelineDate)) {
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

        $this->timeline = $portfolio['timeline'];

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

    protected function registerProgressMethods($datesToProcess, $forceRecalculateTimeline = false)
    {
        if ($this->basepackages->progress->checkProgressFile('mfportfoliotimeline')) {
            $this->basepackages->progress->deleteProgressFile('mfportfoliotimeline');
        }

        $progressMethods = [];

        foreach ($datesToProcess as $dateToProcess) {
            if (!isset($this->timeline['snapshots'][$dateToProcess]) || $forceRecalculateTimeline) {
                array_push($progressMethods,
                    [
                        'method'    => 'generatePortfolioTimeline',
                        'text'      => 'Generate portfolio timeline for ' . $dateToProcess . '...',
                        'args'      => [$dateToProcess, $forceRecalculateTimeline]
                    ]
                );
            }
        }

        array_push($progressMethods,
            [
                'method'    => 'saveTimeline',
                'text'      => 'Saving timeline...',
                'args'      => [null, null]
            ]
        );

        array_push($progressMethods,
            [
                'method'    => 'generatePortfolioPerformance',
                'text'      => 'Generate portfolio performance...',
                'args'      => [null, null]
            ]
        );

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
        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

        $this->schemePackage = $this->usePackage(MfSchemes::class);

        $this->portfolio = $this->portfolioPackage->getPortfolioById((int) $data['portfolio_id'], true);

        if (!$this->portfolio) {
            $this->addResponse('Portfolio ID provided incorrect!', 1);

            return false;
        }

        //Increase Exectimeout to 1 hr as this process takes time to extract and merge data.
        if ((int) ini_get('max_execution_time') < 3600) {
            set_time_limit(3600);
        }

        //Increase memory_limit to 1G as the process takes a bit of memory to process the array.
        if ((int) ini_get('memory_limit') < 1024) {
            ini_set('memory_limit', '1024M');
        }

        $this->timeline = $this->portfolio['timeline'];

        if (!isset($data['mode'])) {
            $data['mode'] = 'monthly';
            $data['monthly_day'] = '1';
            $data['monthly_months'] = ['1','2','3','4','5','6','7','8','9','10','11','12'];
        }

        if ($this->timeline['recalculate'] && $this->timeline['recalculate_from_date']) {
            $recalculateFrom = $this->timeline['recalculate_from_date'];
        } else {
            $recalculateFrom = $this->portfolio['start_date'];
        }

        if (!isset($this->parsedCarbon[$this->today])) {
            $this->parsedCarbon[$this->today] = \Carbon\Carbon::parse($this->today);
        }

        $endDate = $this->parsedCarbon[$this->today];

        foreach ($this->portfolio['investments'] as $investment) {
            if (!isset($this->parsedCarbon[$investment['latest_value_date']])) {
                $this->parsedCarbon[$investment['latest_value_date']] = \Carbon\Carbon::parse($investment['latest_value_date']);
            }

            if (($endDate)->gte($this->parsedCarbon[$investment['latest_value_date']])) {
                $endDate = $this->parsedCarbon[$investment['latest_value_date']];
            }
        }

        $this->portfolioInvestments = array_keys($this->portfolio['investments']);

        $endDate = $endDate->toDateString();

        $datesToProcess = [];

        $portfolioTransactions = msort(array: $this->portfolio['transactions'], key: 'latest_value_date', preserveKey: true, order: SORT_DESC);

        foreach ($portfolioTransactions as $portfolioTransaction) {
            if (!isset($this->portfolioSchemes[$portfolioTransaction['scheme_id']])) {
                $scheme = $this->schemePackage->getSchemeFromAmfiCodeOrSchemeId($portfolioTransaction, true);

                if (!$scheme) {
                    $this->addResponse('Scheme for portfolio transaction id: ' . $portfolioTransaction['id'] . ' does not exists!', 1);

                    return false;
                }

                $this->portfolioSchemes[$portfolioTransaction['scheme_id']] = $scheme;
            }

            if (!isset($this->parsedCarbon[$recalculateFrom])) {
                $this->parsedCarbon[$recalculateFrom] = \Carbon\Carbon::parse($recalculateFrom);
            }

            if (!isset($this->parsedCarbon[$portfolioTransaction['date']])) {
                $this->parsedCarbon[$portfolioTransaction['date']] = \Carbon\Carbon::parse($portfolioTransaction['date']);
            }

            if (($this->parsedCarbon[$portfolioTransaction['date']])->gte($this->parsedCarbon[$recalculateFrom])) {
                $transactionDate = $this->parsedCarbon[$portfolioTransaction['date']]->toDateString();

                if (!in_array($transactionDate, $datesToProcess)) {
                    array_push($datesToProcess, $transactionDate);
                }
            }

            if (!isset($this->portfolioTransactionsDates[$portfolioTransaction['date']])) {
                $this->portfolioTransactionsDates[$portfolioTransaction['date']] = [];
            }

            $this->portfolioTransactionsDates[$portfolioTransaction['date']][$portfolioTransaction['id']] =
                [
                    'date'          => $portfolioTransaction['date'],
                    'scheme_id'     => $portfolioTransaction['scheme_id'],
                    'scheme_name'   => $this->portfolioSchemes[$portfolioTransaction['scheme_id']]['name'],
                    'type'          => $portfolioTransaction['type'],
                    'amount'        => $portfolioTransaction['amount']
                ];
        }

        $startEndDates = (\Carbon\CarbonPeriod::between($recalculateFrom, $endDate))->toArray();

        foreach ($startEndDates as $startEndDate) {
            if ($data['mode'] === 'monthly') {
                if (in_array($startEndDate->month, $data['monthly_months'])) {
                    if ($startEndDate->day == $data['monthly_day']) {
                        if (!in_array($startEndDate->toDateString(), $datesToProcess)) {
                            array_push($datesToProcess, $startEndDate->toDateString());
                        }
                    }
                }
            } else if ($data['mode'] === 'weekly') {
                if (in_array($startEndDate->dayOfWeek(), $data['weekly_days'])) {
                    if (!in_array($startEndDate->toDateString(), $datesToProcess)) {
                        array_push($datesToProcess, $startEndDate->toDateString());
                    }
                }
            }
        }

        if (!in_array($endDate, $datesToProcess)) {
            array_push($datesToProcess, $endDate);
        }

        //Need to add dates for chunks.
        foreach (['week', 'month', 'threeMonth', 'sixMonth', 'year', 'threeYear', 'fiveYear', 'tenYear'] as $time) {
            $latestDate = \Carbon\Carbon::parse($endDate);

            $timeDate = null;

            if ($time === 'week') {
                $latestDate->subDay(6);
            } else if ($time === 'month') {
                $latestDate->subMonth();
            } else if ($time === 'threeMonth') {
                $latestDate->subMonth(3);
            } else if ($time === 'sixMonth') {
                $latestDate->subMonth(6);
            } else if ($time === 'year') {
                $latestDate->subYear();
            } else if ($time === 'threeYear') {
                $latestDate->subYear(3);
            } else if ($time === 'fiveYear') {
                $latestDate->subYear(5);
            } else if ($time === 'tenYear') {
                $latestDate->subYear(10);
            }

            if (($latestDate)->lt($this->parsedCarbon[$recalculateFrom])) {
                continue;
            }

            $timeDate = $latestDate->toDateString();

            if (!in_array($timeDate, $datesToProcess)) {
                array_push($datesToProcess, $timeDate);
            }
        }

        sort($datesToProcess);
        // trace([$datesToProcess]);
        // trace([array_reverse($datesToProcess)]);
        // $datesToProcess = ['2018-11-01'];
        $this->registerProgressMethods($datesToProcess, ($this->timeline['recalculate'] && $this->timeline['recalculate_from_date']));

        $progressFile = $this->basepackages->progress->checkProgressFile('mfportfoliotimeline');

        if (!$progressFile) {
            $this->addResponse('Not able to add dates to timeline progress process, contact developer.', 1);

            return false;
        }

        $this->switchModel($this->snapshotsModel);

        foreach ($progressFile['allProcesses'] as $process) {
            $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

            if ($this->withProgress($process['method'], $process['args']) === false) {
                if ($this->packagesData) {
                    return false;
                } else if ($this->portfolio && $this->portfolioPackage->packagesData) {
                    $this->addResponse(
                        $this->portfolioPackage->packagesData->responseMessage,
                        $this->portfolioPackage->packagesData->responseCode,
                        $this->portfolioPackage->packagesData->responseData ?? []
                    );
                }

                return false;
            }
        }

        return true;
    }

    protected function generatePortfolioTimeline($args)
    {
        $dateToProcess = $args[0];
        $forceRecalculateTimeline = $args[1];

        $this->timelineDateBeingProcessed = $dateToProcess;
        if (!isset($this->timeline['snapshots'][$dateToProcess]) || $forceRecalculateTimeline) {
            // $this->basepackages->utils->setMicroTimer('Snapshot Start - ' . $dateToProcess, true);
            $snapshot = $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $this->portfolio['id']], false, $this);

            if (!isset($snapshot) || !$snapshot) {
                $this->addResponse('Error generating snapshot for timeline date - ' . $dateToProcess, 1);

                return false;
            }

            if (isset($snapshot['timeline'])) {
                unset($snapshot['timeline']);
            }
            if (isset($snapshot['strategies'])) {
                unset($snapshot['strategies']);
            }
            if (isset($snapshot['performances_chunks'])) {
                unset($snapshot['performances_chunks']);
            }

            // $this->basepackages->utils->setMicroTimer('Snapshot End - ' . $dateToProcess, true);
            // var_Dump($this->basepackages->utils->getMicroTimer());
            // $this->basepackages->utils->resetMicroTimer();
            // $this->basepackages->utils->setMicroTimer('Save Snapshot Start - ' . $dateToProcess, true);

            $timelineSnapshot = [];
            $timelineSnapshot['timeline_id'] = $this->timeline['id'];
            $timelineSnapshot['date'] = $dateToProcess;

            foreach ($snapshot['transactions'] as &$snapshotTransactions) {
                if ($snapshotTransactions['transactions'] &&
                    count($snapshotTransactions['transactions']) > 0
                ) {
                    foreach ($snapshotTransactions['transactions'] as $snapshotTransactionsTransactionkey => $snapshotTransactionsTransaction) {
                        if (!isset($this->parsedCarbon[$snapshotTransactionsTransaction['date']])) {
                            $this->parsedCarbon[$snapshotTransactionsTransaction['date']] = \Carbon\Carbon::parse($snapshotTransactionsTransaction['date']);
                        }
                        if (!isset($this->parsedCarbon[$dateToProcess])) {
                            $this->parsedCarbon[$dateToProcess] = \Carbon\Carbon::parse($dateToProcess);
                        }

                        if (($this->parsedCarbon[$snapshotTransactionsTransaction['date']])->gt(\Carbon\Carbon::parse($dateToProcess))) {
                            unset($snapshotTransactions['transactions'][$snapshotTransactionsTransactionkey]);
                        }
                    }
                }
            }

            $timelineSnapshot['snapshot'] = $snapshot;

            if (isset($this->timeline['snapshots_ids'][$dateToProcess])) {
                try {
                    if ($this->localContent->fileExists('.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $this->timeline['snapshots_ids'][$dateToProcess] . '.json')) {
                        $timelineSnapshot['snapshot']['id'] = $this->timeline['snapshots_ids'][$dateToProcess];
                        $timelineSnapshot['id'] = $timelineSnapshot['snapshot']['id'];

                        $this->localContent->write(
                            '.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $this->timeline['snapshots_ids'][$dateToProcess] . '.json',
                            $this->helper->encode($timelineSnapshot)
                        );
                    } else {
                        $timelineSnapshot['snapshot']['id'] = $this->getLastInsertedId() + 1;
                        $timelineSnapshot['id'] = $timelineSnapshot['snapshot']['id'];

                        try {
                            $this->localContent->write(
                                '.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $timelineSnapshot['snapshot']['id'] . '.json',
                                $this->helper->encode($timelineSnapshot)
                            );

                            $this->ffStore->count(true);
                        } catch (FilesystemException | UnableToWriteFile | \throwable $e) {
                            $this->addResponse($e->getMessage(), 1);

                            return false;
                        }

                        if ($this->getLastInsertedId() !== $timelineSnapshot['snapshot']['id']) {
                            $this->addResponse('Could not insert/update timeline snapshot, contact developer', 1);

                            return false;
                        }
                    }
                } catch (FilesystemException | UnableToCheckExistence | UnableToWriteFile | \throwable $e) {
                    $this->addResponse($e->getMessage(), 1);

                    return false;
                }
            } else {
                $timelineSnapshot['snapshot']['id'] = $this->getLastInsertedId() + 1;
                $timelineSnapshot['id'] = $timelineSnapshot['snapshot']['id'];

                try {
                    $this->localContent->write(
                        '.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $timelineSnapshot['snapshot']['id'] . '.json',
                        $this->helper->encode($timelineSnapshot)
                    );

                    $this->ffStore->count(true);
                } catch (FilesystemException | UnableToWriteFile | \throwable $e) {
                    $this->addResponse($e->getMessage(), 1);

                    return false;
                }

                if ($this->getLastInsertedId() !== $timelineSnapshot['snapshot']['id']) {
                    $this->addResponse('Could not insert/update timeline snapshot, contact developer', 1);

                    return false;
                }
            }

            $this->timeline['snapshots_ids'][$dateToProcess] = $timelineSnapshot['snapshot']['id'];
        }

        return true;
    }

    protected function saveTimeline($args)
    {
        $this->switchModel();

        $this->timeline['recalculate'] = false;
        $this->timeline['recalculate_from_date'] = null;

        try {
            $this->localContent->write(
                '.ff/sp/apps_fintech_mf_portfoliostimeline/data/' . $this->timeline['id'] . '.json',
                $this->helper->encode($this->timeline)
            );
        } catch (FilesystemException | UnableToWriteFile | \throwable $e) {
            $this->addResponse($e->getMessage(), 1);

            return false;
        }

        return true;
    }

    protected function generatePortfolioPerformance($args)
    {
        $chunks['portfolio_id'] = $this->portfolio['id'];
        $chunks['performances_chunks'] = [];

        if ($this->timeline['snapshots_ids'] && count($this->timeline['snapshots_ids']) > 0) {
            $datesKeys = array_keys($this->timeline['snapshots_ids']);

            foreach (['week', 'month', 'threeMonth', 'sixMonth', 'year', 'threeYear', 'fiveYear', 'tenYear', 'all'] as $time) {
                if ($time !== 'all') {
                    $latestDate = \Carbon\Carbon::parse($this->helper->lastKey($this->timeline['snapshots_ids']));

                    $timeDate = null;

                    if ($time === 'week') {
                        $timeDate = $latestDate->subDay(6);
                    } else if ($time === 'month') {
                        $timeDate = $latestDate->subMonth();
                    } else if ($time === 'threeMonth') {
                        $timeDate = $latestDate->subMonth(3);
                    } else if ($time === 'sixMonth') {
                        $timeDate = $latestDate->subMonth(6);
                    } else if ($time === 'year') {
                        $timeDate = $latestDate->subYear();
                    } else if ($time === 'threeYear') {
                        $timeDate = $latestDate->subYear(3);
                    } else if ($time === 'fiveYear') {
                        $timeDate = $latestDate->subYear(5);
                    } else if ($time === 'tenYear') {
                        $timeDate = $latestDate->subYear(10);
                    }

                    if (!isset($this->parsedCarbon[$this->portfolio['start_date']])) {
                        $this->parsedCarbon[$this->portfolio['start_date']] = \Carbon\Carbon::parse($this->portfolio['start_date']);
                    }

                    if (($timeDate->lt($this->parsedCarbon[$this->portfolio['start_date']]))) {
                        continue;
                    }

                    $timeDate = $timeDate->toDateString();

                    if (isset($this->timeline['snapshots_ids'][$timeDate])) {
                        $timeDateKey = array_search($timeDate, $datesKeys);
                        $snapshotChunks = array_slice($this->timeline['snapshots_ids'], $timeDateKey);
                    } else {
                        $this->generatePortfolioTimeline([$timeDate, $args[1]]);

                        if (isset($this->timeline['snapshots_ids'][$timeDate])) {
                            $timeDateKey = array_search($timeDate, $datesKeys);
                            $snapshotChunks = array_slice($this->timeline['snapshots_ids'], $timeDateKey);
                        } else {
                            continue;
                        }
                    }
                } else {
                    $snapshotChunks = $this->timeline['snapshots_ids'];
                }

                if (count($snapshotChunks) > 0) {
                    $chunks['performances_chunks'][$time] = [];

                    foreach ($snapshotChunks as $snapshotChunkDate => $snapshotId) {
                        try {
                            if ($this->localContent->fileExists('.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $snapshotId . '.json')) {
                                $timelineSnapshot =
                                    $this->helper->decode(
                                        $this->localContent->read(
                                            '.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $snapshotId . '.json'
                                        ), true
                                    )['snapshot'];
                            }

                            if ($snapshotChunkDate === $this->helper->firstKey($snapshotChunks)) {
                                $firstTimelineSnapshot = $timelineSnapshot;
                            }
                        } catch (FilesystemException | UnableToReadFile | UnableToCheckExistence | \throwable $e) {
                            $this->addResponse($e->getMessage(), 1);

                            return false;
                        }

                        $chunks['performances_chunks'][$time][$snapshotChunkDate] = [];
                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['date'] = $snapshotChunkDate;
                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['invested_amount'] = numberFormatPrecision($timelineSnapshot['invested_amount'], 2);
                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['return_amount'] = numberFormatPrecision($timelineSnapshot['return_amount'], 2);
                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['sold_amount'] = numberFormatPrecision($timelineSnapshot['sold_amount'], 2);
                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['profit_loss'] = numberFormatPrecision($timelineSnapshot['profit_loss'], 2);
                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['total_value'] = numberFormatPrecision($timelineSnapshot['total_value'], 2);
                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['xirr'] = $timelineSnapshot['xirr'];
                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['return_percent'] =
                            numberFormatPrecision(($timelineSnapshot['return_amount'] * 100 / $firstTimelineSnapshot['return_amount'] - 100), 2);
                        if (isset($this->portfolioTransactionsDates[$snapshotChunkDate])) {
                            $chunks['performances_chunks'][$time][$snapshotChunkDate]['orders'] = $this->portfolioTransactionsDates[$snapshotChunkDate];
                        }

                        $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'] = [];

                        foreach ($this->portfolioInvestments as $investmentSchemeId) {
                            $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'][$investmentSchemeId]['invested_amount'] = 0;
                            $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'][$investmentSchemeId]['return_amount'] = 0;
                            $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'][$investmentSchemeId]['diff'] = 0;
                            $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'][$investmentSchemeId]['xirr'] = 0;

                            if (isset($timelineSnapshot['investments'][$investmentSchemeId])) {
                                $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'][$investmentSchemeId]['invested_amount']
                                    = numberFormatPrecision($timelineSnapshot['investments'][$investmentSchemeId]['amount'], 2);

                                $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'][$investmentSchemeId]['return_amount']
                                    = numberFormatPrecision($timelineSnapshot['investments'][$investmentSchemeId]['latest_value'], 2);

                                $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'][$investmentSchemeId]['diff']
                                    = numberFormatPrecision($timelineSnapshot['investments'][$investmentSchemeId]['diff'], 2);

                                $chunks['performances_chunks'][$time][$snapshotChunkDate]['investments'][$investmentSchemeId]['xirr'] =
                                    $timelineSnapshot['investments'][$investmentSchemeId]['xirr'];
                            }
                        }
                    }
                }
            }
        }

        $this->switchModel($this->performancesChunksModel);

        if (isset($this->portfolio['performances_chunks'])) {
            try {
                if ($this->localContent->fileExists('.ff/sp/apps_fintech_mf_portfolios_performances_chunks/data/' . $this->portfolio['performances_chunks']['id'] . '.json')) {
                    $chunks['id'] = $this->portfolio['performances_chunks']['id'];

                    $this->localContent->write(
                        '.ff/sp/apps_fintech_mf_portfolios_performances_chunks/data/' . $this->portfolio['performances_chunks']['id'] . '.json',
                        $this->helper->encode($chunks)
                    );
                } else {
                    $chunks['id'] = $this->getLastInsertedId() + 1;

                    try {
                        $this->localContent->write(
                            '.ff/sp/apps_fintech_mf_portfolios_performances_chunks/data/' . $chunks['id'] . '.json',
                            $this->helper->encode($chunks)
                        );

                        $this->ffStore->count(true);
                    } catch (FilesystemException | UnableToWriteFile | \throwable $e) {
                        $this->addResponse($e->getMessage(), 1);

                        return false;
                    }

                    if ($this->getLastInsertedId() !== $chunks['id']) {
                        $this->addResponse('Could not insert/update performance chunks, contact developer', 1);

                        return false;
                    }
                }
            } catch (FilesystemException | UnableToCheckExistence | UnableToWriteFile | \throwable $e) {
                $this->addResponse($e->getMessage(), 1);

                return false;
            }
        } else {
            $chunks['id'] = $this->getLastInsertedId() + 1;

            try {
                $this->localContent->write(
                    '.ff/sp/apps_fintech_mf_portfolios_performances_chunks/data/' . $chunks['id'] . '.json',
                    $this->helper->encode($chunks)
                );

                $this->ffStore->count(true);
            } catch (FilesystemException | UnableToWriteFile | \throwable $e) {
                $this->addResponse($e->getMessage(), 1);

                return false;
            }

            if ($this->getLastInsertedId() !== $chunks['id']) {
                $this->addResponse('Could not insert/update performance chunks, contact developer', 1);

                return false;
            }
        }

        return true;
    }

    public function switchModel($model = null)
    {
        if (!$model) {
            $this->setModelToUse($this->modelToUse = AppsFintechMfPortfoliostimeline::class);

            $this->packageName = 'mfportfoliostimeline';
        } else {
            $this->setModelToUse($model);
        }

        if ($this->config->databasetype !== 'db') {
            $this->ffStore = $this->ff->store($this->ffStoreToUse);

            $this->ffStore->setValidateData(false);
        }
    }
}