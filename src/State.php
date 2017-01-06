<?php

namespace App;

class State
{
    public $chat_id;
    public $upcoming_tasks = [];
    public $host;
    public $access_id;
    public $secret_key;

    static function fromJson($json)
    {

    }
}