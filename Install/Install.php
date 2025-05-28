<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline\Install;

use Apps\Fintech\Packages\Mf\Portfoliostimeline\Install\Schema\MfPortfoliostimeline;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Install\Schema\MfPortfoliostimelinePerformanceChunks;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Install\Schema\MfPortfoliostimelineSnapshots;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimeline;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimelinePerformanceChunks;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\Model\AppsFintechMfPortfoliostimelineSnapshots;
use System\Base\BasePackage;
use System\Base\Providers\ModulesServiceProvider\DbInstaller;

class Install extends BasePackage
{
    protected $databases;

    protected $dbInstaller;

    public function init()
    {
        $this->databases =
            [
                'apps_fintech_mf_portfoliostimeline'  => [
                    'schema'        => new MfPortfoliostimeline,
                    'model'         => new AppsFintechMfPortfoliostimeline
                ],
                'apps_fintech_mf_portfoliostimeline_snapshots'  => [
                    'schema'        => new MfPortfoliostimelineSnapshots,
                    'model'         => new AppsFintechMfPortfoliostimelineSnapshots
                ],
                'apps_fintech_mf_portfoliostimeline_performance_chunks'  => [
                    'schema'        => new MfPortfoliostimelinePerformanceChunks,
                    'model'         => new AppsFintechMfPortfoliostimelinePerformanceChunks
                ]
            ];

        $this->dbInstaller = new DbInstaller;

        return $this;
    }

    public function install()
    {
        $this->preInstall();

        $this->installDb();

        $this->postInstall();

        return true;
    }

    protected function preInstall()
    {
        return true;
    }

    public function installDb()
    {
        $this->dbInstaller->installDb($this->databases);

        return true;
    }

    public function postInstall()
    {
        //Do anything after installation.
        return true;
    }

    public function truncate()
    {
        $this->dbInstaller->truncate($this->databases);
    }

    public function uninstall($remove = false)
    {
        if ($remove) {
            //Check Relationship
            //Drop Table(s)
            $this->dbInstaller->uninstallDb($this->databases);
        }

        return true;
    }
}