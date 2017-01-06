<?php

namespace App;

class Data
{
    const GLOBAL_DATA_PATH = '/tmp/global_data.json';
    const CHAT_DATA_PATH = '/tmp/chat_data_*.json';

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
        $data->chat_id = $chat_id;
        file_put_contents(
            str_replace('*', $chat_id, self::CHAT_DATA_PATH),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}