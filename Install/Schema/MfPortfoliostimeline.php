<?php

namespace Apps\Fintech\Packages\Mf\Portfoliostimeline\Install\Schema;

use Phalcon\Db\Column;
use Phalcon\Db\Index;

class MfPortfoliostimeline
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
                    'portfolio_id',
                    [
                        'type'          => Column::TYPE_INTEGER,
                        'notNull'       => true,
                    ]
                ),
                new Column(
                    'snapshots',
                    [
                        'type'          => Column::TYPE_JSON,
                        'notNull'       => true,
                    ]
                ),
                new Column(
                    'performance_chunks',
                    [
                        'type'          => Column::TYPE_JSON,
                        'notNull'       => true,
                    ]
                ),
                new Column(
                    'recalculate',
                    [
                        'type'          => Column::TYPE_BOOLEAN,
                        'notNull'       => false,
                    ]
                ),
                new Column(
                    'recalculate_from_date',
                    [
                        'type'          => Column::TYPE_VARCHAR,
                        'size'          => 15,
                        'notNull'       => false
                    ]
                )
            ],
            'indexes' => [
                new Index(
                    'column_UNIQUE',
                    [
                        'portfolio_id'
                    ],
                    'UNIQUE'
                )
            ],
            'options' => [
                'TABLE_COLLATION' => 'utf8mb4_general_ci'
            ]
        ];
    }

    public function indexes()
    {
        return
        [
            new Index(
                'column_INDEX',
                [
                    'portfolio_id'
                ],
                'INDEX'
            )
        ];
    }
}
