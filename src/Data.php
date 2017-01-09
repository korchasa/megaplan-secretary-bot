<?php

namespace App;

class Data
{
    const CHAT_DATA_PATH = __DIR__.'/../var/megaplan_chat_data_*.json';

    function getChat($chat_id)
    {
        $path = str_replace('*', $chat_id, self::CHAT_DATA_PATH);
        if (!file_exists($path)) {
            return (object) [
                'chat_id' => $chat_id,
                'upcoming_tasks' => [],
                'host' => null,
                'access_id' => null,
                'secret_key' => null,
            ];
        }
        return json_decode(file_get_contents($path));
    }

    function setChat($chat_id, $data)
    {
        file_put_contents(
            str_replace('*', $chat_id, self::CHAT_DATA_PATH),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    function all_chats()
    {
        return array_map(function($file) {
            return json_decode(file_get_contents($file));
        }, glob(self::CHAT_DATA_PATH));
    }
}