<?php

declare(strict_types=1);

namespace App;

use korchasa\Telegram\Telegram;
use korchasa\Telegram\Structs\Payload\AbstractPayload;
use korchasa\Telegram\Structs\Payload\InlineKeyboard;
use korchasa\Telegram\Structs\Payload\InlineButton;
use korchasa\Telegram\Structs\Update;
use korchasa\Telegram\Structs\Chat;

class Bot
{
    const STATUS_INBOX = "inbox";
    const STATUS_UPCOMING = "upcoming";
    const STATUS_ARCHIVE = "archive";

    /** @var Data */
    protected $data;
    /** @var Megaplan */
    protected $megaplan;
    /** @var Telegram */
    protected $telegram;

    protected static $new_task_prefixes = ['+', 'Плюс '];
    protected static $megaplan_statuses_for_inbox = ["accepted", "assigned", "actual", "inprocess", "new"];
    protected static $tasks_list_prefixes = ['задачи', 'список задач', 'мои задачи'];
    protected static $task_complete_words = ['готово', 'сделано', 'закрыть', 'закрыто'];
    protected static $comment_signature = "\n\n//Отправленно из Зама для Мегаплана//";

    public function __construct($megaplan, $telegram)
    {
        $this->data = new Data;
        $this->megaplan = $megaplan;
        $this->telegram = $telegram;
    }

    public function process_update(Update $update)
    {
        $state = $this->data->getChat($update->chat()->id);
        try {

            $action = $this->select_action($update, $state);
            $method = 'action_'.$action;
            if (!method_exists($this, $method)) {
                throw new \LogicException('Я запутался, и не понимаю что значит "'.$action.'"');
            }
            $this->log('Info', 'Selected action: %s', $action);
            call_user_func([$this, $method], $update, $state);
            $this->data->setChat($update->chat()->id, $state);
        } catch (\Throwable $e) {
            $this->message($state, '<i>'.$e->getMessage().'</i>');
            $this->log('Error', get_class($e).': '.$e->getMessage());
        }
    }

    function select_action(Update $update, $state): string
    {
        if ($update->isText() && '/demo' === $update->message()->text) {
            return 'demo';
        } elseif (!$state->host) {
            return 'auth';
        } elseif ($update->isCallbackQuery()) {
            return $update->callback_query->data;
        } elseif ($update->isReply()) {
            $text = trim(mb_strtolower($update->message()->text));
            if ($this->str_contains($text, self::$task_complete_words)) {
                return 'complete_task';
            } else {
                if (false !== strtotime($text)) {
                    return 'delay_task';
                } else {
                    return 'help_with_unknown';
                }
            }
        } elseif ($update->isText()) {
            $text = trim(mb_strtolower($update->message()->text));
            if ($this->starts_with($text, self::$tasks_list_prefixes)) {
                return self::STATUS_INBOX;
            } elseif ($this->starts_with($text, ['/'])) {
                return mb_substr($text, 1);
            } elseif ($this->starts_with($text, self::$new_task_prefixes)) {
                return 'new_task';
            } else {
                return 'help_with_unknown';
            }
        } else {
            return 'unknown';
        }
    }

    public function action_auth(Update $update, $state)
    {
        $parts = explode(" ", $update->message()->text);
        if (3 === count($parts)) {
            $client = (new Megaplan())
                ->setHost(trim($parts[0]))
                ->auth(trim($parts[1]), trim($parts[2]));
            $state->host = trim($parts[0]);
            $state->access_id = $client->accessId();
            $state->secret_key = $client->secretKey();
            $this->action_inbox($state);
        } else {
            $update->replyMessage(<<<EOD
Если вы хотите потренироваться на демо-аккаунте, отправьте /demo.

Или введите ваш аккаунт, email и пароль к Мегаплану, через пробел. Например: \n<i>ivanoff.megaplan.ru ivan@ivanoff.com qwerty</i>
EOD
            );
        }
    }

    function action_demo(Update $update, $state)
    {
        $this->action_logout($update, $state);

        $megaplan = (new Megaplan())
            ->setHost($host = 'korchasa.megaplan.ru')
            ->setAccessId('Ef247061a56dcc19c14d')
            ->setSecretKey('3773e0af2bDd2269eF157660109Ba19f1432957b');

        $last_names = [
            'Благочестивый', 'Набожный', 'Лысый', 'Красивый',
            'Железный', 'Безумный', 'Заячья лапа', 'Железнобокий',
            'Длиноногий', 'Грамотей', 'Исповедник', 'Мученик', 'Синезубый',
            'Пешеход', 'Кровавая Секира', 'Орлиный Котёл', 'Детолюб', 'Голоногий',
            'Дутая Голова', 'Широкобородый', 'Связанные Ноги'
        ];

        $first_names = [
            'Блейд', 'Гамбит', 'Халк', 'Тор', 'Росомаха', 'Бетмен', 'Флэш', 'Роршах', 'Дэдпул',
            'Супермен', 'Айронмэн', 'Кейдж', 'Дракс', 'Добрыня', 'Алеша', 'Илья'
        ];

        $megaplan->post('/BumsStaffApiV01/Employee/create.api', [
            'Model' => [
                'Login' => $login = 'demo-'.str_random(3),
                'FirstName' => $first = $first_names[array_rand($first_names)],
                'LastName' => $last = $last_names[array_rand($last_names)],
                'Password' => $password = 'password',
                'Position' => 'Менеджер'
            ]
        ]);

        $update->replyMessage('Привет, '.$first.' '.$last.'!');

        $client = (new Megaplan())
                ->setHost($host)
                ->auth($login, $password);
        $state->host = trim($host);
        $state->access_id = $client->accessId();
        $state->secret_key = $client->secretKey();

        $this->action_inbox($state);
    }

    function action_inbox($state)
    {
        $this->_actionTasksList($state, self::STATUS_INBOX);
    }

    function action_upcoming($state)
    {
        $this->_actionTasksList($state, self::STATUS_UPCOMING);
    }

    function action_archive($state)
    {
        $this->_actionTasksList($state, self::STATUS_ARCHIVE);
    }

    function action_new_task(Update $update, $state)
    {
        $text = $this->ltrim($update->message()->text, self::$new_task_prefixes);
        $resp1 = Megaplan::new($state)->post('/BumsCommonApiV01/UserInfo/id.api');
        Megaplan::new($state)->post('/BumsTaskApiV01/Task/create.api', [
            'Model' => [
                'Name' => $text,
                'Responsible' => $resp1->data->EmployeeId
            ]
        ]);
        $this->action_inbox($state);
    }

    function action_complete_task(Update $update, $state)
    {
        $megaplan = Megaplan::new($state);

        $task = $megaplan->findOneTaskByName(
            $update->message->reply_to_message->text,
            array_merge(self::$megaplan_statuses_for_inbox, ['delayed', 'done', 'completed'])
        );

        $makeAction = function($action) use ($megaplan, $task) {
            return $megaplan->post('/BumsTaskApiV01/Task/action.api', [
                'Id' => $task->Id,
                'Action' => $action
            ]);
        };

        if ('delayed' === $task->Status) {
            $makeAction('act_resume');
            $task->Status = 'accepted';
        }

        if ('assigned' === $task->Status) {
            $makeAction('act_accept_task');
            $task->Status = 'accepted';
        }

        if ('accepted' === $task->Status) {
            $makeAction('act_done');
        }

        $text = $update->message()->text;
        foreach (self::$task_complete_words as $word) {
            $text = str_replace($word, "**$word**", $text);
        }
        $megaplan->post('/BumsCommonApiV01/Comment/create.api', [
            'SubjectType' => 'task',
            'SubjectId' => $task->Id,
            'Model' => ['Text' => $text.self::$comment_signature]
        ]);

        $this->action_inbox($state);
    }

    function action_delay_task(Update $update, $state)
    {
        $megaplan = Megaplan::new($state);

        $task = $megaplan->findOneTaskByName(
            $update->message->reply_to_message->text,
            array_merge(self::$megaplan_statuses_for_inbox, ['delayed'])
        );

        $megaplan->post('/BumsTaskApiV01/Task/action.api', [
            'Id' => $task->Id,
            'Action' => 'act_pause'
        ]);

        $new_time = strtotime($update->message->text);

        $state->upcoming_tasks[] = [
            'id' => $task->Id,
            'upcoming_at' => $new_time
        ];

        $megaplan->post('/BumsCommonApiV01/Comment/create.api', [
            'SubjectType' => 'task',
            'SubjectId' => $task->Id,
            'Model' => ['Text' => 'Отложена до '.date('Y.m.d H:i', $new_time).self::$comment_signature]
        ]);

        $this->action_inbox($state);
    }

    function action_help(Update $update)
    {
        $message = '
С моей помощью вы можете:   

<b>Просматривать списки задач</b>
Отправьте сообщение с любым словом из <i>'.implode('</i>, <i>', self::$tasks_list_prefixes).'</i> или используя команду /inbox.

<b>Создавать задачи</b>
Отправляйте сообщения после знака <b>+</b>
    <i>+ Проверить договор по ООО "Нога и корыто"</i>

<b>Откладывать задачи</b>
Ответьте на сообщение с задачей, указав время
    <i>15 min</i> | <i>next week</i> | <i>2017.01.01 07:00</i>

<b>Завершать задачи</b>
Ответьте на сообщение с задачей, с любым словом из <i>'.implode('</i>, <i>', self::$task_complete_words).'</i>.
';
        $update->replyMessage($message, $this->menu('help'));
    }

    function action_help_with_unknown(Update $update)
    {
        $update->replyMessage('Я не понимаю, что вы имеете ввиду.');
        $this->action_help($update);
    }

    function action_logout(Update $update, $state)
    {
        $update->replyMessage('До свиданья!');
        $state->host = null;
    }

    function _actionTasksList($state, $status)
    {
        $resp = Megaplan::new($state)->post(
            '/BumsTaskApiV01/Task/list.api', [
                'Folder' => 'responsible'
            ]
        );

        $filtered_tasks = array_values(array_filter($resp->data->tasks, function($task) use ($state, $status) {
            $task_status = $this->task_status($task);
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
            $task_status = $this->task_status($task);

            if (self::STATUS_ARCHIVE === $task_status) {
                $text = "<i>{$task->Name}</i>";
            } elseif (self::STATUS_UPCOMING === $task_status) {
                $upcoming = head(array_filter(
                    $state->upcoming_tasks,
                    function($upcoming) use ($task) {
                        return $task->Id == $upcoming->id;
                    }
                ));
                $upcoming_at = $upcoming
                    ? date('Y.m.d H:i', $upcoming->upcoming_at)
                    : 'на неопределенный срок';

                $text = "{$task->Name}<i>\n".$upcoming_at.'</i>';
            } else {
                $text = "{$task->Name}";
            }

            if ($i !== (count($filtered_tasks) - 1)) {
                $this->message($state, $text);
            } else {
                $this->message($state, $text, $this->menu($status));
            }
        }
    }

    function process_upcoming_tasks()
    {
        $data = new Data;
        foreach($data->all_chats() as $state)
        {
            foreach ($state->upcoming_tasks as $i => $task) {
                if (time() > $task->upcoming_at) {
                    $resp = Megaplan::new($state)->post('/BumsTaskApiV01/Task/action.api', [
                        'Id' => $task->id,
                        'Action' => 'act_resume'
                    ]);

                    if ('ok' === $resp->status->code) {
                        unset($state->upcoming_tasks[$i]);
                        $state->upcoming_tasks = array_values($state->upcoming_tasks);
                    }

                    $this->log('Info', 'Returned from delayed: '.$task->id);
                }
            }
            $this->data->setChat($state->chat_id, $state);
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

    function task_status($task)
    {
        if ('delayed' === $task->Status) {
            return self::STATUS_UPCOMING;
        }

        if (in_array($task->Status, self::$megaplan_statuses_for_inbox)) {
            return self::STATUS_INBOX;
        }

        return self::STATUS_ARCHIVE;
    }

    function menu($except)
    {
        $all_buttons = [
            new InlineButton($this->status_name(self::STATUS_INBOX), self::STATUS_INBOX),
            new InlineButton($this->status_name(self::STATUS_UPCOMING), self::STATUS_UPCOMING),
            new InlineButton($this->status_name(self::STATUS_ARCHIVE), self::STATUS_ARCHIVE),
            new InlineButton('?', 'help')
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

    function str_contains($haystack, $needles)
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
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
        $parse_mode = 'html'
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
            true
        );
    }

    function message_and_beep(
        $state,
        $text,
        AbstractPayload $reply_markup = null,
        $reply_to_message_id = null,
        $disable_web_page_preview = false,
        $parse_mode = 'html'
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
            false
        );
    }
}