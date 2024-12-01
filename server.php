<?php
require __DIR__ . '/vendor/autoload.php';


use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\App;

class DrawingServer implements MessageComponentInterface {
    protected $clients;
    protected $rooms;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->rooms = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    private function generateRoomId() {
        do {
            $roomId = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (isset($this->rooms[$roomId]));
        return $roomId;
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);

        switch ($data['type']) {
            case 'createRoom':
                $this->handleCreateRoom($from, $data);
                break;
            case 'joinRoom':
                $this->handleJoinRoom($from, $data);
                break;
            case 'draw':
                $this->handleDraw($from, $data);
                break;
        }
    }

    private function handleCreateRoom(ConnectionInterface $from, $data) {
        $userName = $data['userName'];
        $roomName = $data['roomName'];

        foreach ($this->rooms as $roomId => $room) {
            if ($room['host'] === $userName && isset($room['users'][$userName])) {
                $room['users'][$userName] = $from;
                $from->send(json_encode(['type' => 'roomCreated', 'status' => 'success', 'roomId' => $roomId]));
                return;
            }
        }

        $roomId = $this->generateRoomId();
        $this->rooms[$roomId] = [
            'name' => $roomName,
            'host' => $userName,
            'users' => [$userName => $from],
            'drawingData' => []
        ];
        $from->send(json_encode(['type' => 'roomCreated', 'status' => 'success', 'roomId' => $roomId]));
        echo "Пользователь {$userName} создал комнату {$roomId} с названием '{$roomName}'. Хост: {$userName}, количество пользователей - 1.\n";
    }

    private function handleJoinRoom(ConnectionInterface $from, $data) {
        $roomId = $data['roomId'];
        $userName = $data['userName'];
        if (isset($this->rooms[$roomId])) {
            if (isset($this->rooms[$roomId]['users'][$userName])) {
                $from->send(json_encode(['type' => 'joinRoom', 'status' => 'error', 'message' => 'Username already taken']));
            } else {
                $this->rooms[$roomId]['users'][$userName] = $from;
                $from->send(json_encode(['type' => 'joinRoom', 'status' => 'success', 'roomName' => $this->rooms[$roomId]['name'], 'drawingData' => $this->rooms[$roomId]['drawingData']]));
                echo "Пользователь {$userName} подключился к комнате {$roomId}. Хост: {$this->rooms[$roomId]['host']}, количество пользователей - " . count($this->rooms[$roomId]['users']) . ".\n";
            }
        } else {
            $from->send(json_encode(['type' => 'joinRoom', 'status' => 'error', 'message' => 'Room not found']));
        }
    }

    private function handleDraw(ConnectionInterface $from, $data) {
        $roomId = $data['roomId'];
        $drawingData = $data['drawingData'];
        if (isset($this->rooms[$roomId])) {
            $this->rooms[$roomId]['drawingData'][] = $drawingData;
            foreach ($this->rooms[$roomId]['users'] as $user) {
                if ($from !== $user) {
                    $user->send(json_encode(['type' => 'draw', 'drawingData' => $drawingData]));
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        foreach ($this->rooms as $roomId => $room) {
            foreach ($room['users'] as $userName => $user) {
                if ($conn === $user) {
                    unset($this->rooms[$roomId]['users'][$userName]);
                    if (count($this->rooms[$roomId]['users']) === 0) {
                        unset($this->rooms[$roomId]);
                        echo "Комната {$roomId} удалена, так как все пользователи отключились.\n";
                    } else {
                        echo "Пользователь {$userName} отключился от комнаты {$roomId}. Хост: {$this->rooms[$roomId]['host']}, количество пользователей - " . count($this->rooms[$roomId]['users']) . ".\n";
                    }
                    break;
                }
            }
        }
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

$app = new App('localhost', 8080);
$app->route('/drawing', new DrawingServer, ['*']);
$app->run();