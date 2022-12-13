<?php

namespace Bendt\CoolLog\Support;

use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class CoolLoggerHandler extends AbstractProcessingHandler
{
    protected array $logger_config = [];

    public function __construct($level = Logger::DEBUG, array $logger_config = [])
    {
        parent::__construct($level);
        $this->logger_config = $logger_config;
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records): void
    {
        $batch = [];
        foreach ($records as $record) {
            $batch[] = array_merge(
                $record["extra"],
                [
                    "created_at" => now(),
                    "updated_at" => now(),
                ]
            );
        }


        try {
            DB::connection($this->logger_config["database"])
                ->table("exception_queues")
                ->insert($batch);
        } catch (\Exception $e) {
            // Slack log
            throw $e;
        }
    }

    protected function write(array $record): void
    {
        $this->handleBatch([$record]);
    }
}
