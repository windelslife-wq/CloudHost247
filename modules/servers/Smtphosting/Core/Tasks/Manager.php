<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core\Tasks;

use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\Models\Tasks\Task;

/**
 * Task Manager
 *
 * Processes queued tasks in batches, executing each task's associated model
 * action and updating the task status accordingly.
 */
class Manager
{
    /**
     * Default number of tasks to process per batch.
     *
     * @var int
     */
    protected $batchSize = 50;

    /**
     * Current page offset for pagination.
     *
     * @var int
     */
    protected $page = 1;

    /**
     * @param int $batchSize Number of tasks to process per run cycle.
     */
    public function __construct($batchSize = 50)
    {
        $this->batchSize = max(1, (int) $batchSize);
    }

    /**
     * Process a batch of pending tasks.
     *
     * Each pending task is marked as started, its associated model action is
     * executed, and the task is then marked as finished. If the model action
     * throws an exception the task is cancelled and the error is logged.
     *
     * @return int Number of tasks successfully processed in this batch.
     */
    public function run()
    {
        $processed = 0;

        foreach ($this->getTasks() as $task) {
            try {
                $task->start();

                $model = $task->model();
                if (method_exists($model, 'executeTask')) {
                    $model->executeTask($task);
                }

                $task->finish();
                $processed++;
            } catch (\Exception $e) {
                $task->cancel();

                if (class_exists('\\ModulesGarden\\ProductsReseller\\Server\\Smtphosting\\Core\\ServiceLocator')) {
                    try {
                        \ModulesGarden\ProductsReseller\Server\Smtphosting\Core\ServiceLocator::call('errorManager')
                            ->addError(self::class, $e->getMessage(), $e->getTrace());
                    } catch (\Exception $logEx) {
                        // Swallow logging failures to avoid masking the original error.
                    }
                }
            }
        }

        return $processed;
    }

    /**
     * Set the page offset for pagination.
     *
     * @param int $page 1-based page number.
     * @return $this
     */
    public function setPage($page)
    {
        $this->page = max(1, (int) $page);
        return $this;
    }

    /**
     * Set the batch size (number of tasks per page).
     *
     * @param int $batchSize
     * @return $this
     */
    public function setBatchSize($batchSize)
    {
        $this->batchSize = max(1, (int) $batchSize);
        return $this;
    }

    /**
     * Fetch a paginated batch of pending tasks, ordered oldest-first.
     *
     * @return \Illuminate\Database\Eloquent\Collection|Task[]
     */
    protected function getTasks()
    {
        $offset = ($this->page - 1) * $this->batchSize;

        return Task::where('status', Task::PENDING)
            ->orderBy('id', 'asc')
            ->skip($offset)
            ->take($this->batchSize)
            ->get();
    }
}
