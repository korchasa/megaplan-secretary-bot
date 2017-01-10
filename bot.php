#!/usr/bin/env php
<?php
require "vendor/autoload.php";

use korchasa\Telegram\Telegram;

$telegram = new Telegram(getenv('TOKEN'), getenv('LOG'));
$bot = new \App\Bot(new \App\Megaplan, $telegram);

$update = (object) ['update_id' => 0];

while(true) {
    foreach ($telegram->getUpdates($update->update_id + 1) as $update) {
        $update->telegram = $telegram;
        $bot->process_update($update);
    }
    $bot->process_upcoming_tasks();
}