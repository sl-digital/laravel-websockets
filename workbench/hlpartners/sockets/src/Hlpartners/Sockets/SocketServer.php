<?php namespace Hlpartners\Sockets;

/**
 * H&L WebSocket Server
 * Creates a connection for mobile to desktop comms
 *
 * @category   WebSockets
 * @package    Hlpartners\Sockets
 * @copyright  2014 H&L Partners, St. Louis
 * @version    Release: 1.0.0
 */

use Illuminate\Container\Container;

class SocketServer
{
	private $host;
	private $port;
	private $socket;

	private $controller;
	private $clients;
	private $rooms;
	private $users;

	private $null;
	private $ping;
	private $container;

	//List your IP or URLs here ie: 'http://1.1.1.1' or 'http://www.mysite.com'
	private $origins = array();

	//============================================================
	// Server Constructor
	//============================================================

	public function __construct(Container $container = NULL){
		$this->container = $container;
	}

	//============================================================
	// Server Boot Up - Change host to actual host IP
	//============================================================

	public function boot($host='127.0.0.1',$port='8888'){
		//Set server connection details
		$this->host = $host;
		$this->port = $port;
		$this->null = NULL;

		//Create stream socket
		$this->log("[SRVR] Booting...");
		try {
			$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
			socket_bind($this->socket, $this->host, $this->port);
			socket_listen($this->socket);
		} catch(ErrorException $e) {
			$this->log("[SRVR] Error $e");
			die();
		}

		//Add master to list and start listening
		$this->log("[SRVR] Status OK");
		$this->controller = new SocketController($this);
		$this->clients = array($this->socket);
		$this->rooms = array();
		$this->users = array();
		$this->run();
	}

	//============================================================
	// Run Loop
	//============================================================

	public function run(){

		$this->log("[SRVR] Starting Loop");
		$this->ping = time() + 30;

		while(true){

			//Copy the current clients list
			$read = $this->clients;
			$write = array();
			$except = array();

			//Check for updates or skip
			$updates = @socket_select($read, $write, $except, 0, 10);
			if( $updates < 0 ) continue;

			//Check connections when updates exist
			if($updates > 0){

				//New connection detected
				if(in_array($this->socket, $read)){

					//Accept new connection
					$socket_new = @socket_accept($this->socket);
					
					//Valid connection made
					if($socket_new !== FALSE && $socket_new > 0){

						$this->clients[] = $socket_new;
					
						//Attempt to read from the socket
						$header = @socket_read($socket_new, 2048);

						if($header !== FALSE){

							//Perform WebSocket handshake
							$shake = $this->handshake($header, $socket_new, $this->host, $this->port);

							if($shake === FALSE){
								//Remove from clients
								$socket_result = array_search($socket_new, $this->clients);
								if($socket_result !== FALSE) unset($this->clients[$socket_result]);

								//Remove from scanner
								$socket_result = array_search($socket_new, $read);
								if($socket_result !== FALSE) unset($read[$found_socket]);

								//Close connection
								socket_close($socket_new);
							} else {
								//Create a new user
								$user = new SocketUser($this);
								$user->id = $this->getUserID($socket_new);
								$user->socket = $socket_new;
								$this->addUser($user);
							}

							//Remove socket from changed list before scanning
							$found_socket = array_search($this->socket, $read);
							if($found_socket !== FALSE) unset($read[$found_socket]);

						} else {
			
							//Read failed
							$socket_result = array_search($socket_new, $this->clients);
							if($socket_result !== FALSE) unset($this->clients[$socket_result]);

							//Remove from scanner
							$socket_result = array_search($socket_new, $read);
							if($socket_result !== FALSE) unset($read[$found_socket]);

							//Close connection
							socket_close($socket_new);

						}
					}
				}
				
				//Scan open connections
				foreach($read as $client) {
					$input = $this->readClient($client);

					if( !empty($input) ){
						//Update the connection status
						$this->touchUser($client);

						//Parse the socket message
						$this->controller->parse($client,$input);
					}
				}
			}

			//Check the ping every 30s
			if( time() >= $this->ping ){
				$this->pingUsers();
				$this->ping = time() + 30;
			}
		}

		// close the listening socket
		socket_close($this->socket);
	}

	//============================================================
	// Read Client Input
	//============================================================

	private function readClient($client){
		$buffer = "";
		$input = "";

		if($client !== $this->socket)
		{
			if( ($bytes = @socket_recv($client, $input, 2048, MSG_DONTWAIT)) > 0 ){
				if(!empty($input)){
					$buffer .= $input;
				}
			} else if($bytes === FALSE) {
				$this->log("[SRVR] Connection Lost");
				$this->closeUser($client);
				return $this->null;
			}

			//A length less than 2 is always a disconnect
			if( strlen($bytes) < 2 ){
				$this->log("[SRVR] Connection Dropped");
				$this->closeUser($client);
				return $this->null;
			} else {
				$buffer = $this->unmask($buffer);
				$buffer = json_decode($buffer);
			}
		}

		return $buffer;
	}

	//============================================================
	// Ping Client Connections
	//============================================================

	private function pingUsers(){
		if( count($this->users) > 0 ){
			foreach($this->users as $user){
				$test = $user->ping();
				if($test === FALSE){
					$this->log("[PING] Connection Lost");
					$this->closeUser($user->socket);
					break;
				} else if($test === "IDLE"){
					$this->log("[PING] Connection Idle");
					$this->closeUser($user->socket);
					break;
				}
			}
		}
	}

	//============================================================
	// WebSocket Handshake
	//============================================================

	private function handshake($received_header, $client_conn, $host, $port){
		//Read incoming headers
		$headers = array();
		$lines = preg_split("/\r\n/", $received_header);

		//Check for a valid HTTP connection
		if(!preg_match('/\AGET (\S+) HTTP\/1.1\z/', $lines[0], $matches))
		{
            		$this->log('[SRVR] Invalid HTTP Request');
            		$this->sendHttpResponse($client_conn,400);
            		return false;
        	}

		//Copy over header information
		foreach($lines as $line){
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches)){
				$headers[$matches[1]] = $matches[2];
			}
		}

		//Check for a valid origin
		if( !isset($headers['Origin']) || !in_array($headers['Origin'],$this->origins) ){
            $origin = (isset($headers['Origin'])) ? $headers['Origin'] : "No-Origin";
            $this->log('[SRVR] Access Denied : ' . $origin);
            $this->sendHttpResponse($client_conn,401);
            return false;
		}

		//Check for a valid WebSocket version
		if( !isset($headers['Sec-WebSocket-Version']) || $headers['Sec-WebSocket-Version'] < 6 ){
            $this->log('[SRVR] Unsupported WebSocket Version');
            $this->sendHttpResponse($client_conn,501);
            return false;
		}

		//Check for a valid WebSocket key
		if( !isset($headers['Sec-WebSocket-Key']) || empty($headers['Sec-WebSocket-Key']) ){
			$this->log('[SRVR] Unsupported WebSocket Version');
            $this->sendHttpResponse($client_conn,501);
            return false;
		}

		//WebSocket Accept Header
		$secKey = $headers['Sec-WebSocket-Key'];
		$secAccept = base64_encode(pack('H*',sha1($secKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

		//Create Handshake Header
		$upgrade = 	"HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
					"Upgrade: WebSocket\r\n" .
					"Connection: Upgrade\r\n" .
					"WebSocket-Origin: $host\r\n" .
					"WebSocket-Location: ws://$host:$port/\r\n".
					"Sec-WebSocket-Accept:$secAccept\r\n\r\n";

		//Send Handshake
		@socket_write($client_conn,$upgrade,strlen($upgrade));
	}

	private function sendHttpResponse($socket=null,$status=400){
		$httpHeader = 'HTTP/1.1 ';

		switch($status){
			case 400:
				$httpHeader .= '400 Bad Request';
				break;
			case 401:
				$httpHeader .= '401 Unauthorized';
				break;
			case 403:
				$httpHeader .= '403 Forbidden';
				break;
			case 404:
				$httpHeader .= '404 Not Found';
				break;
			case 501:
				$httpHeader .= '501 Not Implemented';
				break;
		}
		$httpHeader .= "\r\n";

		//Send Response
		@socket_write($socket,$httpHeader,strlen($httpHeader));
	}

	//============================================================
	// Data Masking
	//============================================================

	public function mask($text){
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($text);
		
		if($length <= 125){
			$header = pack('CC', $b1, $length);
		} elseif($length > 125 && $length < 65536){
			$header = pack('CCn', $b1, 126, $length);
		} elseif($length >= 65536){
			$header = pack('CCNN', $b1, 127, $length);
		}

		return $header.$text;
	}

	public function unmask($text){
		$length = ord($text[1]) & 127;

		if($length == 126) {
			$masks = substr($text, 4, 4);
			$data = substr($text, 8);
		} elseif($length == 127) {
			$masks = substr($text, 10, 4);
			$data = substr($text, 14);
		} else {
			$masks = substr($text, 2, 4);
			$data = substr($text, 6);
		}

		$text = "";
		for($i = 0; $i < strlen($data); ++$i){
			$text .= $data[$i] ^ $masks[$i%4];
		}

		return $text;
	}

	//============================================================
	// Terminal Output
	//============================================================

	public function log($message=''){
		fputs(STDERR,$message."\r\n");
	}

	//============================================================
	// User Methods
	//============================================================

	public function getUserID($socket){
		return intval($socket);
	}

	public function getUserByConnection($socket){
		$id = $this->getUserID($socket);
		$user = NULL;

		if( !empty($id) && isset($this->users[$id]) ){
			$user = $this->users[$id];
		}

		return $user;
	}

	public function touchUser($socket){
		$user = $this->getUserByConnection($socket);
		if( !empty($user) ) $user->touch();
	}

	public function addUser($user){
		if( !empty($user) && isset($user->id) ){
			$this->users[$user->id] = $user;
			$this->log("[USER] Added user $user");
			$this->log("[SRVR] There are now " . count($this->users) . " users");
			return TRUE;
		} else {
			return FALSE;
		}
	}

	public function dropUser($user){
		if( !empty($user) && isset($user->id) ){
			//Remove the user from their room
			$room = $this->getRoom($user->room);
			$room_users = array();

			if( !empty($room) ){
				$room->dropUser($user);
				if($user->type === "display"){
					$room_users = $room->users;
					foreach($room_users as $usr){
						//Remove control users
						if($usr->type === "control") $this->closeUser($usr->socket);
					}
				} else {
					$room_users = $room->users;
					foreach($room_users as $usr){
						if($usr->type === "display"){
							//Notify the display
							$resp = array('type'=>'event', 'name'=>'onControlLost', 'message'=>'', 'errors'=>0);
							$response_text = $this->mask(json_encode($resp));
							@socket_write($usr->socket,$response_text,strlen($response_text));
						}
					}
				}
			}

			//Remove the user from the list
			unset($this->users[$user->id]);
			$this->log("[SRVR] Drop user $user");
			$this->log("[SRVR] There are now " . count($this->users) . " users");

			return TRUE;
		} else {
			return FALSE;
		}
	}

	public function closeUser($socket){
		if($socket !== $this->socket){
			//Remove the client user
			$user = $this->getUserByConnection($socket);

			if($user !== NULL){
				$this->dropUser($user);

				//Remove the client
				$found_socket = array_search($socket, $this->clients);
				unset($this->clients[$found_socket]);

				//Close the connection
				@socket_close($socket);
				$this->log("[SRVR] Connection closed to $user");

				//Cleanup
				if( count($this->users) == 0 ){
					$this->users = array();
					$this->rooms = array();
					$this->log("[SRVR] Cleaning Storage");
				}
			}
		} else {
			$this->log("[SRVR] Cannot close master socket");
		}
	}

	//============================================================
	// Room Methods
	//============================================================

	public function getRoom($name=''){
		$room = NULL;

		if( isset($this->rooms[$name]) ){
			$room = $this->rooms[$name];
		}

		return $room;
	}

	public function addRoom($name=''){
		$room = $this->getRoom($name);

		if( empty($room) && !empty($name) ){
			$room = new SocketRoom($this);
			$room->name = $name;
			$this->rooms[$name] = $room;
			$this->log("[ROOM] Added room $name");
		}

		return $room;
	}

	public function dropRoom($name=''){
		if( isset($this->rooms[$name]) ){
			$this->rooms[$name]->implode();
			unset($this->rooms[$name]);
			$this->log("[ROOM] Dropped room $name");
			return TRUE;
		} else {
			return FALSE;
		}
	}
}
