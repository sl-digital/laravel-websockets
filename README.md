# laravel-websockets
A laravel websocket server

This websocket server was implemented as a quick proof-of-concept and is provided as-is.  It is based off other publicly available PHP websocket projects.  This implementation is specifically geared towards syncing a mobile phone to a web application as a controller and was implemented as a Laravel Service.

To add this to a project, you must include the SocketsServiceProvider in your providers array

'providers' => array(
  ...,
	'Hlpartners\Sockets\SocketsServiceProvider'
),

You can then activate the server using "php artisan hlserver:listen" on the configured address and port.
