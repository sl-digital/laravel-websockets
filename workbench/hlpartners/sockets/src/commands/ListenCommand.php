<?php namespace Hlpartners\Sockets;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class ListenCommand extends Command
{
	protected $server;
	protected $name 		= "hlserver:listen";
	protected $description 	= 'Start listening on specified port for incomming websocket connections';

	public function __construct($app)
	{
		$this->server = $app->make('hlserver');
		parent::__construct();
	}

	public function fire()
	{
		set_time_limit(0);
		$this->server = new SocketServer;
		$this->server->boot();
	}
}