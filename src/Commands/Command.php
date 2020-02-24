<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Klevialent Man <klevialent@gmail.com>
 */

namespace Longman\TelegramBot\Commands;

use Longman\TelegramBot\DB;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ChosenInlineResult;
use Longman\TelegramBot\Entities\InlineQuery;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\Payments\PreCheckoutQuery;
use Longman\TelegramBot\Entities\Payments\ShippingQuery;
use Longman\TelegramBot\Entities\Poll;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Base class for commands. It includes some helper methods that can fetch data directly from the Update object.
 *
 * @method Message             getMessage()            Optional. New incoming message of any kind — text, photo, sticker, etc.
 * @method Message             getEditedMessage()      Optional. New version of a message that is known to the bot and was edited
 * @method Message             getChannelPost()        Optional. New post in the channel, can be any kind — text, photo, sticker, etc.
 * @method Message             getEditedChannelPost()  Optional. New version of a post in the channel that is known to the bot and was edited
 * @method InlineQuery         getInlineQuery()        Optional. New incoming inline query
 * @method ChosenInlineResult  getChosenInlineResult() Optional. The result of an inline query that was chosen by a user and sent to their chat partner.
 * @method CallbackQuery       getCallbackQuery()      Optional. New incoming callback query
 * @method ShippingQuery       getShippingQuery()      Optional. New incoming shipping query. Only for invoices with flexible price
 * @method PreCheckoutQuery    getPreCheckoutQuery()   Optional. New incoming pre-checkout query. Contains full information about checkout
 * @method Poll                getPoll()               Optional. New poll state. Bots receive only updates about polls, which are sent or stopped by the bot
 */
abstract class Command
{
    protected Telegram $telegram;
    protected Update $update;
    protected string $name;
    protected string $description;
    protected string $usage;
    protected bool $showInHelp;
    protected string $version;
    protected bool $enabled = true;
    protected bool $need_mysql = false;     //If this command needs mysql
    protected bool $private_only = false;   //Make sure this command only executes on a private chat.
    protected array $config = [];

    public function __construct(Telegram $telegram, ?Update $update = null)
    {
        $this->telegram = $telegram;
        $this->setUpdate($update);
        $this->config = $telegram->getCommandConfig($this->name);
    }

    public function setUpdate(Update $update = null): self
    {
        if ($update !== null) {
            $this->update = $update;
        }

        return $this;
    }

    /**
     * @throws TelegramException
     */
    public function preExecute(): ServerResponse
    {
        if ($this->need_mysql && !($this->telegram->isDbEnabled() && DB::isDbConnected())) {
            return $this->executeNoDb();
        }

        if ($this->isPrivateOnly() && $this->removeNonPrivateMessage()) {
            $message = $this->getMessage();

            if ($user = $message->getFrom()) {
                return Request::sendMessage([
                    'chat_id'    => $user->getId(),
                    'parse_mode' => 'Markdown',
                    'text'       => sprintf(
                        "/%s command is only available in a private chat.\n(`%s`)",
                        $this->getName(),
                        $message->getText()
                    ),
                ]);
            }

            return Request::emptyResponse();
        }

        return $this->execute();
    }

    /**
     * @throws TelegramException
     */
    abstract public function execute(): ServerResponse;

    /**
     * Execution if MySQL is required but not available
     *
     * @throws TelegramException
     */
    public function executeNoDb(): ServerResponse
    {
        return $this->replyToChat('Sorry no database connection, unable to execute "' . $this->name . '" command.');
    }

    public function getUpdate(): Update
    {
        return $this->update;
    }

    /**
     * Relay any non-existing function calls to Update object.
     * This is purely a helper method to make requests from within execute() method easier.
     */
    public function __call(string $name, array $arguments): Command
    {
        if ($this->update === null) {
            return null;
        }
        return call_user_func_array([$this->update, $name], $arguments);
    }

    /**
     * Look for config $name if found return it, if not return null.
     * If $name is not set return all set config.
     *
     * @return array|mixed|null
     */
    public function getConfig(?string $name = null)
    {
        if ($name === null) {
            return $this->config;
        }
        if (isset($this->config[$name])) {
            return $this->config[$name];
        }

        return null;
    }

    public function getTelegram(): Telegram
    {
        return $this->telegram;
    }

    public function getUsage(): string
    {
        return $this->usage;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function showInHelp(): bool
    {
        return $this->showInHelp;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * If this command is intended for private chats only.
     */
    public function isPrivateOnly(): bool
    {
        return $this->private_only;
    }

    public function isSystemCommand(): bool
    {
        return ($this instanceof SystemCommand);
    }

    public function isAdminCommand(): bool
    {
        return ($this instanceof AdminCommand);
    }

    public function isUserCommand(): bool
    {
        return ($this instanceof UserCommand);
    }

    /**
     * Delete the current message if it has been called in a non-private chat.
     */
    protected function removeNonPrivateMessage(): bool
    {
        $message = $this->getMessage() ?: $this->getEditedMessage();

        if ($message) {
            $chat = $message->getChat();

            if (!$chat->isPrivateChat()) {
                // Delete the falsely called command message.
                Request::deleteMessage([
                    'chat_id'    => $chat->getId(),
                    'message_id' => $message->getMessageId(),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Helper to reply to a chat directly.
     *
     * @throws TelegramException
     */
    public function replyToChat(string $text, array $data = []): ServerResponse
    {
        if ($message = $this->getMessage() ?: $this->getEditedMessage() ?: $this->getChannelPost() ?: $this->getEditedChannelPost()) {
            return Request::sendMessage(array_merge([
                'chat_id' => $message->getChat()->getId(),
                'text'    => $text,
            ], $data));
        }

        return Request::emptyResponse();
    }

    /**
     * Helper to reply to a user directly.
     *
     * @throws TelegramException
     */
    public function replyToUser(string $text, array $data = []): ServerResponse
    {
        if ($message = $this->getMessage() ?: $this->getEditedMessage()) {
            return Request::sendMessage(array_merge([
                'chat_id' => $message->getFrom()->getId(),
                'text'    => $text,
            ], $data));
        }

        return Request::emptyResponse();
    }
}
