<?php

/*

           -
         /   \
      /         \
   /   PocketMine  \
/          MP         \
|\     @shoghicp     /|
|.   \           /   .|
| ..     \   /     .. |
|    ..    |    ..    |
|       .. | ..       |
\          |          /
   \       |       /
      \    |    /
         \ | /

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.


*/



class RCON{
	private $socket, $password, $workers, $threads;
	
	public function __construct($password, $port = 19132, $interface = "0.0.0.0", $threads = 4, $clientsPerThread = 5){
		$this->workers = array();
		$this->password = (string) $password;
		console("[INFO] Starting remote control listener");
		if($this->password === ""){
			console("[ERROR] RCON can't be started: Empty password");
			return;
		}
		$this->threads = (int) max(1, $threads);
		$this->clientsPerThread = (int) max(1, $clientsPerThread);
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if($this->socket === false or !socket_bind($this->socket, $interface, (int) $port) or !socket_listen($this->socket)){
			console("[ERROR] RCON can't be started: ".socket_strerror(socket_last_error()));
			return;
		}		
		@socket_set_nonblock($this->socket);
		for($n = 0; $n < $this->threads; ++$n){
			$this->workers[$n] = new RCONInstance($this->socket, $this->password, $this->clientsPerThread);
		}
		@socket_getsockname($this->socket, $addr, $port);
		console("[INFO] RCON running on $addr:$port");
		ServerAPI::request()->schedule(2, array($this, "check"), array(), true);
	}
	
	public function stop(){
		for($n = 0; $n < $this->threads; ++$n){
			$this->workers[$n]->close();
			$this->workers[$n]->join();
		}
		@socket_close($this->socket);
		$this->threads = 0;
	}
	
	public function check(){
		for($n = 0; $n < $this->threads; ++$n){
			if($this->workers[$n]->isTerminated() === true){
				$this->workers[$n] = new RCONInstance($this->socket, $this->password, $this->clientsPerThread);
			}elseif($this->workers[$n]->isWaiting()){
				if($this->workers[$n]->response !== ""){
					console($this->workers[$n]->response);
					$this->workers[$n]->notify();
				}else{
					$this->workers[$n]->response = ServerAPI::request()->api->console->run($this->workers[$n]->cmd, "console");
					$this->workers[$n]->notify();
				}
			}
		}
	}

}

class RCONInstance extends Thread{
	public $stop;
	public $cmd;
	public $response;
	private $socket;
	private $password;
	private $status;
	private $maxClients;

	public function __construct($socket, $password, $maxClients = 5){
		$this->stop = false;
		$this->cmd = "";
		$this->response = "";
		$this->socket = $socket;
		$this->password = $password;
		$this->maxClients = (int) $maxClients;
		for($n = 0; $n < $this->maxClients; ++$n){
			$this->{"client".$n} = null;
			$this->{"status".$n} = 0;
		}
		$this->status = array();
		$this->start();
	}
	
	private function writePacket($client, $requestID, $packetType, $payload){
		return socket_write($client, Utils::writeLInt(strlen($payload)).Utils::writeLInt((int) $requestID).Utils::writeLInt((int) $packetType).($payload === "" ? "\x00":$payload)."\x00");
	}
	
	private function readPacket($client, &$size, &$requestID, &$packetType, &$payload){
		@socket_set_nonblock($client);
		$d = socket_read($client, 4);
		if($this->stop === true){
			return false;
		}elseif($d === false){
			return null;
		}elseif($d === ""){
			return false;
		}
		@socket_set_block($client);
		$size = Utils::readLInt($d);
		if($size < 0){
			return false;
		}
		$requestID = Utils::readLInt(socket_read($client, 4));
		$packetType = Utils::readLInt(socket_read($client, 4));
		$payload = rtrim(socket_read($client, $size + 2)); //Strip two null bytes
		return true;
	}
	
	public function close(){
		$this->stop = true;
	}
	
	public function run(){
		while($this->stop !== true){
			usleep(1);
			if(($client = socket_accept($this->socket)) !== false){
				socket_set_block($client);
				socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);
				$done = false;
				for($n = 0; $n < $this->maxClients; ++$n){
					if($this->{"client".$n} === null){
						$this->{"client".$n} = $client;
						$this->{"status".$n} = 0;
						$done = true;
						break;
					}
				}
				if($done === false){
					@socket_close($client);
				}
			}

			for($n = 0; $n < $this->maxClients; ++$n){
				$client = &$this->{"client".$n};
				if($client !== null){
					if($this->{"status".$n} !== -1 and $this->stop !== true){
						$p = $this->readPacket($client, $size, $requestID, $packetType, $payload);
						if($p === false){
							$this->{"status".$n} = -1;
							continue;
						}elseif($p === null){
							continue;
						}

						switch($packetType){
							case 3: //Login
								if($this->{"status".$n} !== 0){
									$this->{"status".$n} = -1;
									continue;
								}
								if($payload === $this->password){
									@socket_getpeername($client, $addr, $port);
									$this->response = "[INFO] Successful Rcon connection from: /$addr:$port";
									$this->wait();
									$this->response = "";
									$this->writePacket($client, $requestID, 2, "");
									$this->{"status".$n} = 1;
								}else{
									$this->{"status".$n} = -1;
									$this->writePacket($client, -1, 2, "");
									continue;
								}
								break;
							case 2: //Command
								if($this->{"status".$n} !== 1){
									$this->{"status".$n} = -1;
									continue;
								}
								if(strlen($payload) > 0){
									$this->cmd = ltrim($payload);
									$this->wait();
									$this->writePacket($client, $requestID, 0, str_replace("\n", "\r\n", trim($this->response)));
									$this->response = "";
									$this->cmd = "";
								}
								break;
						}
						usleep(1);
					}else{
						@socket_set_option($client, SOL_SOCKET, SO_LINGER, array("l_onoff" => 1, "l_linger" => 1));
						@socket_shutdown($client, 2);
						@socket_set_block($client);
						@socket_read($client, 1);
						@socket_close($client);
						$this->{"status".$n} = 0;
						$this->{"client".$n} = null;
					}
				}
			}
		}
		unset($this->socket, $this->cmd, $this->response, $this->stop);
		exit(0);
	}
}