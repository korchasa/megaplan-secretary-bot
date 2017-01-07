<?php

namespace App;

use Megaplan\SimpleClient\Client;
use Prophecy\Argument;

class Megaplan extends Client
{
    public function __construct()
    {
        $this->timeout = 10;
    }

    public function accessId()
    {
        return $this->accessId;
    }

    public function secretKey()
    {
        return $this->secretKey;
    }

    public function setHost($host)
    {
        $this->host = $host;

        return $this;
    }

    public function getHost()
    {
        return $this->host;
    }

    public static function new($state)
    {
        $megaplan = new self;
        $megaplan
            ->setHost($state->host)
            ->setAccessId($state->access_id)
            ->setSecretKey($state->secret_key);
        return $megaplan;
    }

    public function findOneTaskByName($task_name, $valid_statuses = null)
    {
        $short_task_name = explode("\n", $task_name)[0];

        $resp = $this->post('/BumsTaskApiV01/Task/list.api', [
            'Folder' => 'responsible',
            'Search' => $short_task_name,
            'ShowActions' => true,
        ]);

        if ($valid_statuses) {
            $tasks = array_values(
                array_filter($resp->data->tasks, function($task) use ($valid_statuses) {
                    return in_array($task->Status, $valid_statuses);
                })
            );
        } else {
            $tasks = $resp->data->tasks;
        }

        if (1 === count($tasks)) {
            return $tasks[0];
        } elseif(0 === count($tasks)) {
            throw new \LogicException('Не получилось найти эту задачу в Мегаплане');
        } else {
            throw new \LogicException('У этой задачи в Мегаплане завёлся дубликат');
        }
    }
}