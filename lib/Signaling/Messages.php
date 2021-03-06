<?php
/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Spreed\Signaling;


use OCA\Spreed\Room;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class Messages {

	/** @var IDBConnection */
	protected $db;

	/** @var ITimeFactory */
	protected $time;

	/**
	 * @param IDBConnection $db
	 * @param ITimeFactory $time
	 */
	public function __construct(IDBConnection $db, ITimeFactory $time) {
		$this->db = $db;
		$this->time = $time;
	}

	/**
	 * @param string[] $sessionIds
	 */
	public function deleteMessages(array $sessionIds) {
		$query = $this->db->getQueryBuilder();
		$query->delete('videocalls_signaling')
			->where($query->expr()->in('recipient', $query->createNamedParameter($sessionIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orWhere($query->expr()->in('sender', $query->createNamedParameter($sessionIds, IQueryBuilder::PARAM_STR_ARRAY)));
		$query->execute();
	}

	/**
	 * @param string $senderSessionId
	 * @param string $recipientSessionId
	 * @param string $message
	 */
	public function addMessage($senderSessionId, $recipientSessionId, $message) {
		$query = $this->db->getQueryBuilder();
		$query->insert('videocalls_signaling')
			->values(
				[
					'sender' => $query->createNamedParameter($senderSessionId),
					'recipient' => $query->createNamedParameter($recipientSessionId),
					'timestamp' => $query->createNamedParameter($this->time->getTime()),
					'message' => $query->createNamedParameter($message),
				]
			);
		$query->execute();
	}

	/**
	 * @param Room $room
	 * @param string $message
	 */
	public function addMessageForAllParticipants(Room $room, $message) {
		$query = $this->db->getQueryBuilder();
		$query->insert('videocalls_signaling')
			->values(
				[
					'sender' => $query->createParameter('sender'),
					'recipient' => $query->createParameter('recipient'),
					'timestamp' => $query->createNamedParameter($this->time->getTime()),
					'message' => $query->createNamedParameter($message),
				]
			);

		foreach ($room->getActiveSessions() as $sessionId) {
			$query->setParameter('sender', $sessionId)
				->setParameter('recipient', $sessionId)
				->execute();
		}
	}

	/**
	 * Get messages and delete them afterwards
	 *
	 * To make sure we don't delete messages which we didn't return
	 * we do it with 1 second difference. This means you don't receive messages
	 * immediately, but the next polling is only 1 second later and will get the
	 * "new" message.
	 *
	 * @param $sessionId
	 * @return array
	 */
	public function getAndDeleteMessages($sessionId) {
		$messages = [];
		$time = $this->time->getTime() - 1;

		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('videocalls_signaling')
			->where($query->expr()->eq('recipient', $query->createNamedParameter($sessionId)))
			->andWhere($query->expr()->lte('timestamp', $query->createNamedParameter($time)));
		$result = $query->execute();

		while ($row = $result->fetch()) {
			$messages[] = ['type' => 'message', 'data' => $row['message']];
		}
		$result->closeCursor();

		$query = $this->db->getQueryBuilder();
		$query->delete('videocalls_signaling')
			->where($query->expr()->eq('recipient', $query->createNamedParameter($sessionId)))
			->andWhere($query->expr()->lte('timestamp', $query->createNamedParameter($time)));
		$query->execute();

		return $messages;
	}
}
