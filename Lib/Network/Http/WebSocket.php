<?php
/**
 * WebSocket connection class.
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2012, Magenta Creations (http://mg-crea.com)
 * @package       WebSocket.Network.Http
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

App::uses('HttpSocket', 'Network/Http');
App::uses('Router', 'Routing');

/**
 * Cake network websocket connection class.
 *
 * Core base class for websocket communication.
 *
 * @package       Cake.Network.Http
 */
class WebSocket extends HttpSocket {

	protected $_handshake = null;

	protected $_transport = null;

	public $data;

	public function __construct($config = array()) {
		$defaults = array(
			'namespace' => false,
			'timeout' => 3,
			'persistent' => false
		);
		$config += $defaults;
		parent::__construct($config);
	}

/**
 * Connect the socket to the given host and port.
 *
 * @return boolean Success
 * @throws SocketException
 */
	public function connect() {

		// Scheme aliases to http
		if($this->config['scheme'] == 'wss') $this->config['scheme'] = 'https';
		elseif($this->config['scheme'] == 'ws') $this->config['scheme'] = 'http';

		// Support direct uri configuration
		if(!empty($this->config['host'])) $this->config['request']['uri']['host'] = $this->config['host'];
		if(!empty($this->config['scheme'])) $this->config['request']['uri']['scheme'] = $this->config['scheme'];
		if(!empty($this->config['port'])) $this->config['request']['uri']['port'] = $this->config['port'];

		if(!$this->connected) {
			parent::connect();
			if($this->connected && !$this->_handshake) {
				return $this->_handshake();
			} else {
				return false;
			}
		}

		return $this->connected;
	}

	public function disconnect() {
		$this->_handshake = null;
		$this->_transport = null;
		return parent::disconnect();
	}

	protected function _handshake() {

		// Initial handshake
		$this->_handshake = $this->post(array('path' => '/socket.io/1/?t=' . (int)microtime(true)*1000, 'scheme' => $this->config['scheme'], 'port' => $this->config['port']));
		if($this->_handshake->code != 200) {
			$this->disconnect();
			if(!empty($this->config['silent'])) return false;
			throw new ServiceUnavailableException('Service unavailable', 503);
		}
		// dd($this->_handshake->code);

		// Extract information from handshake HttpResponse
		list($id, $heartbeat, $timeout, $transports) = explode(':', $this->_handshake->body);

		// Create a strong 256bits random string
		$key = base64_encode(openssl_random_pseudo_bytes(18, $strong));

		$header = array(
			'Connection' => 'Upgrade',
			'Upgrade' => 'websocket',
			'Sec-WebSocket-Key' => $key,
			'Sec-WebSocket-Origin' => $this->config['scheme'] . '://' . SERVER_NAME,
			'Sec-WebSocket-Version' => 13
		);

		$this->connect();
		//$this->setTimeout(0, 1000);
		try {
			$this->_transport = $this->get(array('path' => '/socket.io/1/websocket/' . $id, 'scheme' => $this->config['scheme'] == 'https' ? 'wss' : 'ws', 'port' => $this->config['port']), array(), compact('header', 'timeout'));
		} catch(Exception $e) {
			$this->disconnect();
			if(!empty($this->config['silent'])) return false;
			throw new ServiceUnavailableException('Service unavailable', 503);
		}

		$receivedKey = $this->_transport->headers['Sec-WebSocket-Accept'];
		$expectedKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

		if($receivedKey != $expectedKey) {
			$this->disconnect();
			if(!empty($this->config['silent'])) return false;
			throw new ServiceUnavailableException('Service unavailable', 503);
		}

		$connect = $this->read(5);
		// Switch to correct namespace
		if($this->config['namespace']) {
			$this->cd($this->config['namespace']);
			$connect = $this->read(5 + strlen($this->config['namespace']));
		}
		return $connect['payload'] === '1::' . $this->config['namespace'];

	}

	/**
	 * Socket.io protocol
	 */

	/**
	 * Connects to a specific endpoint (namespace)
	 * Handles type 1
	 *
	 * @link https://github.com/LearnBoost/socket.io-spec
	 */
	public function cd($endpoint = null) {
		$message = sprintf('1::%s', $endpoint);
		return $this->write($message);
	}

	/**
	 * Heartbeat
	 * Handles type 2
	 *
	 * @link https://github.com/LearnBoost/socket.io-spec
	 */
	public function heartbeat() {
		return $this->write('2::');
	}

	/**
	 * Pushes a message
	 * Handles type 3 & 4
	 *
	 * @link https://github.com/LearnBoost/socket.io-spec
	 */
	public function push($payload = null, $id = null) {
		if(is_array($payload) || is_object($payload)) {
			$message = sprintf('4:%s:%s:%s', $id, $this->config['namespace'], json_encode($payload));
		} else {
			$message = sprintf('3:%s:%s:%s', $id, $this->config['namespace'], (string)$payload);
		}
		return $this->write($message);
	}

	/**
	 * Emits an event
	 * Handles type 5
	 *
	 * @link https://github.com/LearnBoost/socket.io-spec
	 */
	public function emit($event, $payload = array(), $id = null) {
		$message = sprintf('5:%s:%s:{"name":"%s","args":%s}', $id, $this->config['namespace'], $event, json_encode($payload));
		return $this->write($message);
	}

	/**
	 * Emits an acknowledgment
	 * Handles type 6
	 *
	 * @link https://github.com/LearnBoost/socket.io-spec
	 */
	public function ack($payload = array(), $id = null) {
		if(empty($payload)) $message = sprintf('6:::%s', $id);
		else $message = sprintf('6:::%s+%s', $id, json_encode($payload));
		return $this->write($message);
	}

	/**
	 * Emits an error
	 * Handles type 7
	 *
	 * @link https://github.com/LearnBoost/socket.io-spec
	 */
	public function error($reason = null, $advice = null) {
		$message = sprintf('7::%s:%s+%s', $this->config['namespace'], $reason, $advice);
		return $this->write($message);
	}

	/**
	 * Socket.io handling
	 */

	/**
	 * Read data from the socket. Returns false if no data is available or no connection could be
	 * established.
	 *
	 * @param integer $length Optional buffer length to read; defaults to 1024
	 * @return mixed Socket data
	 */
	public function read($length = 1024) {
		/*if(!$this->_handshake) return parent::read($length);
		if(!$this->_transport) {
			$read = array($this->connection);
			$write  = null;
			$except = null;
			if(stream_select($read, $write, $except, $this->config['timeout'])) {
				dd(parent::read($length));
			}
			dd($select);
			//return fread($this->connection, $length);
		}*/
		$data = parent::read($length);
		if($data && $this->_transport) {
			$data = $this->decode($data);
		}
		return $data;
	}

	/**
	 * Write data to the socket.
	 *
	 * @param string $data The data to write to the socket
	 * @return boolean Success
	 */
	public function write($data) {
		if($data && $this->_transport) {
			$data = $this->encode($data);
		}
		return parent::write($data);
	}


/**
 * Issue the specified request. HttpSocket::get() and HttpSocket::post() wrap this
 * method and provide a more granular interface.
 *
 * @param mixed $request Either an URI string, or an array defining host/uri
 * @return mixed false on error, HttpResponse on success
 * @throws SocketException
 */
	public function request($request = array()) {
		$this->reset(false);

		if (is_string($request)) {
			$request = array('uri' => $request);
		} elseif (!is_array($request)) {
			return false;
		}

		if (!isset($request['uri'])) {
			$request['uri'] = null;
		}
		$uri = $this->_parseUri($request['uri']);

		if (!isset($uri['host'])) {
			$host = $this->config['host'];
		}
		if (isset($request['host'])) {
			$host = $request['host'];
			unset($request['host']);
		}
		$request['uri'] = $this->url($request['uri']);
		$request['uri'] = $this->_parseUri($request['uri'], true);
		$this->request = Set::merge($this->request, array_diff_key($this->config['request'], array('cookies' => true)), $request);

		$this->_configUri($this->request['uri']);

		$Host = $this->request['uri']['host'];
		if (!empty($this->config['request']['cookies'][$Host])) {
			if (!isset($this->request['cookies'])) {
				$this->request['cookies'] = array();
			}
			if (!isset($request['cookies'])) {
				$request['cookies'] = array();
			}
			$this->request['cookies'] = array_merge($this->request['cookies'], $this->config['request']['cookies'][$Host], $request['cookies']);
		}

		if (isset($host)) {
			$this->config['host'] = $host;
		}
		$this->_setProxy();
		$this->request['proxy'] = $this->_proxy;

		$cookies = null;

		if (is_array($this->request['header'])) {
			if (!empty($this->request['cookies'])) {
				$cookies = $this->buildCookies($this->request['cookies']);
			}
			$scheme = '';
			$port = 0;
			if (isset($this->request['uri']['scheme'])) {
				$scheme = $this->request['uri']['scheme'];
			}
			if (isset($this->request['uri']['port'])) {
				$port = $this->request['uri']['port'];
			}
			if (
				($scheme === 'http' && $port != 80) ||
				($scheme === 'https' && $port != 443) ||
				($port != 80 && $port != 443)
			) {
				$Host .= ':' . $port;
			}
			$this->request['header'] = array_merge(compact('Host'), $this->request['header']);
		}

		if (isset($this->request['uri']['user'], $this->request['uri']['pass'])) {
			$this->configAuth('Basic', $this->request['uri']['user'], $this->request['uri']['pass']);
		}
		$this->_setAuth();
		$this->request['auth'] = $this->_auth;

		if (is_array($this->request['body'])) {
			$this->request['body'] = http_build_query($this->request['body']);
		}

		if (!empty($this->request['body']) && !isset($this->request['header']['Content-Type'])) {
			$this->request['header']['Content-Type'] = 'application/x-www-form-urlencoded';
		}

		if (!empty($this->request['body']) && !isset($this->request['header']['Content-Length'])) {
			$this->request['header']['Content-Length'] = strlen($this->request['body']);
		}

		$connectionType = null;
		if (isset($this->request['header']['Connection'])) {
			$connectionType = $this->request['header']['Connection'];
		}
		$this->request['header'] = $this->_buildHeader($this->request['header']) . $cookies;

		if (empty($this->request['line'])) {
			$this->request['line'] = $this->_buildRequestLine($this->request);
		}

		if ($this->quirksMode === false && $this->request['line'] === false) {
			return false;
		}

		$this->request['raw'] = '';
		if ($this->request['line'] !== false) {
			$this->request['raw'] = $this->request['line'];
		}

		if ($this->request['header'] !== false) {
			$this->request['raw'] .= $this->request['header'];
		}

		$this->request['raw'] .= "\r\n";
		$this->request['raw'] .= $this->request['body'];
		$this->write($this->request['raw']);

		$response = null;
		$inHeader = true;
		$response = $this->read();
		//notice(array('request' => $this->request['raw'], 'response' => $response));
		if(!$response) throw new BadRequestException();

		if ($connectionType === 'close') {
			$this->disconnect();
		}

		list($plugin, $responseClass) = pluginSplit($this->responseClass, true);
		App::uses($responseClass, $plugin . 'Network/Http');
		if (!class_exists($responseClass)) {
			throw new SocketException(__d('cake_dev', 'Class %s not found.', $this->responseClass));
		}
		$this->response = new $responseClass($response);
		if (!empty($this->response->cookies)) {
			if (!isset($this->config['request']['cookies'][$Host])) {
				$this->config['request']['cookies'][$Host] = array();
			}
			$this->config['request']['cookies'][$Host] = array_merge($this->config['request']['cookies'][$Host], $this->response->cookies);
		}

		if ($this->request['redirect'] && $this->response->isRedirect()) {
			$request['uri'] = $this->response->getHeader('Location');
			$request['redirect'] = is_int($this->request['redirect']) ? $this->request['redirect'] - 1 : $this->request['redirect'];
			$this->response = $this->request($request);
		}

		return $this->response;
	}

	public function setBlocking($mode = 0) {
		return stream_set_blocking($this->connection, (int)$mode);
	}

	public function setTimeout($seconds = 0, $microseconds = 0) {
		return stream_set_timeout($this->connection, (int)$seconds, (int)$microseconds);
	}

	// Encoding / Decoding functions from https://github.com/lemmingzshadow/php-websocket

	public function encode($payload, $type = 'text', $masked = true) {
		$frameHead = array();
		$frame = '';
		$payloadLength = strlen($payload);

		switch($type)
		{
			case 'ping':
				// first byte indicates FIN, Ping frame (10001001):
				$frameHead[0] = 137;
			break;

			case 'pong':
				// first byte indicates FIN, Pong frame (10001010):
				$frameHead[0] = 138;
			break;

			case 'text':
				// first byte indicates FIN, Text-Frame (10000001):
				$frameHead[0] = 129;
			break;

			case 'close':
			break;
		}

		// set mask and payload length (using 1, 3 or 9 bytes)
		if($payloadLength > 65535)
		{
			$payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
			$frameHead[1] = ($masked === true) ? 255 : 127;
			for($i = 0; $i < 8; $i++)
			{
				$frameHead[$i+2] = bindec($payloadLengthBin[$i]);
			}
			// most significant bit MUST be 0 (return false if to much data)
			if($frameHead[2] > 127)
			{
				return false;
			}
		}
		elseif($payloadLength > 125)
		{
			$payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
			$frameHead[1] = ($masked === true) ? 254 : 126;
			$frameHead[2] = bindec($payloadLengthBin[0]);
			$frameHead[3] = bindec($payloadLengthBin[1]);
		}
		else
		{
			$frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
		}

		// convert frame-head to string:
		foreach(array_keys($frameHead) as $i)
		{
			$frameHead[$i] = chr($frameHead[$i]);
		}
		if($masked === true)
		{
			// generate a random mask:
			$mask = array();
			for($i = 0; $i < 4; $i++)
			{
				$mask[$i] = chr(rand(0, 255));
			}

			$frameHead = array_merge($frameHead, $mask);
		}
		$frame = implode('', $frameHead);

		// append payload to frame:
		$framePayload = array();
		for($i = 0; $i < $payloadLength; $i++)
		{
			$frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
		}

		return $frame;
	}

	public function decode($data) {

		$payloadLength = '';
		$mask = '';
		$unmaskedPayload = '';
		$decodedData = array();

		// estimate frame type:
		$firstByteBinary = sprintf('%08b', ord($data[0]));
		$secondByteBinary = sprintf('%08b', ord($data[1]));
		$opcode = bindec(substr($firstByteBinary, 4, 4));
		$isMasked = ($secondByteBinary[0] == '1') ? true : false;
		$payloadLength = ord($data[1]) & 127;

		// @TODO: close connection if unmasked frame is received.

		switch($opcode)
		{
			// text frame:
			case 1:
				$decodedData['type'] = 'text';
			break;

			// connection close frame:
			case 8:
				$decodedData['type'] = 'close';
			break;

			// ping frame:
			case 9:
				$decodedData['type'] = 'ping';
			break;

			// pong frame:
			case 10:
				$decodedData['type'] = 'pong';
			break;

			default:
				// @TODO: Close connection on unknown opcode.
			break;
		}

		if($payloadLength === 126)
		{
		   $mask = substr($data, 4, 4);
		   $payloadOffset = 8;
		}
		elseif($payloadLength === 127)
		{
			$mask = substr($data, 10, 4);
			$payloadOffset = 14;
		}
		else
		{
			$mask = substr($data, 2, 4);
			$payloadOffset = 6;
		}

		$dataLength = strlen($data);

		if($isMasked === true)
		{
			for($i = $payloadOffset; $i < $dataLength; $i++)
			{
				$j = $i - $payloadOffset;
				$unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
			}
			$decodedData['payload'] = $unmaskedPayload;
		}
		else
		{
			$payloadOffset = $payloadOffset - 4;
			$decodedData['payload'] = substr($data, $payloadOffset);
		}

		return $decodedData;
	}

}
