<?php

namespace pader\swbeanstalk;

use Swoole\Coroutine\Client;

/**
 * Swbeanstalk
 *
 * A beanstalkd client base on swoole coroutine.
 *
 * @package pader\swbeanstalk
 */
class Swbeanstalk
{
    const DEFAULT_PRI = 60;
    const DEFAULT_TTR = 30;

    protected $config;
    protected $connection;
    protected $lastError = null;

    public $debug = false;

    protected $using = 'default';

    protected $watching = ['default' => true];

    /**
     * Swbeanstalk constructor.
     *
     * @param string $host
     * @param int $port
     * @param int $connectTimeout Connect timeout, -1 means never timeout.
     * @param int $timeout Read, write timeout, -1 means never timeout.
     */
    public function __construct($host = '127.0.0.1', $port = 11300, $connectTimeout = 1, $timeout = -1)
    {
        $this->config = compact('host', 'port');

        $this->connection = new Client(SWOOLE_SOCK_TCP);
        $this->connection->set([
            'socket_connect_timeout' => $connectTimeout,
            'socket_timeout' => $timeout
        ]);
    }

    public function connect()
    {
        if ($this->isConnected()) {
            $this->connection->close(true);
        }
        return $this->connection->connect($this->config['host'], $this->config['port']);
    }

    public function isConnected()
    {
        return $this->connection && $this->connection->isConnected();
    }

    public function put($data, int $pri = self::DEFAULT_PRI, int $delay = 0, int $ttr = self::DEFAULT_TTR)
    {
        $this->send(sprintf("put %d %d %d %d\r\n%s", $pri, $delay, $ttr, strlen($data), $data));
        $res = $this->recv();

        if ($res['status'] === 'INSERTED') {
            return $res['meta'][0];
        }

        $this->setError($res['status']);
        return false;
    }

    public function useTube(string $tube): bool
    {
        // we should not have to do anything here
        if ($tube === $this->using) {
            return true;
        }

        $this->send(sprintf("use %s", $tube));
        $ret = $this->recv();
        if ($ret['status'] === 'USING' && $ret['meta'][0] === $tube) {

            $this->using = $tube;
            return true;
        }

        $this->setError($ret['status'], "Use tube $tube failed.");
        return false;
    }

    public function reserve(?int $timeout = null)
    {
        if (isset($timeout)) {
            $this->send(sprintf('reserve-with-timeout %d', $timeout));
        } else {
            $this->send('reserve');
        }

        $res = $this->recv();

        if ($res['status'] === 'RESERVED') {
            [$id, $bytes] = $res['meta'];
            return [
                'id' => $id,
                'body' => substr($res['body'], 0, $bytes)
            ];
        }

        $this->setError($res['status']);
        return false;
    }

    public function delete(int $id): bool
    {
        return $this->sendv(sprintf('delete %d', $id), 'DELETED');
    }

    public function release(int $id, int $pri = self::DEFAULT_PRI, $delay = 0): bool
    {
        return $this->sendv(sprintf('release %d %d %d', $id, $pri, $delay), 'RELEASED');
    }

    public function bury(int $id, int $pri = self::DEFAULT_PRI): bool
    {
        return $this->sendv(sprintf('bury %d %d', $id, $pri), 'BURIED');
    }

    public function touch(int $id): bool
    {
        return $this->sendv(sprintf('touch %d', $id), 'TOUCHED');
    }

    public function watch(string $tube)
    {
        if (isset($this->watching[$tube])) {
            return true;
        }

        $this->send(sprintf('watch %s', $tube));
        $res = $this->recv();

        if ($res['status'] === 'WATCHING') {
            $this->watching[$tube] = true;
            return $res['meta'][0];
        }

        $this->setError($res['status']);
        return false;
    }

    public function ignore(string $tube): bool
    {
        if (isset($this->watching[$tube])) {
            unset($this->watching[$tube]);
            return $this->sendv(sprintf('ignore %s', $tube), 'WATCHING');
        }

        return false;
    }

    public function peek(int $id)
    {
        $this->send(sprintf('peek %d', $id));
        return $this->peekRead();
    }

    public function peekReady()
    {
        $this->send('peek-ready');
        return $this->peekRead();
    }

    public function peekDelayed()
    {
        $this->send('peek-delayed');
        return $this->peekRead();
    }

    public function peekBuried()
    {
        $this->send('peek-buried');
        return $this->peekRead();
    }

    protected function peekRead()
    {
        $res = $this->recv();

        if ($res['status'] === 'FOUND') {
            list($id, $bytes) = $res['meta'];
            return [
                'id' => $id,
                'body' => substr($res['body'], 0, $bytes)
            ];
        }

        $this->setError($res['status']);
        return false;
    }

    public function kick(int $bound)
    {
        $this->send(sprintf('kick %d', $bound));
        $res = $this->recv();

        if ($res['status'] === 'KICKED') {
            return $res['meta'][0];
        }

        $this->setError($res['status']);
        return false;
    }

    public function kickJob(int $id): bool
    {
        return $this->sendv(sprintf('kick-job %d', $id), 'KICKED');
    }

    public function statsJob(int $id)
    {
        $this->send(sprintf('stats-job %d', $id));
        return $this->statsRead();
    }

    public function statsTube(string $tube)
    {
        $this->send(sprintf('stats-tube %s', $tube));
        return $this->statsRead();
    }

    public function stats()
    {
        $this->send('stats');
        return $this->statsRead();
    }

    public function listTubes()
    {
        $this->send('list-tubes');
        return $this->statsRead();
    }

    public function listTubeUsed(bool $askServer = false)
    {
        if ($askServer) {
            $this->send('list-tube-used');
            $res = $this->recv();
            if ($res['status'] === 'USING') {
                $this->using = $res['meta'][0];
            } else {
                $this->setError($res['status']);
                return false;
            }
        }

        return $this->using;
    }

    /**
     * @param string $tube The tube to use during execution
     * @param \Closure $closure Closure to execute while using the specified tube
     * @return mixed the return value of the closure.
     * @internal This is marked as internal since it is not part of a stabilized interface.
     */
    public function withUsedTube(string $tube, \Closure $closure)
    {
        $used = $this->listTubeUsed();
        try {
            $this->useTube($tube);
            return $closure($this);
        } finally {
            $this->useTube($used);
        }
    }

    public function listTubesWatched(bool $askServer = false): array
    {
        if ($askServer) {
            $this->send('list-tubes-watched');
            $result = $this->statsRead();

            $this->watching = array_fill_keys($result, true);
        }

        return array_keys($this->watching);
    }

    /**
     * {@inheritdoc}
     */
    public function watchOnly(string $tube)
    {
        $this->watch($tube);

        $ignoreTubes = array_diff_key($this->watching, [$tube => true]);
        foreach ($ignoreTubes as $ignoreTube => $true) {
            $this->ignore($ignoreTube);
        }

        return $this;
    }

    /**
     * @param string $tube The tube to watch during execution
     * @param \Closure $closure Closure to execute while using the specified tube
     * @return mixed the return value of the closure.
     * @internal This is marked as internal since it is not part of a stabilized interface.
     */
    public function withWatchedTube(string $tube, \Closure $closure)
    {
        $watched = $this->listTubesWatched();
        try {
            $this->watchOnly($tube);
            return $closure($this);
        } finally {
            foreach ($watched as $watchedTupe) {
                $this->watch($watchedTupe);
            }

            if (!in_array($tube, $watched, true)) {
                $this->ignore($tube);
            }
        }
    }

    protected function statsRead()
    {
        $res = $this->recv();

        if ($res['status'] === 'OK') {
            list($bytes) = $res['meta'];
            $body = trim($res['body']);

            $data = array_slice(explode("\n", $body), 1);
            $result = [];

            foreach ($data as $row) {
                if ('-' === substr($row, 0, 1)) {
                    $value = substr($row, 2);
                    $key = null;
                } else {
                    $pos = strpos($row, ':');
                    $key = substr($row, 0, $pos);
                    $value = substr($row, $pos + 2);
                }
                if (is_numeric($value)) {
                    $value = (int)$value === $value ? (int)$value : (float)$value;
                }
                isset($key) ? $result[$key] = $value : array_push($result, $value);
            }
            return $result;
        }

        $this->setError($res['status']);
        return false;
    }

    public function pauseTube(string $tube, int $delay): bool
    {
        return $this->sendv(sprintf('pause-tube %s %d', $tube, $delay), 'PAUSED');
    }

    protected function sendv(string $cmd, string $status): bool
    {
        $this->send($cmd);
        $res = $this->recv();

        if ($res['status'] !== $status) {
            $this->setError($res['status']);
            return false;
        }

        return true;
    }

    protected function send(string $cmd)
    {
        if (!$this->isConnected()) {
            throw new \RuntimeException('No connecting found while writing data to socket.');
        }

        $cmd .= "\r\n";

        if ($this->debug) {
            $this->wrap($cmd, true);
        }

        return $this->connection->send($cmd);
    }

    protected function recv(): array
    {
        if (!$this->isConnected()) {
            throw new \RuntimeException('No connection found while reading data from socket.');
        }

        $recv = $this->connection->recv();
        $metaEnd = strpos($recv, "\r\n");
        $meta = explode(' ', substr($recv, 0, $metaEnd));
        $status = array_shift($meta);

        if ($this->debug) {
            $this->wrap($recv, false);
        }

        return [
            'status' => $status,
            'meta' => $meta,
            'body' => substr($recv, $metaEnd + 2)
        ];
    }

    public function disconnect()
    {
        if ($this->isConnected()) {
            $this->send('quit');
            $this->connection->close();
        }

        if ($this->connection) {
            $this->connection = null;
        }
    }

    protected function setError(string $status, string $msg = '')
    {
        $this->lastError = compact('status', 'msg');
    }

    public function getError()
    {
        if ($this->lastError) {
            $error = $this->lastError;
            $this->lastError = null;
            return $error;
        }
        return null;
    }

    protected function wrap(string $output, bool $out)
    {
        $line = $out ? '----->>' : '<<-----';
        echo "\r\n$line\r\n$output\r\n$line\r\n";
    }
}
