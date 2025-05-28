<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline\Install\Schema;

use Phalcon\Db\Column;
use Phalcon\Db\Index;

class MfPortfoliostimelinePerformanceChunks
{
    public function columns()
    {
        return
        [
           'columns' => [
                new Column(
                    'id',
                    [
                        'type'          => Column::TYPE_INTEGER,
                        'notNull'       => true,
                        'autoIncrement' => true,
                        'primary'       => true,
                    ]
                ),
                new Column(
                    'timeline_id',
                    [
                        'type'          => Column::TYPE_INTEGER,
                        'notNull'       => true,
                    ]
                ),
                new Column(
                    'date',
                    [
                        'type'          => Column::TYPE_VARCHAR,
                        'size'          => 15,
                        'notNull'       => true
                    ]
                ),
                new Column(
                    'performance_chunk',
                    [
                        'type'          => Column::TYPE_JSON,
                        'notNull'       => true,
                    ]
                ),
            ],
            'indexes' => [
                new Index(
                    'column_UNIQUE',
                    [
                        'timeline_id',
                        'date'
                    ],
                    'UNIQUE'
                )
            ],
            'options' => [
                'TABLE_COLLATION' => 'utf8mb4_general_ci'
            ]
        ];
    }
}
