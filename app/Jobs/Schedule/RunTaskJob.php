<?php

namespace Pterodactyl\Jobs\Schedule;

use Exception;
use Pterodactyl\Jobs\Job;
use Carbon\CarbonImmutable;
use Pterodactyl\Models\Task;
use InvalidArgumentException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class RunTaskJob extends Job implements ShouldQueue
{
    use DispatchesJobs;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @var \Pterodactyl\Models\Task
     */
    public $task;

    /**
     * @var bool
     */
    public $manualRun;

    /**
     * RunTaskJob constructor.
     */
    public function __construct(Task $task, $manualRun = false)
    {
        $this->queue = config('pterodactyl.queues.standard');
        $this->task = $task;
        $this->manualRun = $manualRun;
    }

    /**
     * Run the job and send actions to the daemon running the server.
     *
     * @throws \Throwable
     */
    public function handle(
        DaemonCommandRepository $commandRepository,
        InitiateBackupService $backupService,
        DaemonPowerRepository $powerRepository
    ) {
        // Do not process a task that is not set to active, unless it's been manually triggered.
        if (!$this->task->schedule->is_active && !$this->manualRun) {
            $this->markTaskNotQueued();
            $this->markScheduleComplete();

            return;
        }

        $server = $this->task->server;
        // Perform the provided task against the daemon.
        try {
            switch ($this->task->action) {
                case Task::ACTION_POWER:
                    $powerRepository->setServer($server)->send($this->task->payload);
                    break;
                case Task::ACTION_COMMAND:
                    $commandRepository->setServer($server)->send($this->task->payload);
                    break;
                case Task::ACTION_BACKUP:
                    $backupService->setIgnoredFiles(explode(PHP_EOL, $this->task->payload))->handle($server, null, true);
                    break;
                default:
                    throw new InvalidArgumentException('Invalid task action provided: ' . $this->task->action);
            }
        } catch (Exception $exception) {
            // If this isn't a DaemonConnectionException on a task that allows for failures
            // throw the exception back up the chain so that the task is stopped.
            if (!($this->task->continue_on_failure && $exception instanceof DaemonConnectionException)) {
                throw $exception;
            }
        }

        $this->markTaskNotQueued();
        $this->queueNextTask();
    }

    /**
     * Handle a failure while sending the action to the daemon or otherwise processing the job.
     */
    public function failed(Exception $exception = null)
    {
        $this->markTaskNotQueued();
        $this->markScheduleComplete();
    }

    /**
     * Get the next task in the schedule and queue it for running after the defined period of wait time.
     */
    private function queueNextTask()
    {
        /** @var \Pterodactyl\Models\Task|null $nextTask */
        $nextTask = Task::query()->where('schedule_id', $this->task->schedule_id)
            ->orderBy('sequence_id', 'asc')
            ->where('sequence_id', '>', $this->task->sequence_id)
            ->first();

        if (is_null($nextTask)) {
            $this->markScheduleComplete();

            return;
        }

        $nextTask->update(['is_queued' => true]);

        $this->dispatch((new self($nextTask, $this->manualRun))->delay($nextTask->time_offset));
    }

    /**
     * Marks the parent schedule as being complete.
     */
    private function markScheduleComplete()
    {
        $this->task->schedule()->update([
            'is_processing' => false,
            'last_run_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    /**
     * Mark a specific task as no longer being queued.
     */
    private function markTaskNotQueued()
    {
        $this->task->update(['is_queued' => false]);
    }
}
