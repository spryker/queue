<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Shared\Queue;

/**
 * Declares global environment configuration keys. Do not use it for other class constants.
 */
interface QueueConstants
{
    /**
     * Specification:
     * - Server unique id e.g spryker-{hostname}.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_SERVER_ID = 'QUEUE_SERVER_ID';

    /**
     * Specification:
     * - Configuration of queue adapters and worker number as an array.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_ADAPTER_CONFIGURATION = 'QUEUE_ADAPTER_CONFIGURATION';

    /**
     * Specification:
     * - The Default configuration of queue adapters and worker number as an array.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_ADAPTER_CONFIGURATION_DEFAULT = 'QUEUE_ADAPTER_CONFIGURATION_DEFAULT';

    /**
     * Specification:
     * - Delay interval between each execution of worker in milliseconds.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_INTERVAL_MILLISECONDS = 'QUEUE_WORKER_INTERVAL_MILLISECONDS';

    /**
     * Specification:
     * - Delay interval between each execution of process in microsecond.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_PROCESS_TRIGGER_INTERVAL_MICROSECONDS = 'QUEUE_PROCESS_TRIGGER_INTERVAL_MICROSECONDS';

    /**
     * Specification:
     * - Worker execution time in seconds.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_MAX_THRESHOLD_SECONDS = 'QUEUE_WORKER_MAX_THRESHOLD_SECONDS';

    /**
     * Specification:
     * - Absolute path to the log of all processes output which trigger by worker.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_OUTPUT_FILE_NAME = 'QUEUE_WORKER_OUTPUT_FILE_NAME';

    /**
     * Specification:
     * - This flag will use for activation or deactivation logs for queue workers.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_LOG_ACTIVE = 'QUEUE_WORKER_LOG_ACTIVE';

    /**
     * Specification:
     * - The Default consuming/receiving configuration.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_DEFAULT_RECEIVER = 'QUEUE_DEFAULT_RECEIVER';

    /**
     * Specification:
     * - This option will use to check if there is at least one message in queue.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_MESSAGE_CHECK_OPTION = 'QUEUE_WORKER_MESSAGE_CHECK_OPTION';

    /**
     * Specification:
     * - This option lets the worker to run over a loop until there is no message in the queues.
     *
     * @api
     *
     * @deprecated Use `vendor/bin/console queue:worker:start --stop-only-when-empty` instead.
     *
     * @var string
     */
    public const QUEUE_WORKER_LOOP = 'QUEUE_WORKER_LOOP';

    /**
     * Specification:
     * - Configuration of chunk size for queue message retrieval.
     * - Example: $config[QueueConstants::QUEUE_MESSAGE_CHUNK_SIZE_MAP] = ['queueName' => 100].
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_MESSAGE_CHUNK_SIZE_MAP = 'QUEUE:QUEUE_MESSAGE_CHUNK_SIZE_MAP';

    /**
     * Specification:
     * - Recommended (optimal) memory of queue task chunk size for event message processing in KB.
     * - Used to log a warning if the task chunk data size exceeds this limit.
     * - Example: $config[QueueConstants::MAX_QUEUE_TASK_MEMORY_CHUNK_SIZE] = 1024 (1024 KB).
     *
     * @api
     *
     * @var string
     */
    public const MAX_QUEUE_TASK_MEMORY_CHUNK_SIZE = 'QUEUE:MAX_QUEUE_TASK_MEMORY_CHUNK_SIZE';

    /**
     * Specification:
     * - Recommended (optimal) memory limit for the entire queue task process in MB.
     * - Used to log a warning if the task exceeds this memory limit.
     * - The memory value should be chosen based on the total scheduler memory and the count of workers.
     * - Example: $config[QueueConstants::MAX_QUEUE_TASK_MEMORY_SIZE] = 1024 (1024 MB).
     *
     * @api
     *
     * @var string
     */
    public const MAX_QUEUE_TASK_MEMORY_SIZE = 'QUEUE:MAX_QUEUE_TASK_MEMORY_SIZE';

    /**
     * Specification:
     * - Whether wait limiting feature is enabled or not
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_WAIT_LIMIT_ENABLED = 'QUEUE:QUEUE_WORKER_WAIT_LIMIT_ENABLED';

    /**
     * Specification:
     * - Defines maximum waiting time in seconds for a pending queue worker process.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_MAX_WAITING_SECONDS = 'QUEUE:QUEUE_WORKER_MAX_WAITING_SECONDS';

    /**
     * Specification:
     * - Defines maximum waiting rounds for a pending queue worker process.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_MAX_WAITING_ROUNDS = 'QUEUE:QUEUE_WORKER_MAX_WAITING_ROUNDS';

    /**
     * Specification:
     * - Enables processing of queues with resource aware queue worker.
     *
     * @api
     *
     * @var string
     */
    public const RESOURCE_AWARE_QUEUE_WORKER_ENABLED = 'QUEUE:RESOURCE_AWARE_QUEUE_WORKER_ENABLED';

    /**
     * Specification:
     * - Max concurrent PHP processes for all queues/stores.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_MAX_PROCESSES = 'QUEUE:QUEUE_WORKER_MAX_PROCESSES';

    /**
     * Specification:
     * - Defines whether to ignore cases when system free memory can't be detected/read or parsed.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_IGNORE_MEMORY_READ_FAILURE = 'QUEUE:QUEUE_WORKER_IGNORE_MEMORY_READ_FAILURE';

    /**
     * Specification:
     * - Defines free memory buffer for reliability in MBs.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_FREE_MEMORY_BUFFER = 'QUEUE:QUEUE_WORKER_FREE_MEMORY_BUFFER';

    /**
     * Specification:
     * - Defines timeout for memory read process(command) in seconds.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_MEMORY_READ_PROCESS_TIMEOUT = 'QUEUE:QUEUE_WORKER_MEMORY_READ_PROCESS_TIMEOUT';

    /**
     * Specification:
     * - Defines a percentage by how much Worker can increase its own memory consumption within PHP process limit.
     * - When a limit reached - Worker will finish its job as usual to prevent memory leaks.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_MEMORY_MAX_GROWTH_FACTOR = 'QUEUE:QUEUE_WORKER_MEMORY_MAX_GROWTH_FACTOR';

    /**
     * Specification:
     *  - Defines timeout for waiting all run processes become completed in seconds.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_PROCESSES_COMPLETE_TIMEOUT = 'QUEUE:QUEUE_WORKER_PROCESSES_COMPLETE_TIMEOUT';

    /**
     * Specification:
     * - Defines interval for checking the processes are completed in milliseconds.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_WORKER_CHECK_PROCESSES_COMPLETE_INTERVAL_MILLISECONDS = 'QUEUE:QUEUE_WORKER_CHECK_PROCESSES_COMPLETE_INTERVAL_MILLISECONDS';

    /**
     * Specification:
     * - Defines the mode of the queue processing worker.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_PROCESSING_WORKER_DYNAMIC_MODE = 'QUEUE:QUEUE_PROCESSING_WORKER_DYNAMIC_MODE';

    /**
     * Specification:
     * - Defines the threshold of the big queue.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_PROCESSING_BIG_QUEUE_THRESHOLD_BATCHES_AMOUNT = 'QUEUE:QUEUE_PROCESSING_BIG_QUEUE_THRESHOLD_BATCHES_AMOUNT';

    /**
     * Specification:
     * - Defines the limit of the processes per queue.
     *
     * @api
     *
     * @var string
     */
    public const QUEUE_PROCESSING_LIMIT_OF_PROCESSES_PER_QUEUE = 'QUEUE:PROCESSING_LIMIT_OF_PROCESSES_PER_QUEUE';
}
