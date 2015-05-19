<?php namespace Hlpartners\Sockets;

/**
 * H&L WebSocket Controller
 * Handles events and message relays
 *
 * @category   WebSockets
 * @package    Hlpartners\Sockets
 * @copyright  2014 H&L Partners, St. Louis
 * @version    Release: 1.0.0
 */

class SocketController
{
	private $server;

	//============================================================
	// Controller Constructor
	//============================================================

	public function __construct(SocketServer $server = NULL){
		$this->server = $server;
	}

	//============================================================
	// Command Parser
	//============================================================

	public function parse($conn=NULL,$msg=''){
		$user = $this->server->getUserByConnection($conn);

		if( isset($msg->cmd) && !empty($user) ){
			switch($msg->cmd){
				case "set_user_type":
					$this->setType($user,$msg);
					break;

				case "create_room":
					$this->createRoom($user,$msg);
					break;

				case "join_room":
					$this->joinRoom($user,$msg);
					break;

				case "check_room":
					$this->checkRoom($user,$msg);
					break;

				case "relay_event":
					$this->relayEvent($user,$msg);
					break;
			}
		}
	}

	//============================================================
	// Command Methods
	//============================================================

	private function setType($user=NULL,$msg=''){
		$resp = array('type'=>'event', 'name'=>'onUserType', 'message'=>'', 'errors'=>0);

		if( !empty($user) && isset($msg->data) ){
			$status = $user->setType($msg->data);
			if( $status === TRUE ){
				$resp['message'] = 'Ok';
			} else {
				$resp['message'] = 'Error setting type';
				$resp['errors']++;
			} 
		} else {
			$resp = $this->parseError("User not found");
		}

		//Send single response
		$this->respond($user,$resp);
	}

	private function createRoom($user=NULL,$msg=''){
		$resp = array('type'=>'event', 'name'=>'onRoomCreated', 'message'=>'', 'errors'=>0);

		if( !empty($user) && isset($msg->data) ){
			$room = $this->server->addRoom($msg->data);
			if( !empty($room) && isset($room->name) ){
				$resp['message'] = 'Ok';
				$room->addUser($user);
			} else {
				$resp['message'] = 'Error creating room';
				$resp['errors']++;
			}
		} else {
			$resp = $this->parseError("User not found");
		}

		//Send single response
		$this->respond($user,$resp);
	}

	private function joinRoom($user=NULL,$msg=''){
		$resp = array('type'=>'event', 'name'=>'onRoomJoined', 'message'=>'', 'errors'=>0);

		if( !empty($user) && isset($msg->data) ){
			$room = $this->server->getRoom($msg->data);
			if( !empty($room) ){
				$status = $room->addUser($user);
				if($status === TRUE){
					$resp['message'] = 'Ok';
				} else {
					$resp['message'] = 'Error joining room';
					$resp['errors']++;
				}
			} else {
				$resp['message'] = 'Error finding room';
				$resp['errors']++;
			}
		} else {
			$resp = $this->parseError("User not found");
		}

		//Send single response
		$this->respond($user,$resp);
	}

	private function checkRoom($user=NULL,$msg=''){
		$resp = array('type'=>'event', 'name'=>'onRoomReady', 'message'=>'', 'errors'=>0);

		if( !empty($user) && isset($user->room) ){
			$room = $this->server->getRoom($user->room);
			if( !empty($room) ){
				$status = $room->ready();
				if($status === TRUE){
					$resp['message'] = 'Ok';

					//Send group response
					$users = $room->users;
					foreach($users as $usr){
						$this->respond($usr,$resp);
					}

				} else {
					$resp['message'] = 'Error joining room';
					$resp['errors']++;
					$this->respond($user,$resp);
				}
			} else {
				$resp['message'] = 'Error finding room';
				$resp['errors']++;
				$this->respond($user,$resp);
			}
		} else {
			$resp = $this->parseError("User not found");
			$this->respond($user,$resp);
		}
	}

	private function relayEvent($user=NULL,$msg=''){
		$resp = array('type'=>$msg->event, 'name'=>'onRelay', 'message'=>'', 'errors'=>0);

		if( !empty($user) && isset($user->room) && isset($msg->event) ){
			$room = $this->server->getRoom($user->room);
			$recv = ($user->type == "display") ? $room->getControl() : $room->getDisplay();

			if( !empty($recv) ){
				$resp = array('type'=>'event', 'name'=>$msg->event, 'message'=>$msg->data, 'errors'=>0);
				$this->respond($recv,$resp);
			} else {
				$resp['message'] = 'Error finding user';
				$resp['errors']++;
				$this->respond($user,$resp);
			}
		} else {
			$resp = $this->parseError("User not found");
			$this->respond($user,$resp);
		}
	}

	//============================================================
	// Command Response
	//============================================================

	private function parseError($msg="Invalid request"){
		return array('type'=>'error', 'name'=>'onError', 'message'=>$msg);
	}

	private function respond($user,$resp=''){
		if( !empty($user) && isset($user->socket) ){
			$response_text = $this->server->mask(json_encode($resp));
			@socket_write($user->socket,$response_text,strlen($response_text));
		} else {
			$this->server->log("[Controller] Response error!");
		}
	}
}
