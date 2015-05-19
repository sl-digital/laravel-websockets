<?php namespace Hlpartners\Sockets;

/**
 * H&L WebSocket Room
 * Stores details linking users
 *
 * @category   WebSockets
 * @package    Hlpartners\Sockets
 * @copyright  2014 H&L Partners, St. Louis
 * @version    Release: 1.0.0
 */

class SocketRoom
{
	public $name;
	public $users;
	private $server;
	private $max_users;

	//============================================================
	// Room Constructor
	//============================================================

	public function __construct(SocketServer $server = NULL){
		$this->name = "";
		$this->users = array();
		$this->server = $server;
		$this->max_users = 2;
	}

	//============================================================
	// Room Users
	//============================================================

	public function addUser($user){
		if( !empty($user) && isset($user->id) && isset($user->type) ){

			//Prevent more that one display user
			if( $this->hasDisplay() && $user->type === "display" ) return FALSE;

			//Prevent more that one control user
			if( $this->hasControl() && $user->type === "control" ) return FALSE;

			//Prevent duplicate users
			if( isset($this->users[$user->id]) ) return FALSE;

			//Assign room name to user and add to collection
			$user->room = $this->name;
			$this->users[$user->id] = $user;
			$this->server->log("[ROOM] #" . $this->name . " Add user $user");
			$this->server->log("[ROOM] #" . $this->name . " Total users " . count($this->users));

			return TRUE;

		} else {
			$this->server->log("[ROOM] #" . $this->name . " Cannot add user $user");
			return FALSE;
		}
	}

	public function dropUser($user){
		if( !empty($user) && isset($user->id) && isset($this->users[$user->id]) ){
			//Remove user
			unset($this->users[$user->id]);
			$this->server->log("[ROOM] #" . $this->name . " Drop user $user");
			$this->server->log("[ROOM] #" . $this->name . " Total users " . count($this->users));

			//Check for an empty room
			if( count($this->users) == 0 ) $this->server->dropRoom($this->name);

			return TRUE;
		} else {
			return FALSE;
		}
	}

	public function totalUsers(){
		return count($this->users);
	}

	public function ready(){
		if( count($this->users)==2 && $this->hasDisplay() && $this->hasControl() ){
			return TRUE;
		} else {
			return FALSE;
		}
	}

	public function getDisplay(){
		foreach($this->users as $user){
			if($user->type === "display") 
				return $user;
		}
		return NULL;
	}

	public function getControl(){
		foreach($this->users as $user){
			if($user->type === "control") 
				return $user;
		}
		return NULL;
	}

	//============================================================
	// Room Shutdown
	//============================================================

	public function implode(){
		$this->users = array();
		$this->server->log("[ROOM] #" . $this->name . " Implode");
	}

	//============================================================
	// User Checks
	//============================================================

	private function hasDisplay(){
		foreach($this->users as $user){
			if($user->type === "display") 
				return TRUE;
		}
		return FALSE;
	}

	private function hasControl(){
		foreach($this->users as $user){
			if($user->type === "control") 
				return TRUE;
		}
		return FALSE;
	}
}
