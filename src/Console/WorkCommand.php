<?php

namespace Jiyis\Nsq\Console;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Console\WorkCommand as BaseWorkCommand;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Config;

class WorkCommand extends BaseWorkCommand
{

    /**
     * Create a new queue work command.
     *
     * @param  \Illuminate\Queue\Worker $worker
     * @return void
     */
    public function __construct(Worker $worker, Cache $cache)
    {
        $this->signature .= "{--job= : The consumer namespace}";
        parent::__construct($worker, $cache);
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->hasOption('job')) {
            Config::set(['consumer_job' => $this->option('job')]);
        }

        if (method_exists(get_parent_class($this), 'handle')) {
            return parent::handle();
        }

        return parent::fire();

    }


}