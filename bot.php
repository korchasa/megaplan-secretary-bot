#!/usr/bin/env php
<?php
require "vendor/autoload.php";

use korchasa\Telegram\Telegram;

$telegram = new Telegram(getenv('TOKEN'), getenv('LOG'));
$bot = new \App\Bot(new \App\Megaplan, $telegram);
$telegram->loop(function($update) use ($bot) {
    $bot->process_update($update);
});