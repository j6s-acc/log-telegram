<?php
/**
 * This file is part of the j6s-acc/log-telegram library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) 2021 Jigius <jigius@gmail.com>
 * @link https://github.com/j6s-acc/log-telegram GitHub
 */

declare(strict_types=1);

namespace J6sAcc\App\Log;

use Acc\Core\Log;
use Acc\Core\SerializableInterface;
use RuntimeException;
use LogicException;
use DomainException;

/**
 * Class TelegramLog
 *
 * @package J6sAcc\App\Log
 */
final class TelegramLog implements TelegramLogInterface, SerializableInterface, Log\LogEmbeddableInterface
{
    /**
     * @var array
     */
    private array $i;
    /**
     * @var Log\LogLevelInterface
     */
    private Log\LogLevelInterface $minLevel;
    /**
     * @var Log\LogInterface
     */
    private Log\LogInterface $original;

    /**
     * TelegramLog constructor.
     *
     * @param Log\LogInterface $log
     */
    public function __construct(Log\LogInterface $log)
    {
        $this->i = [];
        $this->original = $log;
        $this->minLevel = new Log\LogLevel(Log\LogLevelInterface::INFO);
    }

    /**
     * @inheritdoc
     */
    public function withChatId(int $id): TelegramLog
    {
        $obj = $this->blueprinted();
        $obj->i['chatId'] = $id;
        return $obj;
    }

    /**
     * @inheritdoc
     */
    public function withRequestUri(string $uri): TelegramLog
    {
        $obj = $this->blueprinted();
        $obj->i['requestUri'] = $uri;
        return $obj;
    }

    /**
     * @inheritDoc
     */
    public function withEntry(Log\LogEntryInterface $entity): self
    {
        $obj = $this->blueprinted();
        if ($entity->level()->lt($this->minLevel)) {
            $obj->original = $this->original->withEntry($entity);
            return $obj;
        }
        $obj->original = $this->original->withEntry($entity);
        if (!isset($this->i['chatId']) || !isset($this->i['requestUri'])) {
            throw new LogicException("Not initialized in a proper way");
        }
        if (($ch = curl_init($this->i['uri'])) === false) {
            throw new RuntimeException("Couldn't initialize a connect to a CURL-handler");
        }
        $opts = [
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $this->i['chatId'],
                'text' => (function () use ($entity): string {
                    $i = $entity->serialized();
                    if (!isset($i['dt']) || !isset($i['level']) || !isset($i['text'])) {
                        throw new DomainException("invalid data");
                    }
                    return
                        sprintf(
                            "%s %s [%u] %s\n", $i['dt'],
                            str_pad(
                                $entity->level()->toString(),
                                7,
                                " ",
                                STR_PAD_LEFT
                            ),
                            getmypid(),
                            $i['text']
                        );
                }) ()
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ];
        foreach ($opts as $p => $v) {
            if (curl_setopt($ch, $p, $v) === false) {
                throw
                    new RuntimeException(
                        "Couldn't set an option for CURL-handler",
                        0,
                        new RuntimeException(curl_error($ch), curl_errno($ch))
                    );
            }
        }
        if (($result = curl_exec($ch)) === false) {
            throw
                new RuntimeException(
                    "Couldn't do an exec-request on CURL-handler",
                    0,
                    new RuntimeException(curl_error($ch), curl_errno($ch))
                );
        }
        curl_close($ch);
        return $obj;
    }

    /**
     * @inheritDoc
     */
    public function serialized(): array
    {
        return [
            'i' => $this->i,
            'minLevel' => $this->minLevel->toInt(),
            'original' => [
                'classname' => get_class($this->original),
                'state' => $this->original->serialized()
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function unserialized(iterable $data): self
    {
        if (
            !isset($data['minLevel']) || !is_int($data['minLevel']) ||
            !isset($data['i']) || !is_array($data['i']) ||
            !isset($data['original']['classname']) || !is_string($data['original']['classname']) ||
            !class_exists($data['original']['classname']) ||
            !isset($data['original']['state']) || !is_array($data['original']['state'])
        ) {
            throw new LogicException("type invalid");
        }
        $log = new $data['original']['classname']();
        if (!($log instanceof Log\LogInterface)) {
            throw new LogicException("type invalid");
        }
        $obj = $this->blueprinted();
        $obj->original = $log->unserialized($data['original']['state']);
        $obj->minLevel = new Log\LogLevel($data['minLevel']);
        $obj->i = $data['i'];
        return $obj;
    }

    /**
     * Clones the instance
     * @return $this
     */
    private function blueprinted(): self
    {
        $obj = $this->created();
        $obj->i = $this->i;
        $obj->minLevel = $this->minLevel;
        return $obj;
    }

    /**
     * @inheritDoc
     */
    public function withMinLevel(Log\LogLevel $level): self
    {
        $obj = $this->blueprinted();
        $obj->minLevel = $level;
        return $obj;
    }

    /**
     * @inheritDoc
     */
    public function withEmbedded(Log\LogInterface $log): self
    {
        if ($this->original instanceof Log\LogEmbeddableInterface) {
            $obj = $this->original->withEmbedded($log);
        } else {
            $obj = $this->blueprinted();
            $obj->original = $log;
        }
        return $obj;
    }

    /**
     * @inheritdoc
     */
    public function created(): self
    {
        return new self($this->original);
    }
}
