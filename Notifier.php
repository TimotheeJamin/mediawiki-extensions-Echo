<?php

// @todo Fill in
class EchoNotifier {
	/**
	 * Record an EchoNotification for an EchoEvent.
	 *
	 * @param $user The User to notify.
	 * @param $event The EchoEvent to notify about.
	 */
	public static function notifyWithNotification( $user, $event ) {
		EchoNotification::create( array( 'user' => $user, 'event' => $event) );
	}

	/**
	 * Send a Notification to a user by email
	 *
	 * @param $user The User to notify.
	 * @param $event The EchoEvent to notify about.
	 */
	public static function notifyWithEmail( $user, $event ) {
		if ( ! $user->isEmailConfirmed() ) {
			// No valid email address
			return false;
		}

		global $wgPasswordSender, $wgPasswordSenderName;

		$adminAddress = new MailAddress( $wgPasswordSender, $wgPasswordSenderName );
		$address = new MailAddress( $user );
		$email = EchoNotificationController::formatNotification( $event, $user, 'email' );
		$subject = $email['subject'];
		$body = $email['body'];

		UserMailer::send($address, $adminAddress, $subject, $body );
	}
}