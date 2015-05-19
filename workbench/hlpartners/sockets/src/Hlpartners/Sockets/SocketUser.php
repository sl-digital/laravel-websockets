<?php namespace Hlpartners\Sockets;

/**
 * H&L WebSocket User
 * Stores details on individual connections
 *
 * @category   WebSockets
 * @package    Hlpartners\Sockets
 * @copyright  2014 H&L Partners, St. Louis
 * @version    Release: 1.0.0
 */

class SocketUser
{
	public $id;
	public $room;
	public $socket;
	public $type;
	public $types;

	public $last_time = 0;
	public $last_ping = 0;

	private $server;

	//============================================================
	// User Constructor
	//============================================================

	public function __construct(SocketServer $server = NULL){
		$this->server = $server;
		$this->id = NULL;
		$this->type = NULL;
		$this->room = NULL;
		$this->socket = NULL;
		$this->types = array('control','display');	
		$this->touch();
	}

	//============================================================
	// User Types
	//============================================================

	public function setType($type=''){
		if( in_array($type,$this->types) ){
			$this->type = $type;
			return TRUE;
		} else {
			return FALSE;
		}
	}

	public function touch(){
		$this->last_time = time();
	}

	public function ping(){
		$this->last_ping = time();

		//Check for dead connections
		if( !is_resource($this->socket) ){
			return false;
		}
		//Check for 15-minute idle
		if( 900 < ($this->last_ping - $this->last_time) ){
			return "IDLE";
		}

		try {
			$test = array('type'=>'event','name'=>'ping');
			$test = $this->server->mask(json_encode($test));
			$ping = @socket_write($this->socket,$test,strlen($test));
		} catch (SocketException $e) {
			return false;
		}

		return $ping;
	}

	//============================================================
	// User Output Formatting
	//============================================================

	public function __toString(){
		return "[id:" . $this->id . ", type:" . $this->type . ", room:" . $this->room . "]";
	}
}