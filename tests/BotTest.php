<?php

namespace App;

use korchasa\Telegram\Telegram;
use korchasa\Telegram\Structs\Update;
use korchasa\Telegram\Structs\Chat;
use korchasa\Telegram\Unstructured;
use Prophecy\Argument;

class BotTest extends \PHPUnit_Framework_TestCase
{
    private $prophet;
    private $telegram;
    private $megaplan;

    /**
     * @dataProvider providerSelectAction
     */
    function testSelectAction($state, $update, $expected_action)
    {
        $megaplan = $this->prophet->prophesize(Megaplan::class);
        $telegram = $this->prophet->prophesize(Telegram::class);
        $bot = new Bot($megaplan->reveal(), $telegram->reveal());
        $action = $bot->select_action($state, $update);
        $this->assertEquals($expected_action, $action);
        $this->assertTrue(
            method_exists($bot, 'action_'.$action),
            "Method \"$action\" not found in App\\Bot"
        );
    }

    function providerSelectAction()
    {
        return [
            'not authorized' => [
                new State,
                $this->update_text('any text'),
                'auth'
            ],
            'just text1' => [
                $this->valid_state(),
                $this->update_text('any text'),
                'info'
            ],
            'just text2' => [
                $this->valid_state(),
                $this->update_text('Список задач'),
                'inbox'
            ],
            'just text3' => [
                $this->valid_state(),
                $this->update_text('задАчи'),
                'inbox'
            ],
            'add task by plus' => [
                $this->valid_state(),
                $this->update_text('+any text'),
                'new_task'
            ],
            'add task by plus word' => [
                $this->valid_state(),
                $this->update_text('Плюс что-то'),
                'new_task'
            ],
        ];
    }

    public function estProcessUpdate()
    {
        $update = $this->update('{
            "update_id": 877959854,
            "message": {
                "chat": {
                    "id": 1115100,
                    "username": "korchasa"
                },
                "text": "фывфыв"
            }
        }');
        $megaplan = $this->prophet->prophesize(Megaplan::class);
        $telegram = $this->prophet->prophesize(Telegram::class);
        $telegram->sendMessage(Argument::type(Chat::class), Argument::type('string'))->willReturn(1);
        $bot = new Bot($megaplan->reveal(), $telegram->reveal());

        $bot->processUpdate($update);
    }

    protected function update_text($text)
    {
        return $this->update('{
            "update_id": 877959854,
            "message": {
                "chat": {
                    "id": 1115100,
                    "username": "korchasa"
                },
                "text": "'.$text.'"
            }
        }');
    }

    protected function update($json)
    {
        $data = json_decode($json);
        if (null === $data) {
            throw new Exception('Json decode error: '.json_last_error_msg());
        }
        $update = new Update(new Unstructured($data));
        $update->telegram = $this->telegram;
        return $update;
    }

    protected function valid_state()
    {
        $state = new State;
        $state->chat_id = 1115100;
        $state->host = "korchasa.megaplan.ru";
        $state->access_id = "Ef247061a56dcc19c14d";
        $state->secret_key = "3773e0af2bDd2269eF157660109Ba19f1432957b";
        return $state;
    }

    protected function setup()
    {
        $this->prophet = new \Prophecy\Prophet;
        $this->megaplan = $this->prophet->prophesize(Megaplan::class)->reveal();
        $this->telegram = $this->prophet->prophesize(Telegram::class)->reveal();
    }

    protected function tearDown()
    {
        $this->prophet->checkPredictions();
    }
}