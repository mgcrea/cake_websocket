## CakePHP WebSocket Plugin
by `Olivier Louvignes`


### Description

This repository contains a [CakePHP 2.0](https://github.com/cakephp) plugin that provides:

* `WebSocket Object` (that extends HttpSocket core class) to act as websocket client

* `Publishable Behavior` that aims to provide easy broadcasting of changes occuring in your models


### Installation

1. Clone this plugin to `app/Plugin/WebSocket`

2. Load the plugin in your `app/config/bootstrap.php` file :

		define('SERVER_NAME', $_SERVER['SERVER_NAME']);
		CakePlugin::load('WebSocket');

3. To configure one of your model to use the `Publishable` Behavior.

		public $actsAs = array('Publishable' => array('fields' => array('name', 'status_date', 'status_code', 'status_progress')),

4. To configure one of your controller to use a socket.

		App::import('Plugin/WebSocket/Lib/Network/Http', 'WebSocket', array('file'=>'WebSocket.php'));
		$websocket = new WebSocket(array('port' => 8080, 'scheme'=>'ws'));

5. Setup a Node WebSocket server using [Socket.io](http://socket.io) like:

		var io = require('socket.io').listen(8080);

		io.sockets.on('connection', function (socket) {
		  socket.emit('news', { hello: 'world' });
		  socket.on('my other event', function (data) {
		    console.log(data);
		  });
		});

### Interface

Quick example:

	$websocket = new WebSocket(array('port' => 8080));

	if($websocket->connect()) {

		$someData = array('notify' => false, 'foo' => $bar);
		$websocket->emit('my other event', $someData);

	}


### Bugs & Contribution

* Some functions (encoding/decoding) were extracted from this [php-websocket](https://github.com/lemmingzshadow/php-websocket) project.

* Patches welcome! Send a pull request.

* Post issues on [Github](http://github.com/mgcrea/cake_websocket/issues)

* The latest code will always be [here](http://github.com/mgcrea/cake_websocket)


### License

	Copyright 2012 Olivier Louvignes. All rights reserved.

	The MIT License

	Permission is hereby granted, free of charge, to any person obtaining a
	copy of this software and associated documentation files (the "Software"),
	to deal in the Software without restriction, including without limitation
	the rights to use, copy, modify, merge, publish, distribute, sublicense,
	and/or sell copies of the Software, and to permit persons to whom the
	Software is furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
	THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
	FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
	DEALINGS IN THE SOFTWARE.
