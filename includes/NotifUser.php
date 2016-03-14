<?php

/**
 * Entity that represents a notification target user
 */
class MWEchoNotifUser {

	/**
	 * Notification target user
	 * @var User
	 */
	private $mUser;

	/**
	 * Object cache
	 * @var BagOStuff
	 */
	private $cache;

	/**
	 * Database access gateway
	 * @var EchoUserNotificationGateway
	 */
	private $userNotifGateway;

	/**
	 * Notification mapper
	 * @var EchoNotificationMapper
	 */
	private $notifMapper;

	/**
	 * Target page mapper
	 * @var EchoTargetPageMapper
	 */
	private $targetPageMapper;

	/**
	 * @var EchoForeignNotifications
	 */
	private $foreignNotifications;

	// The max notification count shown in badge

	// The max number shown in bundled message, eg, <user> and 99+ others <action>.
	// This is really a totally separate thing, and could be its own constant.

	// WARNING: If you change this, you should also change all references in the
	// i18n messages (100 and 99) in all repositories using Echo.
	const MAX_BADGE_COUNT = 99;

	/**
	 * Usually client code doesn't need to initialize the object directly
	 * because it could be obtained from factory method newFromUser()
	 * @param User $user
	 * @param BagOStuff $cache
	 * @param EchoUserNotificationGateway $userNotifGateway
	 * @param EchoNotificationMapper $notifMapper
	 * @param EchoTargetPageMapper $targetPageMapper
	 */
	public function __construct(
		User $user,
		BagOStuff $cache,
		EchoUserNotificationGateway $userNotifGateway,
		EchoNotificationMapper $notifMapper,
		EchoTargetPageMapper $targetPageMapper
	) {
		$this->mUser = $user;
		$this->userNotifGateway = $userNotifGateway;
		$this->cache = $cache;
		$this->notifMapper = $notifMapper;
		$this->targetPageMapper = $targetPageMapper;
		$this->foreignNotifications = new EchoForeignNotifications( $user );
	}

	/**
	 * Factory method
	 * @param $user User
	 * @throws MWException
	 * @return MWEchoNotifUser
	 */
	public static function newFromUser( User $user ) {
		if ( $user->isAnon() ) {
			throw new MWException( 'User must be logged in to view notification!' );
		}
		global $wgMemc;

		return new MWEchoNotifUser(
			$user, $wgMemc,
			new EchoUserNotificationGateway( $user, MWEchoDbFactory::newFromDefault() ),
			new EchoNotificationMapper(),
			new EchoTargetPageMapper()
		);
	}

	/**
	 * Clear talk page notification when users visit their talk pages.  This
	 * only resets if the notification count is less than max notification
	 * count. If the user has 99+ notifications, decrementing 1 bundled talk
	 * page notification would not really affect the count
	 */
	public function clearTalkNotification() {
		// There is no new talk notification
		if ( $this->cache->get( $this->getTalkNotificationCacheKey() ) === '0' ) {
			return;
		}

		// Do nothing if the count display meets the max 99+
		if ( $this->notifCountHasReachedMax() ) {
			return;
		}

		// Mark the talk page notification as read
		$this->markRead(
			$this->userNotifGateway->getUnreadNotifications(
				'edit-user-talk'
			)
		);

		$this->flagCacheWithNoTalkNotification();
	}

	/**
	 * Flag the cache with new talk notification
	 */
	public function flagCacheWithNewTalkNotification() {
		$this->cache->set( $this->getTalkNotificationCacheKey(), '1', 86400 );
	}

	/**
	 * Flag the cache with no talk notification
	 */
	public function flagCacheWithNoTalkNotification() {
		$this->cache->set( $this->getTalkNotificationCacheKey(), '0', 86400 );
	}

	/**
	 * Memcache key for talk notification
	 */
	public function getTalkNotificationCacheKey() {
		global $wgEchoConfig;

		return wfMemcKey( 'echo-new-talk-notification', $this->mUser->getId(), $wgEchoConfig['version'] );
	}

	/**
	 * Check if the user has more notification count than max count display
	 * @return bool
	 */
	public function notifCountHasReachedMax() {
		if ( $this->getNotificationCount() >= self::MAX_BADGE_COUNT ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get message count for this user.
	 *
	 * @param boolean $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_SLAVE)
	 * @return int
	 */
	public function getMessageCount( $cached = true, $dbSource = DB_SLAVE ) {
		$count = $this->getNotificationCount( $cached, $dbSource, EchoAttributeManager::MESSAGE );
		$count += $this->foreignNotifications->getCount( EchoAttributeManager::MESSAGE );

		return $count;
	}

	/**
	 * Get alert count for this user.
	 *
	 * @param boolean $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_SLAVE)
	 * @return int
	 */
	public function getAlertCount( $cached = true, $dbSource = DB_SLAVE ) {
		$count = $this->getNotificationCount( $cached, $dbSource, EchoAttributeManager::ALERT );
		$count += $this->foreignNotifications->getCount( EchoAttributeManager::ALERT );

		return $count;
	}

	/**
	 * Retrieves number of unread notifications that a user has, would return
	 * MWEchoNotifUser::MAX_BADGE_COUNT + 1 at most
	 *
	 * @param boolean $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_SLAVE)
	 * @param string $section Notification section
	 * @return int
	 */
	public function getNotificationCount( $cached = true, $dbSource = DB_SLAVE, $section = EchoAttributeManager::ALL ) {
		global $wgEchoConfig;

		if ( $this->mUser->isAnon() ) {
			return 0;
		}

		$memcKey = wfMemcKey(
			'echo-notification-count' . ( $section === EchoAttributeManager::ALL ? '' : ( '-' . $section ) ),
			$this->mUser->getId(),
			$wgEchoConfig['version']
		);
		if ( $cached ) {
			$data = $this->cache->get( $memcKey );
			if ( $data !== false && $data !== null ) {
				return (int)$data;
			}
		}

		$attributeManager = EchoAttributeManager::newFromGlobalVars();
		if ( $section === EchoAttributeManager::ALL ) {
			$eventTypesToLoad = $attributeManager->getUserEnabledEvents( $this->mUser, 'web' );
		} else {
			$eventTypesToLoad = $attributeManager->getUserEnabledEventsbySections( $this->mUser, 'web', array( $section ) );
		}

		$count = (int) $this->userNotifGateway->getCappedNotificationCount( $dbSource, $eventTypesToLoad, MWEchoNotifUser::MAX_BADGE_COUNT + 1 );
		$this->cache->set( $memcKey, $count, 86400 );

		return $count;
	}

	/**
	 * Get the unread timestamp of the latest alert
	 *
	 * @param boolean $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_SLAVE)
	 * @return bool|MWTimestamp
	 */
	public function getLastUnreadAlertTime( $cached = true, $dbSource = DB_SLAVE ) {
		$time = $this->getLastUnreadNotificationTime( $cached, $dbSource, EchoAttributeManager::ALERT );

		$foreignTime = $this->foreignNotifications->getTimestamp( EchoAttributeManager::ALERT );
		if ( $foreignTime !== false ) {
			$max = max( $time ? $time->getTimestamp( TS_MW ) : 0, $foreignTime->getTimestamp( TS_MW ) );
			$time = new MWTimestamp( $max );
		}

		return $time;
	}

	/**
	 * Get the unread timestamp of the latest message
	 *
	 * @param boolean $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_SLAVE)
	 * @return bool|MWTimestamp
	 */
	public function getLastUnreadMessageTime( $cached = true, $dbSource = DB_SLAVE ) {
		$time = $this->getLastUnreadNotificationTime( $cached, $dbSource, EchoAttributeManager::MESSAGE );

		$foreignTime = $this->foreignNotifications->getTimestamp( EchoAttributeManager::MESSAGE );
		if ( $foreignTime !== false ) {
			$max = max( $time ? $time->getTimestamp( TS_MW ) : 0, $foreignTime->getTimestamp( TS_MW ) );
			$time = new MWTimestamp( $max );
		}

		return $time;
	}

	/**
	 * Returns the timestamp of the last unread notification.
	 *
	 * @param boolean $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_SLAVE)
	 * @param string $section Notification section
	 * @return bool|MWTimestamp Timestamp of last notification, or false if there is none
	 */
	public function getLastUnreadNotificationTime( $cached = true, $dbSource = DB_SLAVE, $section = EchoAttributeManager::ALL ) {
		global $wgEchoConfig;

		if ( $this->mUser->isAnon() ) {
			return false;
		}

		$memcKey = wfMemcKey(
			'echo-notification-timestamp' . ( $section === EchoAttributeManager::ALL ? '' : ( '-' . $section ) ),
			$this->mUser->getId(),
			$wgEchoConfig['version']
		);

		// read from cache, if allowed
		if ( $cached ) {
			$timestamp = $this->cache->get( $memcKey );
			if ( $timestamp !== false ) {
				return new MWTimestamp( $timestamp );
			}
		}

		$attributeManager = EchoAttributeManager::newFromGlobalVars();
		if ( $section === EchoAttributeManager::ALL ) {
			$eventTypesToLoad = $attributeManager->getUserEnabledEvents( $this->mUser, 'web' );
		} else {
			$eventTypesToLoad = $attributeManager->getUserEnabledEventsbySections( $this->mUser, 'web', array( $section ) );
		}

		$notifications = $this->notifMapper->fetchUnreadByUser( $this->mUser, 1, null, $eventTypesToLoad, $dbSource );
		if ( $notifications ) {
			$notification = reset( $notifications );
			$timestamp = $notification->getTimestamp();

			// store to cache & return
			$this->cache->set( $memcKey, $timestamp, 86400 );

			return new MWTimestamp( $timestamp );
		}

		return false;
	}

	/**
	 * Mark one or more notifications read for a user.
	 * @param $eventIds Array of event IDs to mark read
	 * @return boolean
	 */
	public function markRead( $eventIds ) {
		$eventIds = array_filter( (array)$eventIds, 'is_numeric' );
		if ( !$eventIds || wfReadOnly() ) {
			return false;
		}

		$res = $this->userNotifGateway->markRead( $eventIds );
		if ( $res ) {
			// Delete records from echo_target_page
			$this->targetPageMapper->deleteByUserEvents( $this->mUser, $eventIds );
			// Update notification count in cache
			$this->resetNotificationCount( DB_MASTER );

			// After this 'mark read', is there any unread edit-user-talk
			// remaining?  If not, we should clear the newtalk flag.
			if ( $this->mUser->getNewtalk() ) {
				$unreadEditUserTalk = $this->notifMapper->fetchUnreadByUser( $this->mUser, 1, null, array( 'edit-user-talk' ), DB_MASTER );
				if ( count( $unreadEditUserTalk ) === 0 ) {
					$this->mUser->setNewtalk( false );
				}
			}
		}

		return $res;
	}

	/**
	 * Mark one or more notifications unread for a user.
	 * @param $eventIds Array of event IDs to mark unread
	 * @return boolean
	 */
	public function markUnRead( $eventIds ) {
		$eventIds = array_filter( (array)$eventIds, 'is_numeric' );
		if ( !$eventIds || wfReadOnly() ) {
			return false;
		}

		$res = $this->userNotifGateway->markUnRead( $eventIds );
		if ( $res ) {
			// Update notification count in cache
			$this->resetNotificationCount( DB_MASTER );

			// After this 'mark unread', is there any unread edit-user-talk?
			// If so, we should add the edit-user-talk flag
			if ( !$this->mUser->getNewtalk() ) {
				$unreadEditUserTalk = $this->notifMapper->fetchUnreadByUser( $this->mUser, 1, null, array( 'edit-user-talk' ), DB_MASTER );
				if ( count( $unreadEditUserTalk ) > 0 ) {
					$this->mUser->setNewtalk( true );
				}
			}
		}

		return $res;
	}

	/**
	 * Attempt to mark all or sections of notifications as read, this only
	 * updates up to $wgEchoMaxUpdateCount records per request, see more
	 * detail about this in Echo.php, the other reason is that mediawiki
	 * database interface doesn't support updateJoin() that would update
	 * across multiple tables, we would visit this later
	 *
	 * @param string[] $sections
	 * @return boolean
	 */
	public function markAllRead( array $sections = array( EchoAttributeManager::ALL ) ) {
		if ( wfReadOnly() ) {
			return false;
		}

		global $wgEchoMaxUpdateCount;

		// Mark all sections as read if this is the case
		if ( in_array( EchoAttributeManager::ALL, $sections ) ) {
			$sections = EchoAttributeManager::$sections;
		}

		$attributeManager = EchoAttributeManager::newFromGlobalVars();
		$eventTypes = $attributeManager->getUserEnabledEventsbySections( $this->mUser, 'web', $sections );

		$notifs = $this->notifMapper->fetchUnreadByUser( $this->mUser, $wgEchoMaxUpdateCount, null, $eventTypes );

		$eventIds = array_filter(
			array_map( function ( EchoNotification $notif ) {
				// This should not happen at all, but use 0 in
				// such case so to keep the code running
				if ( $notif->getEvent() ) {
					return $notif->getEvent()->getId();
				} else {
					return 0;
				}
			}, $notifs )
		);

		$res = $this->markRead( $eventIds );
		if ( $res ) {
			// Delete records from echo_target_page
			$this->targetPageMapper->deleteByUserEvents( $this->mUser, $eventIds );
			if ( count( $notifs ) < $wgEchoMaxUpdateCount ) {
				$this->flagCacheWithNoTalkNotification();
			}
		}

		return $res;
	}

	/**
	 * Recalculates the number of notifications that a user has.
	 * @param $dbSource int use master or slave database to pull count
	 */
	public function resetNotificationCount( $dbSource = DB_SLAVE ) {
		// Reset notification count for all sections as well
		$this->getNotificationCount( false, $dbSource, EchoAttributeManager::ALL );
		$alertCount = $this->getNotificationCount( false, $dbSource, EchoAttributeManager::ALERT );
		$msgCount = $this->getNotificationCount( false, $dbSource, EchoAttributeManager::MESSAGE );

		$user = $this->mUser;
		// when notification count needs to be updated, last notification may have
		// changed too, so we need to invalidate that cache too
		$this->getLastUnreadNotificationTime( false, $dbSource, EchoAttributeManager::ALL );
		$alertUnread = $this->getLastUnreadNotificationTime( false, $dbSource, EchoAttributeManager::ALERT );
		$msgUnread = $this->getLastUnreadNotificationTime( false, $dbSource, EchoAttributeManager::MESSAGE );
		$this->mUser->invalidateCache();
		DeferredUpdates::addCallableUpdate( function () use ( $user, $alertCount, $alertUnread, $msgCount, $msgUnread ) {
			$uw = EchoUnreadWikis::newFromUser( $user );
			if ( $uw ) {
				$uw->updateCount( wfWikiID(), $alertCount, $alertUnread, $msgCount, $msgUnread );
			}
		} );
	}

	/**
	 * Get the user's email notification format
	 * @return string
	 */
	public function getEmailFormat() {
		global $wgAllowHTMLEmail;

		if ( $wgAllowHTMLEmail ) {
			return $this->mUser->getOption( 'echo-email-format' );
		} else {
			return EchoHooks::EMAIL_FORMAT_PLAIN_TEXT;
		}
	}

}
