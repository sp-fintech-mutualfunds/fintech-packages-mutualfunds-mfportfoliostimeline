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

    public function init()
    {
        $this->today = (\Carbon\Carbon::now(new \DateTimeZone('Asia/Kolkata')))->toDateString();

        parent::init();

        return $this;
    }

    public function getPortfoliotimelineByPortfolio($portfolio)
    {
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
            $timeline = $timeline[0];
        } else {
            return false;
        }

        return $timeline;
    }

    public function getPortfoliotimelineByPortfolioAndTimeline($portfolio, $getTimelineDate = null, $force = false)
    {
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
            $timeline = $timeline[0];
        } else {
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
        if (isset($timeline['snapshots'][$getTimelineDate]) && !$force) {
            $timelinePortfolio = $timeline['snapshots'][$getTimelineDate];
        } else {
            $portfolioPackage = $this->usePackage(MfPortfolios::class);

            $timelinePortfolio = $portfolioPackage->recalculatePortfolio(['portfolio_id' => $portfolio['id']], false, $getTimelineDate);
        }

        $timelinePortfolio['beforeStartDateRequested'] = $beforeStartDateRequested;
        $timelinePortfolio['afterEndDateRequested'] = $afterEndDateRequested;
        $timelinePortfolio['end_date'] = $endDate;

        if ($timelinePortfolio) {
            if (isset($portfolioPackage)) {
                $this->addResponse(
                    $portfolioPackage->packagesData->responseMessage,
                    $portfolioPackage->packagesData->responseCode,
                    $portfolioPackage->packagesData->responseData ?? []
                );
            }

            return $timelinePortfolio;
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
        tracE([$timelinePortfolio]);
        trace([$portfolio, $data]);
    }
}