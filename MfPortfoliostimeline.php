<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline;

use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimeline;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimelinePerformanceChunks;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimelineSnapshots;
use Apps\Fintech\Packages\Mf\Schemes\MfSchemes;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use System\Base\BasePackage;

class MfPortfoliostimeline extends BasePackage
{
    protected $modelToUse = AppsFintechMfPortfoliostimeline::class;

    public $snapshotsModel = AppsFintechMfPortfoliostimelineSnapshots::class;

    public $performanceChunksModel = AppsFintechMfPortfoliostimelinePerformanceChunks::class;

    protected $packageName = 'mfportfoliostimeline';

    public $mfportfoliostimeline;

    protected $today;

    protected $portfolioPackage;

    protected $timeline;

    public $timelineDateBeingProcessed;

    public $portfolio;

    protected $portfolioTransactionsDates = [];

    public $portfolioSchemes = [];

    public $parsedCarbon = [];

    private $previousSnapshot;

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
        $this->timeline = $this->getPortfoliotimelineByPortfolio($portfolio, false);

        if (!$this->timeline) {
            return false;
        }

        $this->timeline['recalculate'] = true;
        $this->timeline['recalculate_from_date'] = $transactionDate;

        //Delete snapshots and chunks here
        //

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
        // trace([$this->timeline['snapshots_ids'][$getTimelineDate]]);
        if (isset($this->timeline['snapshots_ids'][$getTimelineDate]) && !$force) {
            try {
                if ($this->localContent->fileExists('.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $this->timeline['snapshots_ids'][$getTimelineDate] . '.json')) {
                    $timelineSnapshot = $this->helper->decode($this->localContent->read('.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $this->timeline['snapshots_ids'][$getTimelineDate] . '.json'), true);
                }
            } catch (FilesystemException | UnableToReadFile | UnableToCheckExistence | \throwable $e) {
                $this->addResponse($e->getMessage(), 1);

                return false;
            }

            // trace([$timelineSnapshot]);
            // $this->switchModel($this->snapshotsModel);

            // $timelineSnapshot = $this->getById((int) $this->timeline['snapshots_ids'][$getTimelineDate]);

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

        // if (isset($this->portfolioPackage)) {
        //     $this->addResponse(
        //         $this->portfolioPackage->packagesData->responseMessage,
        //         $this->portfolioPackage->packagesData->responseCode,
        //         $this->portfolioPackage->packagesData->responseData ?? []
        //     );
        // }

        if ($timelinePortfolio) {
            $timelinePortfolio['beforeStartDateRequested'] = $beforeStartDateRequested;
            $timelinePortfolio['afterEndDateRequested'] = $afterEndDateRequested;
            $timelinePortfolio['end_date'] = $endDate;

            // if (isset($this->timeline['performance_chunks_ids'][$getTimelineDate]) && !$force) {
            //     $this->switchModel($this->performanceChunksModel);

            //     $timelinePerformanceChunk = $this->getById((int) $this->timeline['performance_chunks_ids'][$getTimelineDate]);

            //     if ($timelinePerformanceChunk) {
            //         $timelinePortfolio['performance_chunks'] = $timelinePerformanceChunk['performance_chunk'];
            //     }
            // } else {
                // trace(['me']);
                // $this->createSnapshotChunks($getTimelineDate);
                // $this->update($this->timeline);

                // //Add to opcache
                // if ($this->opCache) {
                //     $this->opCache->setCache($this->timeline['id'], $this->timeline, 'mfportfoliostimeline');
                // }
            // }

            // $timelinePortfolio['performance_chunks'] = $this->timeline['performance_chunks'][$getTimelineDate];

            if ($force) {
                $this->addResponse('Recalculated timeline for ' . $getTimelineDate, 0);
            }

            //Update with ids
            $this->switchModel();
            // $this->update($this->timeline);
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
        $this->portfolio = $portfolio;

        if (!isset($portfolio['timeline'])) {
            $this->getPortfoliotimelineByPortfolio($portfolio);

            if (!$this->timeline) {
                return false;
            }
        }

        $this->timeline = &$portfolio['timeline'];

        // if (!isset($this->timeline['snapshots_ids']) || !isset($this->timeline['performance_chunks_ids'])) {
        //     return true;
        // }

        // if (count($this->timeline['snapshots_ids']) === 0 || count($this->timeline['performance_chunks_ids']) === 0) {
        //     return true;
        // }

        // if (count($this->timeline['snapshots_ids']) !== count($this->timeline['performance_chunks_ids'])) {
        //     return true;
        // }

        // if (count(array_diff(array_keys($this->timeline['snapshots_ids']), array_keys($this->timeline['performance_chunks_ids']))) > 0) {
        //     return true;
        // }

        // if ($this->timeline['recalculate'] && $this->timeline['recalculate_from_date']) {
        //     return true;
        // }

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
            if (!isset($this->timeline['snapshots'][$dateToProcess]) ||
                !isset($this->timeline['performance_chunks'][$dateToProcess]) ||
                $forceRecalculateTimeline
            ) {
                array_push($progressMethods,
                    [
                        'method'    => 'generatePortfolioTimeline',
                        'text'      => 'Generate portfolio timeline for ' . $dateToProcess . '...',
                        'args'      => [$dateToProcess, $forceRecalculateTimeline]
                    ]
                );
            }
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

        if (isset($data['mode'])) {
            $this->timeline['mode'] = $data['mode'];
        }

        if ($this->timeline['recalculate'] && $this->timeline['recalculate_from_date']) {
            $recalculateFrom = $this->timeline['recalculate_from_date'];
        } else {
            $recalculateFrom = $this->portfolio['start_date'];
        }

        $endDate = $this->today;

        if (!isset($this->parsedCarbon[$endDate])) {
            $this->parsedCarbon[$endDate] = \Carbon\Carbon::parse($endDate);
        }
        if (!isset($this->parsedCarbon[$this->helper->first($this->portfolio['transactions'])['latest_value_date']])) {
            $this->parsedCarbon[$this->helper->first($this->portfolio['transactions'])['latest_value_date']] =
                \Carbon\Carbon::parse($this->helper->first($this->portfolio['transactions'])['latest_value_date']);
        }

        if (($this->parsedCarbon[$endDate])->gte($this->parsedCarbon[$this->helper->first($this->portfolio['transactions'])['latest_value_date']])) {
            $endDate = $this->helper->first($this->portfolio['transactions'])['latest_value_date'];
        }

        $datesToProcess = [];

        $portfolioTransactions = msort(array: $this->portfolio['transactions'], key: 'latest_value_date', preserveKey: true, order: SORT_DESC);

        foreach ($portfolioTransactions as $portfolioTransaction) {
            // array_push($this->portfolioTransactionsDates, $portfolioTransaction['date']);
            if (!isset($this->portfolioSchemes[$portfolioTransaction['scheme_id']])) {
                $scheme = $this->schemePackage->getSchemeFromAmfiCodeOrSchemeId($portfolioTransaction);

                if (!$scheme) {
                // if (!$scheme || !isset($scheme['navs']['navs'])) {
                    $this->addResponse('Scheme for portfolio transaction id: ' . $portfolioTransaction['id'] . ' does not exists!', 1);

                    return false;
                }

                $this->portfolioSchemes[$portfolioTransaction['scheme_id']] = $scheme;
            }

            if ($this->timeline['mode'] === 'transactions') {
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
            }
        }

        if ($this->timeline['mode'] !== 'transactions') {
            $startEndDates = (\Carbon\CarbonPeriod::between($recalculateFrom, $endDate))->toArray();

            foreach ($startEndDates as $startEndDate) {
                if ($this->timeline['mode'] === 'monthly') {
                    if (in_array($startEndDate->month, $data['monthly_months'])) {
                        if ($startEndDate->day == $data['monthly_day']) {
                            array_push($datesToProcess, $startEndDate->toDateString());
                        }
                    }
                } else if ($this->timeline['mode'] === 'weekly') {
                    if (in_array($startEndDate->dayOfWeek(), $data['weekly_days'])) {
                        array_push($datesToProcess, $startEndDate->toDateString());
                    }
                } else if ($this->timeline['mode'] === 'daily') {
                    array_push($datesToProcess, $startEndDate->toDateString());
                }
            }
        }

        array_push($datesToProcess, $endDate);
        // trace([$datesToProcess, array_reverse($datesToProcess)]);
        // $datesToProcess = ['2018-11-01'];
        $this->registerProgressMethods($datesToProcess, ($this->timeline['recalculate'] && $this->timeline['recalculate_from_date']));

        // $numberOfSnapshots = count($this->timeline['snapshots']);
        // $numberOfPerformanceChunks = count($this->timeline['performance_chunks']);

        // $numberOfDays = count($startEndDates);

        // if ($numberOfSnapshots != $numberOfDays ||
        //     $numberOfPerformanceChunks != $numberOfDays ||
        //     ($this->timeline['recalculate'] && $this->timeline['recalculate_from_date'])
        // ) {
        //     $this->registerProgressMethods($startEndDates, ($this->timeline['recalculate'] && $this->timeline['recalculate_from_date']));

        //     return true;
        // }
        $progressFile = $this->basepackages->progress->checkProgressFile('mfportfoliotimeline');

        if (!$progressFile) {
            $this->addResponse('Not able to add dates to timeline progress process, contact developer.', 1);

            return false;
        }

        $this->switchModel($this->snapshotsModel);

        foreach ($progressFile['allProcesses'] as $process) {
            $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

            if ($this->withProgress($process['method'], $process['args']) === false) {
                $this->addResponse(
                    $this->portfolioPackage->packagesData->responseMessage,
                    $this->portfolioPackage->packagesData->responseCode,
                    $this->portfolioPackage->packagesData->responseData ?? []
                );

                return false;
            }
        }
        // trace(['me']);
        // $this->timeline['snapshots_ids'] = msort(array: $this->timeline['snapshots_ids'], key: 'timelineDate', preserveKey: true);
        // $this->timeline['performance_chunks_ids'] = msort(array: $this->timeline['performance_chunks_ids'], key: 'timelineDate', preserveKey: true);
        // $this->timeline['recalculate'] = null;
        // $this->timeline['recalculate_from_date'] = null;

        //Process Snapshots
        // $this->switchModel($this->snapshotsModel);
        // $this->setModelToUse($this->snapshotsModel);

        // if ($this->config->databasetype !== 'db') {
        //     $this->ffStore = null;
        // }

        // foreach ($this->timeline['snapshots'] as $snapshotDate => $snapshot) {
        //     if (isset($this->timeline['snapshots_ids'][$snapshotDate])) {
        //         $timelineSnapshot = $this->getById((int) $this->timeline['snapshots_ids'][$snapshotDate]);

        //         if (!$timelineSnapshot) {
        //             $timelineSnapshot = [];
        //         }
        //     }

        //     $timelineSnapshot['timeline_id'] = $this->timeline['id'];
        //     $timelineSnapshot['date'] = $snapshotDate;
        //     $timelineSnapshot['snapshot'] = $snapshot;

        //     if (isset($timelineSnapshot['id'])) {
        //         $this->update($timelineSnapshot);
        //     } else {
        //         $this->add($timelineSnapshot);
        //     }

        //     if (!isset($this->packagesData->last['id'])) {
        //         $this->addResponse('Could not insert/update timeline snapshot, contact developer', 1);

        //         return false;
        //     }

        //     $this->timeline['snapshots_ids'][$snapshotDate] = $this->packagesData->last['id'];
        // }

        //Process Chunks
        // $this->switchModel($this->performanceChunksModel);
        // $this->setModelToUse($this->performanceChunksModel);

        // if ($this->config->databasetype !== 'db') {
        //     $this->ffStore = null;
        // }

        // foreach ($this->timeline['performance_chunks'] as $performanceChunkDate => $performanceChunk) {
        //     if (isset($this->timeline['performance_chunks_ids'][$performanceChunkDate])) {
        //         $timelinePerformanceChunk = $this->getById((int) $this->timeline['performance_chunks_ids'][$performanceChunkDate]);

        //         if (!$timelinePerformanceChunk) {
        //             $timelinePerformanceChunk = [];
        //         }
        //     }

        //     $timelinePerformanceChunk['timeline_id'] = $this->timeline['id'];
        //     $timelinePerformanceChunk['date'] = $performanceChunkDate;
        //     $timelinePerformanceChunk['performance_chunk'] = $performanceChunk;

        //     if (isset($timelinePerformanceChunk['id'])) {
        //         $this->update($timelinePerformanceChunk);
        //     } else {
        //         $this->add($timelinePerformanceChunk);
        //     }

        //     if (!isset($this->packagesData->last['id'])) {
        //         $this->addResponse('Could not insert/update timeline snapshot, contact developer', 1);

        //         return false;
        //     }

        //     $this->timeline['performance_chunks_ids'][$performanceChunkDate] = $this->packagesData->last['id'];
        // }

        //Update Timeline
        // $this->switchModel();
        // $this->setModelToUse($this->modelToUse = AppsFintechMfPortfoliostimeline::class);

        // $this->packageName = 'mfportfoliostimeline';

        // if ($this->config->databasetype !== 'db') {
        //     $this->ffStore = null;
        // }

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

        return true;
    }

    protected function generatePortfolioTimeline($args)
    {
        $dateToProcess = $args[0];
        $forceRecalculateTimeline = $args[1];

        $this->timelineDateBeingProcessed = $dateToProcess;
        // try {
        if (!isset($this->timeline['snapshots'][$dateToProcess]) ||
            !isset($this->timeline['performance_chunks'][$dateToProcess]) ||
            $forceRecalculateTimeline
        ) {
            // $this->basepackages->utils->setMicroTimer('Snapshot Start - ' . $dateToProcess, true);

            $snapshot = $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $this->portfolio['id']], false, $this);
            // if (in_array($dateToProcess, $this->portfolioTransactionsDates)) {
            //     $this->previousSnapshot = $snapshot = $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $this->portfolio['id']], false, $this);
            //     //remove this
            //     return true;
            // } else {
            //     if ($this->previousSnapshot) {
            //         $snapshot = $this->calculatePreviousSnapshot();
            //     }
            // }

            if (!isset($snapshot) || !$snapshot) {
                $this->addResponse('Error generating snapshot for timeline date - ' . $dateToProcess, 1);

                return false;
            }

            unset($snapshot['timeline']);
            unset($snapshot['strategies']);

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

                        $this->localContent->write(
                            '.ff/sp/apps_fintech_mf_portfoliostimeline_snapshots/data/' . $this->timeline['snapshots_ids'][$dateToProcess] . '.json',
                            $this->helper->encode($timelineSnapshot)
                        );
                    }
                } catch (FilesystemException | UnableToCheckExistence | UnableToWriteFile | \throwable $e) {
                    $this->addResponse($e->getMessage(), 1);

                    return false;
                }
            //     // $timelineSnapshotArr = $this->getById((int) $this->timeline['snapshots_ids'][$dateToProcess]);
                // $timelineSnapshot['id'] = $this->timeline['snapshots_ids'][$dateToProcess];
            //     // $timelineSnapshot = $this->getById((int) $this->timeline['snapshots_ids'][$dateToProcess]);

            //     // if ($timelineSnapshotArr) {
            //     //     if ($forceRecalculateTimeline) {
            //     //         $timelineSnapshot['id'] = $timelineSnapshotArr['id'];//Remove everything else.
            //     //     } else {
            //     //         $timelineSnapshot = $timelineSnapshotArr;
            //     //     }
            //     // }
                // $this->update($timelineSnapshot);
            } else {
                $timelineSnapshot['snapshot']['id'] = $this->getLastInsertedId() + 1;

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
            // trace(['me']);

            $this->timeline['snapshots_ids'][$dateToProcess] = $timelineSnapshot['snapshot']['id'];

            // if (!$this->createSnapshotChunks($timelineSnapshot, $forceRecalculateTimeline)) {
            //     return false;
            // }

            // $this->switchModel();
            // try {
            //     $this->localContent->write(
            //         '.ff/sp/apps_fintech_mf_portfoliostimeline/data/' . $this->timeline['id'] . '.json',
            //         $this->helper->encode($this->timeline)
            //     );
            // } catch (FilesystemException | UnableToWriteFile | \throwable $e) {
            //     $this->addResponse($e->getMessage(), 1);

            //     return false;
            // }
            // if (!$this->update($this->timeline)) {
            //     return false;
            // }
        }

        // $this->basepackages->utils->setMicroTimer('Save Snapshot End - ' . $dateToProcess, true);
        // var_Dump($this->basepackages->utils->getMicroTimer());
        // $this->basepackages->utils->resetMicroTimer();
        // } catch (\throwable $e) {
        //     trace([$e]);
        // }
        // trace([$dateToProcess]);
            // if ($dateToProcess === '2017-10-31') {
            // if ($dateToProcess === '2025-08-01') {
                // trace(['me']);
            // }

        return true;
    }

    protected function createSnapshotChunks($timelineSnapshot, $forceRecalculateTimeline = false)
    {
        // trace([$timelineSnapshot]);
        // if (isset($this->timeline['snapshots'])) {
        //     $timelineSnapshots = $this->timeline['snapshots'];
        // } else if (isset($this->timeline['snapshots_ids'])) {
        // }
        $snapshotsIds = $this->timeline['snapshots_ids'];
        ksort($snapshotsIds);
        $timelineDateKeys = array_keys($snapshotsIds);
        // // $timelineDateKeys = array_keys($this->timeline['snapshots']);
        $timelineDateKey = array_search($timelineSnapshot['date'], $timelineDateKeys);

        // $portfolioSnapshots = array_slice($snapshotsIds, 0, $timelineDateKey + 1);
        // trace([$portfolioSnapshots]);

        // $portfolioSnapshots = msort(array: $portfolioSnapshots, key: 'timelineDate');
        // trace([$portfolioSnapshots]);
        // $totalSnapshots = count($portfolioSnapshots);

        // $this->timeline['performance_chunks'][$timelineDate]['timelineDate'] = $timelineDate;
        // $this->timeline['performance_chunks'][$timelineDate]['all'] = [];

        // $portfolioSnapshotsArr = [];

        // foreach ($portfolioSnapshots as $snapshotDate => $snapshotId) {
        //     $this->switchModel($this->snapshotsModel);

        //     if (!isset($portfolioSnapshotsArr[$snapshotDate])) {
        //         $portfolioSnapshotsArr[$snapshotDate] = $this->getById((int) $snapshotId);
        //     }

            $this->switchModel($this->performanceChunksModel);

            $previousPerformanceChunk = [];

        if ($timelineDateKey !== 0) {
            // trace([$timelineDateKeys, $timelineDateKey, $this->timeline['performance_chunks_ids'][$timelineDateKeys[$timelineDateKey - 1]]]);
            $previousPerformanceChunkArr = $this->getById((int) $this->timeline['performance_chunks_ids'][$timelineDateKeys[$timelineDateKey - 1]]);

            if ($previousPerformanceChunkArr) {
                $previousPerformanceChunk = $previousPerformanceChunkArr['performance_chunk'];
            }

            // trace([$previousPerformanceChunk]);
        }
        // trace([$this->portfolio['start_date'], $timelineSnapshot['date']]);
            if (isset($this->timeline['performance_chunks_ids'][$timelineSnapshot['date']])) {
                $timelinePerformanceChunk = [];
                $timelinePerformanceChunkArr = $this->getById((int) $this->timeline['performance_chunks_ids'][$timelineSnapshot['date']]);

                if ($timelinePerformanceChunkArr) {
                    if ($forceRecalculateTimeline) {
                        $timelinePerformanceChunk['id'] = $timelinePerformanceChunkArr['id'];//Remove everything else.
                    } else {
                        $timelinePerformanceChunk = $timelinePerformanceChunkArr;
                    }
                }
            }
            // $timelinePerformanceChunk[$timelineSnapshot['date']]['timelineDate'] = $timelineDate;

            // $this->timeline['performance_chunks'][$timelineDate]['all'][$portfolioSnapshotsArr[$timelineSnapshot['date']]['timelineDate']] = [];
            // $allPerfornace = &$this->timeline['performance_chunks'][$timelineDate]['all'][$portfolioSnapshotsArr[$timelineSnapshot['date']]['timelineDate']];
            $timelinePerformanceChunk['date'] = $timelineSnapshot['date'];
            $timelinePerformanceChunk['timeline_id'] = $timelineSnapshot['timeline_id'];
            // $timelinePerformanceChunk['performance_chunk']['timelineDate'] = $timelineSnapshot['date'];
            $thisPerformanceChunk = [];
            $thisPerformanceChunk[$timelineSnapshot['date']]['invested_amount'] = $timelineSnapshot['snapshot']['invested_amount'];
            $thisPerformanceChunk[$timelineSnapshot['date']]['return_amount'] = $timelineSnapshot['snapshot']['return_amount'];
            $thisPerformanceChunk[$timelineSnapshot['date']]['profit_loss'] = $timelineSnapshot['snapshot']['profit_loss'];

            $timelinePerformanceChunk['performance_chunk'] = array_merge($previousPerformanceChunk, $thisPerformanceChunk);

            // trace([$timelinePerformanceChunk]);
            if (isset($timelinePerformanceChunk['id'])) {
                $this->update($timelinePerformanceChunk);
            } else {
                $this->add($timelinePerformanceChunk);
            }

            if (!isset($this->packagesData->last['id'])) {
                $this->addResponse('Could not insert/update timeline performance chunk, contact developer', 1);

                return false;
            }

            $this->timeline['performance_chunks_ids'][$timelineSnapshot['date']] = $this->packagesData->last['id'];

            return true;
            // trace([$timelinePerformanceChunk]);
        // trace([$portfolioSnapshotsArr]);
        // }
        // for ($forAll = 0; $forAll < $totalSnapshots; $forAll++) {
        // }

        // if ($totalSnapshots > 1) {
        //     if ($totalSnapshots > 7) {
        //         $forWeek = $totalSnapshots - 7;
        //     } else {
        //         $forWeek = $totalSnapshots;
        //     }

        //     $this->timeline['performance_chunks'][$timelineDate]['week'] = [];
        //     for ($forWeek; $forWeek < $totalSnapshots; $forWeek++) {
        //         $this->timeline['performance_chunks'][$timelineDate]['week'][$portfolioSnapshots[$forWeek]['timelineDate']] = [];
        //         $weeklyPerformance = &$this->timeline['performance_chunks'][$timelineDate]['week'][$portfolioSnapshots[$forWeek]['timelineDate']];
        //         $weeklyPerformance['timelineDate'] = $portfolioSnapshots[$forWeek]['timelineDate'];
        //         $weeklyPerformance['invested_amount'] = $portfolioSnapshots[$forWeek]['invested_amount'];
        //         $weeklyPerformance['return_amount'] = $portfolioSnapshots[$forWeek]['return_amount'];
        //         $weeklyPerformance['profit_loss'] = $portfolioSnapshots[$forWeek]['profit_loss'];
        //     }
        // }

        // if ($totalSnapshots > 7) {
        //     if ($totalSnapshots > 30) {
        //         $forMonth = $totalSnapshots - 30;
        //     } else {
        //         $forMonth = $totalSnapshots;
        //     }

        //     $this->timeline['performance_chunks'][$timelineDate]['month'] = [];
        //     for ($forMonth; $forMonth < $totalSnapshots; $forMonth++) {
        //         $this->timeline['performance_chunks'][$timelineDate]['month'][$portfolioSnapshots[$forMonth]['timelineDate']] = [];
        //         $monthlyPerformance = &$this->timeline['performance_chunks'][$timelineDate]['month'][$portfolioSnapshots[$forMonth]['timelineDate']];
        //         $monthlyPerformance['timelineDate'] = $portfolioSnapshots[$forMonth]['timelineDate'];
        //         $monthlyPerformance['invested_amount'] = $portfolioSnapshots[$forMonth]['invested_amount'];
        //         $monthlyPerformance['return_amount'] = $portfolioSnapshots[$forMonth]['return_amount'];
        //         $monthlyPerformance['profit_loss'] = $portfolioSnapshots[$forMonth]['profit_loss'];
        //     }
        // }

        // if ($totalSnapshots > 30) {
        //     if ($totalSnapshots > 365) {
        //         $forYear = $totalSnapshots - 365;
        //     } else {
        //         $forYear = $totalSnapshots;
        //     }

        //     $this->timeline['performance_chunks'][$timelineDate]['year'] = [];
        //     for ($forYear; $forYear < $totalSnapshots; $forYear++) {
        //         $this->timeline['performance_chunks'][$timelineDate]['year'][$portfolioSnapshots[$forYear]['timelineDate']] = [];
        //         $yearlyPerformance = &$this->timeline['performance_chunks'][$timelineDate]['year'][$portfolioSnapshots[$forYear]['timelineDate']];
        //         $yearlyPerformance['timelineDate'] = $portfolioSnapshots[$forYear]['timelineDate'];
        //         $yearlyPerformance['invested_amount'] = $portfolioSnapshots[$forYear]['invested_amount'];
        //         $yearlyPerformance['return_amount'] = $portfolioSnapshots[$forYear]['return_amount'];
        //         $yearlyPerformance['profit_loss'] = $portfolioSnapshots[$forYear]['profit_loss'];
        //     }
        // }

        // if ($totalSnapshots > 365) {
        //     if ($totalSnapshots > 1095) {
        //         $forThreeYear = $totalSnapshots - 1095;
        //     } else {
        //         $forThreeYear = $totalSnapshots;
        //     }

        //     $this->timeline['performance_chunks'][$timelineDate]['threeYear'] = [];
        //     for ($forThreeYear; $forThreeYear < $totalSnapshots; $forThreeYear++) {
        //         $this->timeline['performance_chunks'][$timelineDate]['threeYear'][$portfolioSnapshots[$forThreeYear]['timelineDate']] = [];
        //         $threeYearPerformance = &$this->timeline['performance_chunks'][$timelineDate]['threeYear'][$portfolioSnapshots[$forThreeYear]['timelineDate']];
        //         $threeYearPerformance['timelineDate'] = $portfolioSnapshots[$forThreeYear]['timelineDate'];
        //         $threeYearPerformance['invested_amount'] = $portfolioSnapshots[$forThreeYear]['invested_amount'];
        //         $threeYearPerformance['return_amount'] = $portfolioSnapshots[$forThreeYear]['return_amount'];
        //         $threeYearPerformance['profit_loss'] = $portfolioSnapshots[$forThreeYear]['profit_loss'];
        //     }
        // }

        // if ($totalSnapshots > 1095) {
        //     if ($totalSnapshots > 1825) {
        //         $forFiveYear = $totalSnapshots - 1825;
        //     } else {
        //         $forFiveYear = $totalSnapshots;
        //     }

        //     $this->timeline['performance_chunks'][$timelineDate]['fiveYear'] = [];
        //     for ($forFiveYear; $forFiveYear < $totalSnapshots; $forFiveYear++) {
        //         $this->timeline['performance_chunks'][$timelineDate]['fiveYear'][$portfolioSnapshots[$forFiveYear]['timelineDate']] = [];
        //         $fiveYearPerformance = &$this->timeline['performance_chunks'][$timelineDate]['fiveYear'][$portfolioSnapshots[$forFiveYear]['timelineDate']];
        //         $fiveYearPerformance['timelineDate'] = $portfolioSnapshots[$forFiveYear]['timelineDate'];
        //         $fiveYearPerformance['invested_amount'] = $portfolioSnapshots[$forFiveYear]['invested_amount'];
        //         $fiveYearPerformance['return_amount'] = $portfolioSnapshots[$forFiveYear]['return_amount'];
        //         $fiveYearPerformance['profit_loss'] = $portfolioSnapshots[$forFiveYear]['profit_loss'];
        //     }
        // }

        // if ($totalSnapshots > 3652) {
        //     if ($totalSnapshots > 3652) {
        //         $forTenYear = $totalSnapshots - 3652;
        //     } else {
        //         $forTenYear = $totalSnapshots;
        //     }

        //     $this->timeline['performance_chunks'][$timelineDate]['tenYear'] = [];
        //     for ($forTenYear; $forTenYear < $totalSnapshots; $forTenYear++) {
        //         $this->timeline['performance_chunks'][$timelineDate]['tenYear'][$portfolioSnapshots[$forTenYear]['timelineDate']] = [];
        //         $tenYearPerformance = &$this->timeline['performance_chunks'][$timelineDate]['tenYear'][$portfolioSnapshots[$forTenYear]['timelineDate']];
        //         $tenYearPerformance['timelineDate'] = $portfolioSnapshots[$forTenYear]['timelineDate'];
        //         $tenYearPerformance['invested_amount'] = $portfolioSnapshots[$forTenYear]['invested_amount'];
        //         $tenYearPerformance['return_amount'] = $portfolioSnapshots[$forTenYear]['return_amount'];
        //         $tenYearPerformance['profit_loss'] = $portfolioSnapshots[$forTenYear]['profit_loss'];
        //     }
        // }

        // $this->timeline['performance_chunks'][$timelineDate]['all'] = [];
        // for ($forAll = 0; $forAll < $totalSnapshots; $forAll++) {
        //     $this->timeline['performance_chunks'][$timelineDate]['all'][$portfolioSnapshots[$forAll]['timelineDate']] = [];
        //     $allPerfornace = &$this->timeline['performance_chunks'][$timelineDate]['all'][$portfolioSnapshots[$forAll]['timelineDate']];
        //     $allPerfornace['timelineDate'] = $portfolioSnapshots[$forAll]['timelineDate'];
        //     $allPerfornace['invested_amount'] = $portfolioSnapshots[$forAll]['invested_amount'];
        //     $allPerfornace['return_amount'] = $portfolioSnapshots[$forAll]['return_amount'];
        //     $allPerfornace['profit_loss'] = $portfolioSnapshots[$forAll]['profit_loss'];
        // }
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