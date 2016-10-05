<?php

use MediaWiki\MediaWikiServices;

abstract class EchoDiscussionParser {
	const HEADER_REGEX = '^(==+)\s*([^=].*)\s*\1$';

	static protected $timestampRegex;
	static protected $revisionInterpretationCache = array();
	static protected $diffParser;

	/**
	 * Given a Revision object, generates EchoEvent objects for
	 * the discussion-related actions that occurred in that Revision.
	 *
	 * @param Revision $revision
	 * @return null
	 */
	static function generateEventsForRevision( Revision $revision ) {
		global $wgEchoMentionsOnMultipleSectionEdits;
		global $wgEchoMentionOnChanges;

		// use slave database if there is a previous revision
		if ( $revision->getPrevious() ) {
			$title = Title::newFromID( $revision->getPage() );
		// use master database for new page
		} else {
			$title = Title::newFromID( $revision->getPage(), Title::GAID_FOR_UPDATE );
		}

		// not a valid title
		if ( !$title ) {
			return;
		}

		$interpretation = self::getChangeInterpretationForRevision( $revision );

		$userID = $revision->getUser();
		$userName = $revision->getUserText();
		$user = $userID != 0 ? User::newFromId( $userID ) : User::newFromName( $userName, false );

		foreach ( $interpretation as $action ) {
			if ( $action['type'] == 'add-comment' ) {
				$fullSection = $action['full-section'];
				$header = self::extractHeader( $fullSection );
				$userLinks = self::getUserLinks( $action['content'], $title );
				self::generateMentionEvents( $header, $userLinks, $action['content'], $revision, $user );
			} elseif ( $action['type'] == 'new-section-with-comment' ) {
				$content = $action['content'];
				$header = self::extractHeader( $content );
				$userLinks = self::getUserLinks( $content, $title );
				self::generateMentionEvents( $header, $userLinks, $content, $revision, $user );
			} elseif ( $action['type'] == 'add-section-multiple' && $wgEchoMentionsOnMultipleSectionEdits ) {
				$content = self::stripHeader( $action['content'] );
				$content = self::stripSignature( $content );
				$userLinks = self::getUserLinks( $content, $title );
				self::generateMentionEvents( $action['header'], $userLinks, $content, $revision, $user );
			} elseif ( $action['type'] === 'unknown-signed-change' ) {
				$userLinks = array_diff_key(
					self::getUserLinks( $action['new_content'], $title ) ?: [],
					self::getUserLinks( $action['old_content'], $title ) ?: []
				);
				$header = self::extractHeader( $action['full-section'] );

				if ( $wgEchoMentionOnChanges ) {
					self::generateMentionEvents( $header, $userLinks, $action['new_content'], $revision, $user );
				}
			}
		}

		if ( $title->getNamespace() == NS_USER_TALK ) {
			$notifyUser = User::newFromName( $title->getText() );
			// If the recipient is a valid non-anonymous user and hasn't turned
			// off their notifications, generate a talk page post Echo notification.
			if ( $notifyUser && $notifyUser->getId() ) {
				// If this is a minor edit, only notify if the agent doesn't have talk page minor
				// edit notification blocked
				if ( !$revision->isMinor() || !$user->isAllowed( 'nominornewtalk' ) ) {
					$section = self::detectSectionTitleAndText( $interpretation, $title );
					if ( $section['section-text'] === '' ) {
						$section['section-text'] = $revision->getComment();
					}
					EchoEvent::create( array(
						'type' => 'edit-user-talk',
						'title' => $title,
						'extra' => array(
							'revid' => $revision->getId(),
							'minoredit' => $revision->isMinor(),
							'section-title' => $section['section-title'],
							'section-text' => $section['section-text'],
							'target-page' => $title->getArticleID(),
						),
						'agent' => $user,
					) );
				}
			}
		}
	}

	/**
	 * Attempts to determine what section title the edit was performed under (if any)
	 *
	 * @param array $interpretation Results of self::getChangeInterpretationForRevision
	 * @return array Array containing section title and text
	 * @param Title $title
	 */
	public static function detectSectionTitleAndText( array $interpretation, Title $title = null ) {
		global $wgLang;
		$header = $snippet = '';
		$found = false;

		StubObject::unstub( $wgLang );

		foreach ( $interpretation as $action ) {
			switch ( $action['type'] ) {
				case 'add-comment':
					$header = self::extractHeader( $action['full-section'] );
					$snippet = self::getTextSnippet(
						self::stripSignature( self::stripHeader( $action['content'] ), $title ),
						$wgLang,
						150,
						$title );
					break;
				case 'new-section-with-comment':
					$header = self::extractHeader( $action['content'] );
					$snippet = self::getTextSnippet(
						self::stripSignature( self::stripHeader( $action['content'] ), $title ),
						$wgLang,
						150,
						$title );
					break;
			}
			if ( $header ) {
				// If we find a second header within the same change interpretation then
				// we cannot choose just 1 to link to
				if ( $found ) {
					$found = false;
					break;
				}
				$found = true;
			}
		}
		if ( $found === false ) {
			return array( 'section-title' => '', 'section-text' => '' );
		}

		return array( 'section-title' => $header, 'section-text' => $snippet );
	}

	/**
	 * For an action taken on a talk page, notify users whose user pages
	 * are linked.
	 * @param string $header The subject line for the discussion.
	 * @param array $userLinks
	 * @param string $content The content of the post, as a wikitext string.
	 * @param Revision $revision
	 * @param User $agent The user who made the comment.
	 */
	public static function generateMentionEvents(
		$header,
		$userLinks,
		$content,
		Revision $revision,
		User $agent
	) {
		global $wgEchoMaxMentionsCount, $wgEchoMentionStatusNotifications;

		$title = $revision->getTitle();
		if ( !$title ) {
			return;
		}
		$content = self::stripHeader( $content );
		$content = self::stripSignature( $content, $title );

		if ( !$userLinks ) {
			return;
		}

		$userMentions = self::getUserMentions( $title, $revision->getUser( Revision::RAW ), $userLinks );
		$overallMentionsCount = self::getOverallUserMentionsCount( $userMentions );
		if ( $overallMentionsCount === 0 ) {
			return;
		}

		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();

		if ( $overallMentionsCount > $wgEchoMaxMentionsCount ) {
			if ( $wgEchoMentionStatusNotifications ) {
				EchoEvent::create( array(
					'type' => 'mention-failure-too-many',
					'title' => $title,
					'extra' => array(
						'max-mentions' => $wgEchoMaxMentionsCount,
						'section-title' => $header,
						'notifyAgent' => true
					),
					'agent' => $agent,
				) );
				$stats->increment( 'echo.event.mention.notification.failure-too-many' );
			}
			return;
		}

		if ( $userMentions['validMentions'] ) {
			EchoEvent::create( array(
				'type' => 'mention',
				'title' => $title,
				'extra' => array(
					'content' => $content,
					'section-title' => $header,
					'revid' => $revision->getId(),
					'mentioned-users' => $userMentions['validMentions'],
				),
				'agent' => $agent,
			) );
		}

		if ( $wgEchoMentionStatusNotifications ) {
			// TODO batch?
			foreach ( $userMentions['validMentions'] as $mentionedUserId ) {
				EchoEvent::create( array(
					'type' => 'mention-success',
					'title' => $title,
					'extra' => array(
						'subject-name' => User::newFromId( $mentionedUserId )->getName(),
						'section-title' => $header,
						'revid' => $revision->getId(),
						'notifyAgent' => true
					),
					'agent' => $agent,
				) );
				$stats->increment( 'echo.event.mention.notification.success' );
			}

			// TODO batch?
			foreach ( $userMentions['anonymousUsers'] as $anonymousUser ) {
				EchoEvent::create( array(
					'type' => 'mention-failure',
					'title' => $title,
					'extra' => array(
						'failure-type' => 'user-anonymous',
						'subject-name' => $anonymousUser,
						'section-title' => $header,
						'revid' => $revision->getId(),
						'notifyAgent' => true
					),
					'agent' => $agent,
				) );
				$stats->increment( 'echo.event.mention.notification.failure-user-anonymous' );
			}

			// TODO batch?
			foreach ( $userMentions['unknownUsers'] as $unknownUser ) {
				EchoEvent::create( array(
					'type' => 'mention-failure',
					'title' => $title,
					'extra' => array(
						'failure-type' => 'user-unknown',
						'subject-name' => $unknownUser,
						'section-title' => $header,
						'revid' => $revision->getId(),
						'notifyAgent' => true
					),
					'agent' => $agent,
				) );
				$stats->increment( 'echo.event.mention.notification.failure-user-unknown' );
			}
		}
	}

	private static function getOverallUserMentionsCount( $userMentions ) {
		return count( $userMentions, COUNT_RECURSIVE ) - count( $userMentions );
	}

	/**
	 * @return array[]
	 * Set of arrays containing valid mentions and possible intended but failed mentions.
	 * - [validMentions]: An array of valid users to mention with ID => ID.
	 * - [unknownUsers]: An array of DBKey strings representing unknown users.
	 * - [validMentions]: An array of DBKey strings representing anonymous IP users.
	 */
	private static function getUserMentions( Title $title, $revisionUserId, array $userLinks ) {
		global $wgEchoMaxMentionsCount;
		$userMentions = array(
			'validMentions' => array(),
			'unknownUsers' => array(),
			'anonymousUsers' => array(),
		);

		$count = 0;
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();

		foreach ( $userLinks as $dbk => $page_id ) {
			// If more users are being pinged this is likely a spam/attack vector
			// Don't send any mention notifications.
			if ( $count > $wgEchoMaxMentionsCount ) {
				$stats->increment( 'echo.event.mention.error.tooMany' );
				break;
			}

			// we should not add user to 'mention' notification list if
			// 1. the user link links to a subpage
			if ( self::hasSubpage( $dbk ) ) {
				continue;
			}

			// 2. user is an anonymous IP
			if ( User::isIP( $dbk ) ) {
				$userMentions['anonymousUsers'][] = $dbk;
				$count++;
				$stats->increment( 'echo.event.mention.error.anonUser' );
				continue;
			}

			$user = User::newFromName( $dbk );
			// 3. the user name is not valid
			if ( !$user ) {
				$userMentions['unknownUsers'][] = str_replace( '_', ' ', $dbk );
				$count++;
				$stats->increment( 'echo.event.mention.error.invalidUser' );
				continue;
			}

			// 4. the user mentions themselves
			if ( $user->getId() === $revisionUserId ) {
				$stats->increment( 'echo.event.mention.error.sameUser' );
				continue;
			}

			// 5. the user is the owner of the talk page
			if ( $title->getNamespace() === NS_USER_TALK && $title->getDBkey() === $dbk ) {
				$stats->increment( 'echo.event.mention.error.ownPage' );
				continue;
			}

			// 6. user does not exist
			if ( $user->getId() === 0 ) {
				$userMentions['unknownUsers'][] = str_replace( '_', ' ', $dbk );
				$count++;
				$stats->increment( 'echo.event.mention.error.unknownUser' );
				continue;
			}

			$userMentions['validMentions'][$user->getId()] = $user->getId();
			$count++;
		}

		return $userMentions;
	}

	/**
	 * @return bool|array
	 * Array of links in the user namespace with DBKey => ID.
	 */
	private static function getUserLinks( $content, Title $title ) {
		$output = self::parseNonEditWikitext( $content, new Article( $title ) );
		$links = $output->getLinks();

		if ( !isset( $links[NS_USER] ) || !is_array( $links[NS_USER] ) ) {
			return false;
		}

		return $links[NS_USER];
	}

	private static function hasSubpage( $dbk ) {
		return strpos( $dbk, '/' ) !== false;
	}

	/**
	 * It's like Article::prepareTextForEdit,
	 *  but not for editing (old wikitext usually)
	 * Stolen from AbuseFilterVariableHolder
	 *
	 * @param string $wikitext
	 * @param Article $article
	 *
	 * @return ParserOutput
	 */
	static function parseNonEditWikitext( $wikitext, Article $article ) {
		static $cache = array();

		$cacheKey = md5( $wikitext ) . ':' . $article->getTitle()->getPrefixedText();

		if ( isset( $cache[$cacheKey] ) ) {
			return $cache[$cacheKey];
		}

		global $wgParser;
		$options = new ParserOptions;
		$options->setTidy( true );
		$output = $wgParser->parse( $wikitext, $article->getTitle(), $options );
		$cache[$cacheKey] = $output;

		return $output;
	}

	/**
	 * Given a Revision object, returns a talk-page-centric interpretation
	 * of the changes made in it.
	 *
	 * @param Revision $revision
	 * @see EchoDiscussionParser::interpretDiff
	 * @return array see interpretDiff for details.
	 */
	static function getChangeInterpretationForRevision( Revision $revision ) {
		if ( $revision->getId() && isset( self::$revisionInterpretationCache[$revision->getId()] ) ) {
			return self::$revisionInterpretationCache[$revision->getId()];
		}

		$userID = $revision->getUser();
		$userName = $revision->getUserText();
		$user = $userID != 0 ? User::newFromId( $userID ) : User::newFromName( $userName, false );
		$prevText = '';
		if ( $revision->getParentId() ) {
			$prevRevision = Revision::newFromId( $revision->getParentId() );
			if ( $prevRevision ) {
				$prevText = ContentHandler::getContentText( $prevRevision->getContent() );
			}
		}

		$changes = self::getMachineReadableDiff(
			$prevText,
			ContentHandler::getContentText( $revision->getContent() )
		);
		$output = self::interpretDiff( $changes, $user->getName(), $revision->getTitle() );

		self::$revisionInterpretationCache[$revision->getId()] = $output;

		return $output;
	}

	/**
	 * Given a machine-readable diff, interprets the changes
	 * in terms of discussion page actions
	 *
	 * @todo Expand recognisable actions.
	 *
	 * @param array $changes Output of EchoEvent::getMachineReadableDiff
	 * @param string $username Username
	 * @param Title $title
	 * @return array[] Array of associative arrays.
	 *
	 * Each entry represents an action, which is classified in the 'action' field.
	 * All types contain a 'content' field except 'unknown'
	 *  (which instead passes through the machine-readable diff in 'details')
	 *  and 'unknown-change' (which provides 'new_content' and 'old_content')
	 * action may be:
	 * - add-comment: A comment signed by the user is added to an
	 *    existing section.
	 * - new-section-with-comment: A new section is added, containing
	 *    a single comment signed by the user in question.
	 * - add-section-multiple: A new section or additions to a section
	 *    while editing multiple sections at once.
	 * - unknown-multi-signed-addition: Some signed content is added,
	 *    but it contains multiple signatures.
	 * - unknown-unsigned-addition: Some content is added, but it is
	 *    unsigned.
	 * - unknown-subtraction: Some content was removed. These actions are
	 *    not currently analysed.
	 * - unknown-change: Some content was replaced with other content.
	 * - unknown-signed-change: Same as unknown-change, but signed.
	 * - unknown: Unrecognised change type.
	 */
	static function interpretDiff( $changes, $username, Title $title = null ) {
		// One extra item in $changes for _info
		$actions = array();
		$signedSections = array();

		foreach ( $changes as $index => $change ) {
			if ( !is_numeric( $index ) ) {
				continue;
			}

			if ( !$change['action'] ) {
				// Unknown action; skip
				continue;
			}

			if ( $change['action'] == 'add' ) {
				$content = trim( $change['content'] );
				// The \A means the regex must match at the beginning of the string.
				// This is slightly different than ^ which matches beginning of each
				// line in multiline mode.
				$startSection = preg_match( "/\A" . self::HEADER_REGEX . '/um', $content );
				$sectionCount = self::getSectionCount( $content );
				$signedUsers = array_keys( self::extractSignatures( $content, $title ) );

				if (
					count( $signedUsers ) == 1 &&
					in_array( $username, $signedUsers )
				) {
					if ( $sectionCount === 0 ) {
						$signedSections[] = self::getSectionSpan( $change['right-pos'], $changes['_info']['rhs'] );
						$fullSection = self::getFullSection( $changes['_info']['rhs'], $change['right-pos'] );
						$actions[] = array(
							'type' => 'add-comment',
							'content' => $content,
							'full-section' => $fullSection,
						);
					} elseif ( $startSection && $sectionCount === 1 ) {
						$signedSections[] = self::getSectionSpan( $change['right-pos'], $changes['_info']['rhs'] );
						$actions[] = array(
							'type' => 'new-section-with-comment',
							'content' => $content,
						);
					} else {
						$nextSectionStart = $change['right-pos'];
						$sectionData = self::extractSections( $content );
						foreach ( $sectionData as $section ) {
							$sectionSpan = self::getSectionSpan( $nextSectionStart, $changes['_info']['rhs'] );
							$nextSectionStart = $sectionSpan[1] + 1;
							$sectionSignedUsers = self::extractSignatures( $section['content'], $title );
							if ( !empty( $sectionSignedUsers ) ) {
								$signedSections[] = $sectionSpan;
								if ( !$section['header'] ) {
									$fullSection = self::getFullSection( $changes['_info']['rhs'], $change['right-pos'] );
									$section['header'] = self::extractHeader( $fullSection );
								}
								$actions[] = array(
									'type' => 'add-section-multiple',
									'content' => $section['content'],
									'header' => $section['header'],
								);
							} else {
								$actions[] = array(
									'type' => 'unknown-unsigned-addition',
									'content' => $section['content'],
								);
							}
						}
					}
				} elseif ( count( $signedUsers ) >= 1 ) {
					$actions[] = array(
						'type' => 'unknown-multi-signed-addition',
						'content' => $content,
					);
				} else {
					$actions[] = array(
						'type' => 'unknown-unsigned-addition',
						'content' => $content,
					);
				}
			} elseif ( $change['action'] == 'subtract' ) {
				$actions[] = array(
					'type' => 'unknown-subtraction',
					'content' => $change['content'],
				);
			} elseif ( $change['action'] == 'change' ) {
				$actions[] = array(
					'type' => 'unknown-change',
					'old_content' => $change['old_content'],
					'new_content' => $change['new_content'],
					'right-pos' => $change['right-pos'],
					'full-section' => self::getFullSection( $changes['_info']['rhs'], $change['right-pos'] ),
				);

				if ( self::hasNewSignature(
					$change['old_content'],
					$change['new_content'],
					$username,
					$title
				) ) {
					$signedSections[] = self::getSectionSpan( $change['right-pos'], $changes['_info']['rhs'] );
				}
			} else {
				$actions[] = array(
					'type' => 'unknown',
					'details' => $change,
				);
			}
		}

		if ( !empty( $signedSections ) ) {
			$actions = self::convertToUnknownSignedChanges( $signedSections, $actions );
		}

		return $actions;
	}

	static function getSignedUsers( $content, $title ) {
		return array_keys( self::extractSignatures( $content, $title ) );
	}

	static function hasNewSignature( $oldContent, $newContent, $username, $title ) {
		$oldSignedUsers = self::getSignedUsers( $oldContent, $title );
		$newSignedUsers = self::getSignedUsers( $newContent, $title );

		return !in_array( $username, $oldSignedUsers ) && in_array( $username, $newSignedUsers );
	}

	/**
	 * Converts actions of type "unknown-change" to "unknown-signed-change" if the change is in a signed section.
	 *
	 * @param array $signedSections Array of arrays containing first and last line number of signed sections
	 * @param array $actions
	 * @return array converted actions
	 */
	static function convertToUnknownSignedChanges( $signedSections, $actions ) {
		return array_map( function( $action ) use( $signedSections ) {
			if (
				$action['type'] === 'unknown-change' &&
				self::isInSignedSection( $action['right-pos'], $signedSections )
			) {
				$action['type'] = 'unknown-signed-change';
			}

			return $action;
		}, $actions );
	}

	static function isInSignedSection( $line, $signedSections ) {
		foreach ( $signedSections as $section ) {
			if ( $line > $section[0] && $line <= $section[1] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Finds the section that a given line is in.
	 *
	 * @param array $lines of lines in the page.
	 * @param int $offset The line to find the full section for.
	 * @return string Content of the section.
	 */
	static function getFullSection( $lines, $offset ) {
		$start = self::getSectionStartIndex( $offset, $lines );
		$end = self::getSectionEndIndex( $offset, $lines );
		$content = implode( "\n", array_slice( $lines, $start, $end - $start ) );

		return trim( $content, "\n" );
	}

	/**
	 * Given a line number and a text, find the first and last line of the section the line number is in.
	 * If there are subsections, the last line index will be the line before the beginning of the first subsection.
	 * @param $offset line number
	 * @param $lines
	 * @return array tuple [$firstLine, $lastLine]
	 */
	static function getSectionSpan( $offset, $lines ) {
		return array(
			self::getSectionStartIndex( $offset, $lines ),
			self::getSectionEndIndex( $offset, $lines )
		);
	}

	/**
	 * Finds the line number of the start of the section that $offset is in.
	 * @param $offset
	 * @param $lines
	 * @return int
	 */
	static function getSectionStartIndex( $offset, $lines ) {
		for ( $i = $offset - 1; $i >= 0; $i-- ) {
			if ( self::getSectionCount( $lines[$i] ) ) {
				break;
			}
		}

		return $i;
	}

	/**
	 * Finds the line number of the end of the section that $offset is in.
	 * @param $offset
	 * @param $lines
	 * @return int
	 */
	static function getSectionEndIndex( $offset, $lines ) {
		$lastLine = count( $lines );
		for ( $i = $offset; $i < $lastLine; $i++ ) {
			if ( self::getSectionCount( $lines[$i] ) ) {
				break;
			}
		}

		return $i;
	}

	/**
	 * Gets the number of section headers in a string.
	 *
	 * @param string $text The text.
	 * @return int Number of section headers found.
	 */
	static function getSectionCount( $text ) {
		$text = trim( $text );

		$matches = array();
		preg_match_all( '/' . self::HEADER_REGEX . '/um', $text, $matches );

		return count( $matches[0] );
	}

	/**
	 * Gets the title of a section or sub section
	 *
	 * @param string $text The text of the section.
	 * @return string|false The title of the section or false if not found
	 */
	static function extractHeader( $text ) {
		$text = trim( $text );

		$matches = array();

		if ( !preg_match_all( '/' . self::HEADER_REGEX . '/um', $text, $matches ) ) {
			return false;
		}

		return trim( end( $matches[2] ) );
	}

	/**
	 * Extracts sections and their contents from text.
	 *
	 * @param string $text The text to parse.
	 * @return array[]
	 * Array of arrays containing sections with header and content.
	 * - [header]: The full header string of the section or false if there is preceding text without header.
	 * - [content]: The content of the section including the header string.
	 */
	private static function extractSections( $text ) {
		$matches = array();

		if ( !preg_match_all( '/' . self::HEADER_REGEX . '/um', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array( array(
				'header' => false,
				'content' => $text
			) );
		}

		$sectionNum = count( $matches[0] );
		$sections = array();

		if ( $matches[0][0][1] > 1 ) { // is there text before the first headline?
			$sections[] = array(
				'header' => false,
				'content' =>  substr( $text, 0, $matches[0][0][1] - 1 )
			);
		}
		for ( $i = 0; $i < $sectionNum; $i++ ) {
			if ( $i + 1 < $sectionNum ) {
				$content = substr( $text, $matches[0][$i][1], $matches[0][$i + 1][1] - $matches[0][$i][1] );
			} else {
				$content = substr( $text, $matches[0][$i][1] );
			}
			$sections[] = array(
				'header' => self::extractHeader( $matches[0][$i][0] ),
				'content' =>  trim( $content )
			);
		}

		return $sections;
	}

	/**
	 * Strips out a signature if possible.
	 *
	 * @param string $text The wikitext to strip
	 * @param Title $title
	 * @return string
	 */
	static function stripSignature( $text, Title $title = null ) {
		$output = self::getUserFromLine( $text, $title );
		if ( $output === false ) {
			$timestampPos = self::getTimestampPosition( $text );

			return substr( $text, 0, $timestampPos );
		}

		// Use truncate() instead of truncateHTML() because truncateHTML()
		// would not strip signature if the text contains < or &
		global $wgContLang;

		return $wgContLang->truncate( $text, $output[0], '' );
	}

	/**
	 * Strips unnecessary indentation and so on from comments
	 *
	 * @param string $text The text to strip from
	 * @return string Stripped wikitext
	 */
	static function stripIndents( $text ) {
		// First strip all indentation from the beginning of lines
		$text = preg_replace( '/^\s*\:+/m', '', $text );

		// Now if there is only one list item, strip that too
		$listRegex = '/^\s*(?:[\:#*]\s*)*[#*]/m';
		$matches = array();
		if ( preg_match_all( $listRegex, $text, $matches ) ) {
			if ( count( $matches ) == 1 ) {
				$text = preg_replace( $listRegex, '', $text );
			}
		}

		return $text;
	}

	/**
	 * Strips out a section header
	 * @param string $text The text to strip out the section header from.
	 * @return string The same text, with the section header stripped out.
	 */
	static function stripHeader( $text ) {
		$text = preg_replace( '/' . self::HEADER_REGEX . '/um', '', $text );

		return $text;
	}

	/**
	 * Determines whether the input is a signed comment.
	 *
	 * @param string $text The text to check.
	 * @param User|bool $user If set, will only return true if the comment is
	 *  signed by this user.
	 * @param Title $title
	 * @return bool
	 */
	static function isSignedComment( $text, $user = false, Title $title = null ) {
		$userData = self::getUserFromLine( $text, $title );

		if ( $userData === false ) {
			return false;
		} elseif ( $user === false ) {
			return true;
		}

		list( , $foundUser ) = $userData;

		return User::getCanonicalName( $foundUser, false ) === User::getCanonicalName( $user, false );
	}

	/**
	 * Finds the start position, if any, of the timestamp on a line
	 *
	 * @param string $line The line to search for a signature on
	 * @return int|bool Integer position
	 */
	static function getTimestampPosition( $line ) {
		$timestampRegex = self::getTimestampRegex();
		$endOfLine = self::getLineEndingRegex();
		$tsMatches = array();
		if ( !preg_match(
			"/$timestampRegex$endOfLine/mu",
			$line,
			$tsMatches,
			PREG_OFFSET_CAPTURE
		) ) {
			return false;
		}

		return $tsMatches[0][1];
	}

	/**
	 * Finds differences between $oldText and $newText
	 * and returns the result in a machine-readable format.
	 *
	 * @param string $oldText The "left hand side" of the diff.
	 * @param string $newText The "right hand side" of the diff.
	 * @throws MWException
	 * @return array of changes.
	 * Each change consists of:
	 * * An 'action', one of:
	 *   - add
	 *   - subtract
	 *   - change
	 * * 'content' that was added or removed, or in the case
	 *    of a change, 'old_content' and 'new_content'
	 * * 'left_pos' and 'right_pos' (in lines) of the change.
	 */
	static function getMachineReadableDiff( $oldText, $newText ) {
		if ( !isset( self::$diffParser ) ) {
			self::$diffParser = new EchoDiffParser;
		}

		return self::$diffParser->getChangeSet( $oldText, $newText );
	}

	/**
	 * Finds and extracts signatures in $text
	 *
	 * @param string $text The text in which to look for signed comments.
	 * @param Title $title
	 * @return array Associative array, the key is the username, the value
	 *  is the last signature that was found.
	 */
	static function extractSignatures( $text, Title $title = null ) {
		$lines = explode( "\n", $text );

		$output = array();

		$lineNumber = 0;

		foreach ( $lines as $line ) {
			++$lineNumber;

			// Look for the last user link on the line.
			$userData = self::getUserFromLine( $line, $title );
			if ( $userData === false ) {
				// print "F\t$lineNumber\t$line\n";
				continue;
			} else {
				// print "S\t$lineNumber\n";
			}

			list( $signaturePos, $user ) = $userData;

			$signature = substr( $line, $signaturePos );
			$output[$user] = $signature;
		}

		return $output;
	}

	/**
	 * From a line in the signature, extract all the users linked to
	 *
	 * @param string $line Line of text potentially including linked user, user talk,
	 *  and contribution pages
	 * @return array of usernames, empty array for none detected
	 */
	public static function extractUsersFromLine( $line ) {
		/*
		 * Signatures can look like anything (as defined by i18n messages
		 * "signature" & "signature-anon").
		 * A signature can, e.g., be both a link to user & user-talk page.
		 */
		// match all title-like excerpts in this line
		if ( !preg_match_all( '/\[\[([^\[]+)\]\]/', $line, $matches ) ) {
			return array();
		}

		$matches = $matches[1];

		$usernames = array();

		foreach ( $matches as $match ) {
			/*
			 * Create an object out of the link title.
			 * In theory, links can be [[text]], [[text|text]] or pipe tricks
			 * [[text|]] or [[|text]].
			 * In the case of reverse pipe trick, the value we use *could* be
			 * empty, but Parser::pstPass2 should have normalized that for us
			 * already.
			 */
			$match = explode( '|', $match );
			$title = Title::newFromText( $match[0] );

			// figure out if we the link is related to a user
			if (
				$title &&
				( $title->getNamespace() === NS_USER || $title->getNamespace() === NS_USER_TALK )
			) {
				$usernames[] = $title->getText();
			} elseif ( $title && $title->isSpecial( 'Contributions' ) ) {
				$parts = explode( '/', $title->getText(), 2 );
				$usernames[] = end( $parts );
			} else {
				// move on to next matched title-like excerpt
				continue;
			}
		}

		return $usernames;
	}

	/**
	 * From a line in a wiki page, determine which user, if any,
	 *  has signed it.
	 *
	 * @param string $line The line.
	 * @param Title $title
	 * @return bool|array false for none, Array for success.
	 * - First element is the position of the signature.
	 * - Second element is the normalised user name.
	 */
	public static function getUserFromLine( $line, Title $title = null ) {
		global $wgParser;

		/*
		 * First we call extractUsersFromLine to get all the potential usernames
		 * from the line.  Then, we loop backwards through them, figure out which
		 * match to a user, regenera the signature based on that user, and
		 * see if it matches!
		 */
		$usernames = self::extractUsersFromLine( $line );
		$usernames = array_reverse( $usernames );
		foreach ( $usernames as $username ) {
			// generate (dateless) signature from the user we think we've
			// discovered the signature from
			// don't validate the username - anon (IP) is fine!
			$user = User::newFromName( $username, false );
			$sig = $wgParser->preSaveTransform(
				'~~~',
				$title ?: Title::newMainPage(),
				$user,
				new ParserOptions()
			);

			// see if we can find this user's generated signature in the content
			$pos = strrpos( $line, $sig );
			if ( $pos !== false ) {
				return array( $pos, $username );
			}
			// couldn't find sig, move on to next link excerpt and try there
		}

		// couldn't find any matching signature
		return false;
	}

	/**
	 * Find the last link beginning with a given prefix on a line.
	 *
	 * @param string $line The line to search.
	 * @param string $linkPrefix The prefix to search for.
	 * @param bool $failureOffset
	 * @return array|bool false for failure, array for success.
	 * - First element is the string offset of the link.
	 * - Second element is the user the link refers to.
	 */
	static function getLinkFromLine( $line, $linkPrefix, $failureOffset = false ) {
		$offset = 0;

		// If extraction failed at another offset, try again.
		if ( $failureOffset !== false ) {
			$offset = $failureOffset - strlen( $line ) - 1;
		}

		// Avoid PHP warning: Offset is greater than the length of haystack string
		if ( abs( $offset ) > strlen( $line ) ) {
			return false;
		}

		$linkPos = strripos( $line, $linkPrefix, $offset );

		if ( $linkPos === false ) {
			// print "I\tNo match for $linkPrefix\n";
			return false;
		}

		$linkUser = self::extractUserFromLink( $line, $linkPrefix, $linkPos );

		if ( $linkUser === false ) {
			// print "E\tExtraction failed\t$linkPrefix\n";
			// Look for another place.
			return self::getLinkFromLine( $line, $linkPrefix, $linkPos );
		} else {
			return array( $linkPos, $linkUser );
		}
	}

	/**
	 * Given text including a link, gives the user that that link refers to
	 *
	 * @param string $text The text to extract from.
	 * @param string $prefix The link prefix that was used to find the link.
	 * @param int $offset Optionally, the offset of the start of the link.
	 * @return bool|string Type description
	 */
	static function extractUserFromLink( $text, $prefix, $offset = 0 ) {
		$userPart = substr( $text, strlen( $prefix ) + $offset );

		$userMatches = array();
		if ( !preg_match(
			'/^[^\|\]\#]+/u',
			$userPart,
			$userMatches
		) ) {
			// user link is invalid
			// print "I\tUser link invalid\t$userPart\n";
			// print "E\tCannot find user info to extract\n";
			return false;
		}

		$user = $userMatches[0];

		if (
			!User::isIP( $user ) &&
			User::getCanonicalName( $user ) === false
		) {
			// Not a real username
			// print "E\tInvalid username\n";
			return false;
		}

		return User::getCanonicalName( $userMatches[0], false );
	}

	/**
	 * Gets a regular expression fragmentmatching characters that
	 * can appear in a line after the signature.
	 *
	 * @return string regular expression fragment.
	 */
	static function getLineEndingRegex() {
		$ignoredEndings = array(
			'\s*',
			preg_quote( '}' ),
			preg_quote( '{' ),
			'\<[^\>]+\>',
			preg_quote( '{{' ) . '[^}]+' . preg_quote( '}}' ),
		);

		$regex = '(?:' . implode( '|', $ignoredEndings ) . ')*';

		return $regex;
	}

	/**
	 * Gets a regular expression that will match this wiki's
	 * timestamps as given by ~~~~.
	 *
	 * @throws MWException
	 * @return string regular expression fragment.
	 */
	static function getTimestampRegex() {
		if ( self::$timestampRegex !== null ) {
			return self::$timestampRegex;
		}

		// Step 1: Get an exemplar timestamp
		$title = Title::newMainPage();
		$user = User::newFromName( 'Test' );
		$options = new ParserOptions;

		global $wgParser;
		$exemplarTimestamp =
			$wgParser->preSaveTransform( '~~~~~', $title, $user, $options );

		// Step 2: Generalise it
		// Trim off the timezone to replace at the end
		$output = $exemplarTimestamp;
		$tzRegex = '/\s*\(\w+\)\s*$/';
		$tzMatches = array();
		if ( preg_match( $tzRegex, $output, $tzMatches ) ) {
			$output = preg_replace( $tzRegex, '', $output );
		}
		$output = preg_quote( $output, '/' );
		$output = preg_replace( '/[^\d\W]+/u', '[^\d\W]+', $output );
		$output = preg_replace( '/\d+/u', '\d+', $output );

		if ( $tzMatches ) {
			$output .= preg_quote( $tzMatches[0] );
		}

		if ( !preg_match( "/$output/u", $exemplarTimestamp ) ) {
			throw new MWException( "Timestamp regex does not match exemplar" );
		}

		self::$timestampRegex = $output;

		return $output;
	}

	/**
	 * Parse wikitext into truncated plain text.
	 * @param string $text
	 * @param Language $lang
	 * @param int $length default 150
	 * @param Title|null $title Page from which the text snippet is being extracted
	 * @return string
	 */
	static function getTextSnippet( $text, Language $lang, $length = 150, $title = null ) {
		// Parse wikitext
		$html = MessageCache::singleton()->parse( $text, $title )->getText();
		$plaintext = self::htmlToText( $html );
		return $lang->truncate( $plaintext, $length );
	}

	/**
	 * Parse an edit summary into truncated plain text.
	 * @param string $text
	 * @param Language $lang
	 * @param int $length default 150
	 * @return string
	 */
	static function getTextSnippetFromSummary( $text, Language $lang, $length = 150 ) {
		// Parse wikitext with summary parser
		$html = Linker::formatLinksInComment( Sanitizer::escapeHtmlAllowEntities( $text ) );
		$plaintext = self::htmlToText( $html );
		return $lang->truncate( $plaintext, $length );
	}

	/**
	 * @param string $html
	 * @return string text version of the given html string
	 */
	public static function htmlToText( $html ) {
		return trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES ) );
	}

	/**
	 * Extract an edit excerpt from a revision
	 *
	 * @param Revision $revision
	 * @param Language $lang
	 * @param int $length
	 * @return string
	 */
	public static function getEditExcerpt( Revision $revision, Language $lang, $length = 150 ) {
		$interpretation = self::getChangeInterpretationForRevision( $revision );
		$section = self::detectSectionTitleAndText( $interpretation );
		return $lang->truncate( $section['section-title'] . ' ' . $section['section-text'], $length );
	}
}
