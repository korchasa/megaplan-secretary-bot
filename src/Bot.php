<?php

namespace App;

use korchasa\Telegram\Telegram;
use korchasa\Telegram\Structs\Payload\AbstractPayload;
use korchasa\Telegram\Structs\Payload\InlineKeyboard;
use korchasa\Telegram\Structs\Payload\InlineButton;
use korchasa\Telegram\Structs\Update;
use korchasa\Telegram\Structs\Message;
use korchasa\Telegram\Structs\Chat;
use korchasa\Telegram\Unstructured;

class Bot
{
    const STATUS_INBOX = "inbox";
    const STATUS_UPCOMING = "upcoming";
    const STATUS_ARCHIVE = "archive";

    protected $data;
    protected $megaplan;
    protected $telegram;

    protected static $new_task_prefixes = ['+', 'Плюс '];
    protected static $megaplan_statuses_for_inbox = ["accepted", "assigned", "actual", "inprocess", "new"];

    public function __construct($megaplan, $telegram)
    {
        $this->data = new Data;
        $this->megaplan = $megaplan;
        $this->telegram = $telegram;
    }

    public function process_update($update)
    {
        try {
            $state = $this->data->getChat($update->chat()->id);
            $action = 'action_'.$this->select_action($state, $update);
            if (!method_exists($this, $action)) {
                throw new \LogicException('Method '.$action.' not found');
            }
            $this->log('Info', 'Selected action: %s', $action);
            call_user_func([$this, $action], $state, $update);
            $this->data->setChat($update->chat()->id, $state);
        } catch (\Exception $e) {
            $this->message($state, '<i>Что-то пошло не так. '.$e->getMessage().'</i>');
            $this->log('Error', get_class($e).':'.$e->getMessage());
        }
    }

    function select_action($state, $update)
    {
        if ($update->isText() && !$state->host) {
            return 'auth';
        } elseif ($update->isCallbackQuery()) {
            return $update->callback_query->data;
        } elseif ($update->isReply()) {
            if (in_array($update->message()->text, ['готово', 'сделано', 'закрыть', 'закрыто'])) {
                return 'complete_task';
            } else {
                if (false !== strtotime($update->message->text)) {
                    return 'delay_task';
                }
            }
        } elseif ($update->isText()) {
            $text = trim($update->message()->text);
            if ($this->starts_with($text, ['задачи', '/задачи', 'список задач', 'мои задачи'])) {
                return self::STATUS_INBOX;
            } elseif ($this->starts_with($text, ['/'])) {
                return 'info';
            } elseif ($this->starts_with($text, self::$new_task_prefixes)) {
                return 'new_task';
            } else {
                return 'info';
            }
        } else {

        }
    }

    public function action_auth($state, $update)
    {
        $parts = explode(" ", $update->message()->text);
        if (3 === count($parts)) {
            try {
                $client = (new Megaplan(trim($parts[0])))
                  ->auth(trim($parts[1]), trim($parts[2]));
                $state->host = trim($parts[0]);
                $state->access_id = $client->accessId();
                $state->secret_key = $client->secretKey();

            } catch (\Exception $e) {
                $update->replyMessage('Что-то пошло не так. Попробуйте еще раз');
            }
        } else {
            $update->replyMessage('Введите ваш аккаунт, email и пароль к Мегаплану, через пробел. Например, "ivanoff.megaplan.ru ivan@ivanoff.com qwerty"');
        }
    }

    function action_inbox($state, $update)
    {
        return $this->_actionTasksList($state, self::STATUS_INBOX);
    }

    function action_upcoming($state, $update)
    {
        return $this->_actionTasksList($state, self::STATUS_UPCOMING);
    }

    function action_archive($state, $update)
    {
        return $this->_actionTasksList($state, self::STATUS_ARCHIVE);
    }

    function action_new_task($state, $update)
    {
        $text = $this->ltrim($update->message()->text, self::$new_task_prefixes);
        $resp1 = $this->megaplan($state)->post('/BumsCommonApiV01/UserInfo/id.api');
        $resp2 = $this->megaplan($state)->post('/BumsTaskApiV01/Task/create.api', [
            'Model' => [
                'Name' => $text,
                'Responsible' => $resp1->data->EmployeeId
            ]
        ]);
        $this->action_inbox($state, $update);
    }

    function action_complete_task($state, $update)
    {
        $megaplan = $this->megaplan($state);

        $task = $megaplan->findOneTaskByName(
            $update->message->reply_to_message->text,
            self::$megaplan_statuses_for_inbox
        );

        if ('assigned' == $task->Status) {
            $resp = $megaplan->post('/BumsTaskApiV01/Task/action.api', [
                'Id' => $task->Id,
                'Action' => 'act_accept_task'
            ]);
            $task->Status = 'accepted';
        }

        if ('accepted' == $task->Status) {
            $resp = $megaplan->post('/BumsTaskApiV01/Task/action.api', [
                'Id' => $task->Id,
                'Action' => 'act_done'
            ]);
            $task->Status = 'accepted';
        }

        $this->action_inbox($state, $update);
    }

    function action_delay_task($state, $update)
    {
        $megaplan = $this->megaplan($state);

        $task = $megaplan->findOneTaskByName(
            $update->message->reply_to_message->text,
            array_merge(self::$megaplan_statuses_for_inbox, ['delayed'])
        );

        $resp = $megaplan->post('/BumsTaskApiV01/Task/action.api', [
            'Id' => $task->Id,
            'Action' => 'act_pause'
        ]);

        $new_time = strtotime($update->message->text);

        $state->upcoming_tasks[] = [
            'id' => $task->Id,
            'until' => $new_time
        ];

        $resp = $megaplan->post('/BumsCommonApiV01/Comment/create.api', [
            'SubjectType' => 'task',
            'SubjectId' => $task->Id,
            'Model' => ['Text' => 'Отложена до '.date('c', $new_time)]
        ]);
    }

    function action_info($state, Update $update)
    {
        $update->replyMessage(
            '<b>Использование:</b>
    1. Отправляйте сообщения после знака +, чтобы добавлять новые задачи:
        <i>+ Проверить договор по ООО "Нога и корыто"</i>
            2. Reply with date or time to defer: <i>15 min</i> | <i>next week</i> | <i>2017.01.01 07:00:00</i>
            3. Reply with text to archive: <i>done!</i>',
            $this->makeButtons('info')
        );
    }

    function _actionTasksList($state, $status)
    {
        $resp = $this->megaplan($state)->post(
            '/BumsTaskApiV01/Task/list.api', [
                'Folder' => 'responsible'
            ]
        );
        $tasks = $resp->data->tasks;

        $filtered_tasks = array_values(array_filter($resp->data->tasks, function($task) use ($state, $status) {
            $task_status = $this->task_status($state, $task);
            return $status === $task_status;
        }));

        if (!$filtered_tasks) {
            $filtered_tasks = [(object) [
                'Status' => 'new',
                'Name' => '<b>'.ucfirst($this->status_name($status)).":</b>\nПусто"
            ]];
        } else {
            $this->message(
                $state,
                '<b>'.ucfirst($this->status_name($status)).':</b>'
            );
        }

        foreach ($filtered_tasks as $i => $task) {
            $task_status = $this->task_status($state, $task);

            if (self::STATUS_ARCHIVE === $task_status) {
                $text = "<i>{$task->Name}</i>";
            } elseif (self::STATUS_UPCOMING === $task_status) {
                $text = "{$task->Name} - <i>".date('Y.m.d H:i:s', $task->upcoming_at).'</i>';
            } else {
                $text = "{$task->Name}";
            }

            if ($i !== (count($filtered_tasks) - 1)) {
                $this->message($state, $text);
            } else {
                $this->message($state, $text, $this->makeButtons($status));
            }
        }
    }

    function megaplan($state)
    {
        return $this->megaplan
            ->setHost($state->host)
            ->setAccessId($state->access_id)
            ->setSecretKey($state->secret_key);
    }

    function process_upcoming_tasks()
    {
        global $telegram;
        foreach(glob(CHAT_DATA_PATH) as $file)
        {
            $state = json_decode(file_get_contents($file));
            foreach ($state->tasks as $task) {
                if (self::STATUS_UPCOMING === $task->status && time() > $task->upcoming_at) {
                    $chat = new Chat;
                    $chat->id = $state->chat_id;
                    $message = new Message;
                    $message->chat = $chat;
                    $update = new Update;
                    $update->message = $message;
                    $update->telegram = $telegram;
                    $this->show_tasks($state, self::STATUS_INBOX);

                    $task->status = self::STATUS_INBOX;
                }
            }
            file_put_contents(
                $file,
                json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }
    }

    function log($level, $pattern)
    {
        echo vsprintf(
            "%s\t%s\t".$pattern.PHP_EOL,
            array_merge(
                [
                    date('c'),
                    $level
                ],
                array_slice(func_get_args(), 2)
            )
        );
    }

    function status_name($status)
    {
        return [
            self::STATUS_INBOX => 'Сейчас',
            self::STATUS_UPCOMING => 'Потом',
            self::STATUS_ARCHIVE => 'Сделано'
        ][$status];
    }

    function task_status($state, $task)
    {
        foreach ($state->upcoming_tasks as $utask) {
            if ($task->Name === $utask->id) {
                return self::STATUS_UPCOMING;
            }
        }

        if (in_array($task->Status, self::$megaplan_statuses_for_inbox)) {
            return self::STATUS_INBOX;
        }

        return self::STATUS_ARCHIVE;
    }

    function makeButtons($except)
    {
        $all_buttons = [
            new InlineButton($this->status_name(self::STATUS_INBOX), self::STATUS_INBOX),
            new InlineButton($this->status_name(self::STATUS_UPCOMING), self::STATUS_UPCOMING),
            new InlineButton($this->status_name(self::STATUS_ARCHIVE), self::STATUS_ARCHIVE),
            new InlineButton('?', 'info')
        ];
        return new InlineKeyboard([
            array_values(array_filter($all_buttons, function($button) use ($except) {
                return $button->callback_data !== $except;
            }))
        ]);
    }

    function ltrim($string, $words_or_words)
    {
        $string = trim($string);

        if (!is_array($words_or_words)) {
            $words_or_words = [$words_or_words];
        }

        foreach ($words_or_words as $word) {
            if (0 === mb_strpos($string, $word)) {
                $string = mb_substr($string, mb_strlen($word));
            }
        }

        return $string;
    }

    function starts_with($haystack, $needles)
    {
        foreach ($needles as $needle) {
            if (starts_with($haystack, mb_strtolower($needle))) {
                return true;
            }
        }
        return false;
    }

    function message(
        $state,
        $text,
        AbstractPayload $reply_markup = null,
        $reply_to_message_id = null,
        $disable_web_page_preview = false,
        $parse_mode = 'html',
        $disable_notifications = true
    ) {
        $chat = new Chat;
        $chat->id = $state->chat_id;

        return $this->telegram->sendMessage(
            $chat,
            $text,
            $reply_markup,
            $reply_to_message_id,
            $disable_web_page_preview,
            $parse_mode,
            $disable_notifications
        );
    }
}