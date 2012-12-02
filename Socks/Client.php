<?php

namespace Socks;

use React\Promise\When;
use React\Promise\Deferred;
use React\HttpClient\Client as HttpClient;
use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\EventLoop\LoopInterface;
use React\HttpClient\ConnectionManagerInterface;
use \Exception;
use \InvalidArgumentException;

class Client implements ConnectionManagerInterface
{
    /**
     *
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     *
     * @var Resolver
     */
    private $resolver;

    private $socksHost;

    private $socksPort;

    private $timeout;

    /**
     * @var LoopInterface
     */
    protected $loop;

    private $resolveLocal = true;

    private $protocolVersion = '4a';

    public function __construct(LoopInterface $loop, ConnectionManagerInterface $connectionManager, Resolver $resolver, $socksHost, $socksPort)
    {
        $this->loop = $loop;
        $this->connectionManager = $connectionManager;
        $this->socksHost = $socksHost;
        $this->socksPort = $socksPort;
        $this->resolver = $resolver;
        $this->timeout = ini_get("default_socket_timeout");
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    public function setResolveLocal($resolveLocal)
    {
        $this->resolveLocal = $resolveLocal;
    }

    public function setProtocolVersion($version)
    {
        $version = (string)$version;
        if (!in_array($version, array('4','4a'), true)) {
            throw new InvalidArgumentException('Invalid protocol version given');
        }
        $this->protocolVersion = $version;
    }

    public function createHttpClient()
    {
        return new HttpClient($this->loop, $this, new SecureConnectionManager($this, $this->loop));
    }

    public function getConnection($host, $port)
    {
        $deferred = new Deferred();

        $timeout = microtime(true) + $this->timeout;
        $timerTimeout = $this->loop->addTimer($this->timeout, function () use ($deferred) {
            $deferred->reject(new Exception('Timeout while connecting to socks server'));
            // TODO: stop initiating connection and DNS query
        });

        $loop = $this->loop;
        $that = $this;
        When::all(
            array(
                $this->connectionManager->getConnection($this->socksHost, $this->socksPort)->then(
                    null,
                    function ($error) {
                        return new Exception('Unable to connect to socks server', 0, $error);
                    }
                ),
                $this->resolve($host)->then(
                    null,
                    function ($error) {
                        return new Exception('Unable to resolve remote hostname', 0, $error);
                    }
                )
            ),
            function ($fulfilled) use ($deferred, $port, $timeout, $that, $loop, $timerTimeout) {
                $loop->cancelTimer($timerTimeout);
                $deferred->resolve($that->handleConnectedSocks($fulfilled[0], $fulfilled[1], $port, $timeout));
            },
            function ($error) use ($deferred, $loop, $timerTimeout) {
                $loop->cancelTimer($timerTimeout);
                $deferred->reject(new Exception('Unable to connect to socks server', 0, $error));
            }
        );
        return $deferred->promise();
    }

    private function resolve($host)
    {
        // return if it's already an IP or we want to resolve remotely (socks 4 only supports resolving locally)
        if (false !== filter_var($host, FILTER_VALIDATE_IP) || ($this->protocolVersion !== '4' && !$this->resolveLocal)) {
            return When::resolve($host);
        }

        return $this->resolver->resolve($host);
    }

    public function handleConnectedSocks(Stream $stream, $host, $port, $timeout)
    {
        $deferred = new Deferred();
        $resolver = $deferred->resolver();

        $timerTimeout = $this->loop->addTimer(max($timeout - microtime(true), 0.1), function () use ($resolver) {
            $resolver->reject(new Exception('Timeout while establishing socks session'));
        });

        $ip = ip2long($host);                                                   // do not resolve hostname. only try to convert to IP
        $data = pack('C2nNC', 0x04, 0x01, $port, $ip === false ? 1 : $ip, 0x00); // send IP or (0.0.0.1) if invalid
        if ($ip === false) {                                                   // host is not a valid IP => send along hostname
            $data .= $host.pack('C',0x00);
        }
        $stream->write($data);

        $loop = $this->loop;
        $deferred->then(
            function (Stream $stream) use ($timerTimeout, $loop) {
                $loop->cancelTimer($timerTimeout);
                $stream->removeAllListeners('end');
                return $stream;
            },
            function ($error) use ($stream, $timerTimeout, $loop) {
                $loop->cancelTimer($timerTimeout);
                $stream->close();
                return $error;
            }
        );

        $stream->on('end',function (Stream $stream) use ($resolver) {
            $resolver->reject(new Exception('Premature end while establishing socks session'));
        });

        $this->readBinary($stream, array(
            'null'   => 'C',
            'status' => 'C',
            'port'   => 'n',
            'ip'     => 'N'
        ))->then(function ($data) use ($stream, $resolver) {
            if ($data['null'] !== 0x00 || $data['status'] !== 0x5a) {
                $resolver->reject(new Exception('Invalid SOCKS response'));
            }

            $resolver->resolve($stream);
        });

        return $deferred->promise();
    }

    protected function readBinary(Stream $stream, $structure)
    {
        $deferred = new Deferred();

        $length = 0;
        $unpack = '';
        foreach ($structure as $name=>$format) {
            if ($length !== 0) {
                $unpack .= '/';
            }
            $unpack .= $format . $name;

            if ($format === 'C') {
                ++$length;
            } else if ($format === 'n') {
                $length += 2;
            } else if ($format === 'N') {
                $length += 4;
            } else {
                throw new Exception('Invalid format given');
            }
        }

        $this->readLength($stream, $length)->then(function ($response) use ($unpack, $deferred) {
            $data = unpack($unpack, $response);
            $deferred->resolve($data);
        });

        return $deferred->promise();
    }

    protected function readLength(Stream $stream, $bytes)
    {
        $deferred = new Deferred();
        $oldsize = $stream->bufferSize;
        $stream->bufferSize = $bytes;

        $buffer = '';

        $fn = function ($data, Stream $stream) use (&$buffer, &$bytes, $deferred, $oldsize, &$fn) {
            $bytes -= strlen($data);
            $buffer .= $data;

            if ($bytes === 0) {
                $stream->bufferSize = $oldsize;
                $stream->removeListener('data', $fn);

                $deferred->resolve($buffer);
            } else {
                $stream->bufferSize = $bytes;
            }
        };
        $stream->on('data', $fn);
        return $deferred->promise();
    }
}