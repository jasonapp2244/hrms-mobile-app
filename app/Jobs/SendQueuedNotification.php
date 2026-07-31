<?php

namespace App\Jobs;

use Illuminate\Notifications\SendQueuedNotifications;

/**
 * The job that carries a queued notification to the worker.
 *
 * Laravel's own SendQueuedNotifications copies only `tries`, `timeout`,
 * `maxExceptions` and `afterCommit` off the notification. Everything else set on
 * a notification class — `deleteWhenMissingModels`, `backoff` — is never read,
 * because the queue reflects on the *job* class to decide what to do:
 *
 *     CallQueuedHandler::handleModelNotFound()
 *       → (new ReflectionClass($jobClass))->getDefaultProperties()['deleteWhenMissingModels']
 *
 * So the settings have to live here. AppServiceProvider binds this class in
 * place of the framework's, which NotificationSender resolves from the container.
 */
class SendQueuedNotification extends SendQueuedNotifications
{
    /**
     * A notification whose subject has been deleted is discarded, not failed.
     *
     * The notification is about the record. If a leave request is withdrawn or
     * removed between queueing and sending, there is nothing left to tell anyone
     * — and failing the job instead fills failed_jobs with entries that read like
     * a broken mail pipeline when nothing is broken.
     */
    public $deleteWhenMissingModels = true;

    /**
     * Seconds between attempts. Retry quickly once, then give a struggling mail
     * server room rather than hammering it. `tries` still comes from the
     * notification, which the framework does copy across.
     */
    public $backoff = [30, 120];
}
