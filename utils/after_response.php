<?php

function mq_defer_after_response(string $key, callable $task): void
{
    if (!isset($GLOBALS['mq_after_response_tasks']) || !is_array($GLOBALS['mq_after_response_tasks'])) {
        $GLOBALS['mq_after_response_tasks'] = [];
    }

    $GLOBALS['mq_after_response_tasks'][$key] = $task;
}

function mq_run_after_response_tasks(): void
{
    $tasks = $GLOBALS['mq_after_response_tasks'] ?? [];
    $GLOBALS['mq_after_response_tasks'] = [];

    if (!$tasks || !function_exists('fastcgi_finish_request')) {
        return;
    }

    if (@fastcgi_finish_request() === false) {
        return;
    }

    ignore_user_abort(true);

    foreach ($tasks as $key => $task) {
        try {
            $task();
        } catch (Throwable $e) {
            error_log('MelodyQuest deferred task failed [' . $key . ']: ' . $e->getMessage());
        }
    }
}
