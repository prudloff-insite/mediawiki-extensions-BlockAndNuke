<?php

class BanPests {

	static function getWhitelist() {
		global $wgBaNwhitelist, $wgWhitelist;

		/* Backward compatibility */
		if ( isset( $wgWhitelist ) && file_exists( $wgWhitelist ) ) {
			$wgBaNwhitelist = $wgWhitelist;
		} elseif ( !isset( $wgBaNwhitelist ) || !file_exists( $wgBaNwhitelist ) ) {
			throw new MWException(
				'You need to specify a whitelist!  $wgBaNwhitelist should point to a filename that contains the whitelist.'
			);
		}

		$fh = fopen( $wgBaNwhitelist, 'r' );
		$file = fread( $fh, 200 );
		fclose( $fh );
		return preg_split( '/\r\n|\r|\n/', $file );
	}

	static function getBannableUsers() {
		$dbr = wfGetDB( DB_SLAVE );
		$cond = array( 'rc_new' => 1 ); /* Anyone creating new pages */
		$cond[] = $dbr->makeList(		/* Anyone uploading stuff */
			array(
				'rc_log_type' => 'upload',
				'rc_log_action' => 'upload'
			),
			LIST_AND
		);
		$cond[] = $dbr->makeList( /* New Users older than a day who haven't done anything yet */
			array(
				'rc_log_action' => 'create',
				'rc_log_type' => 'newusers',
			),
			LIST_AND
		);
		$result = $dbr->select(
			'recentchanges',
			array( 'DISTINCT rc_user_text' ),
			$dbr->makeList( $cond, LIST_OR ),
			__METHOD__,
			array( 'ORDER BY' => 'rc_user_text ASC' )
		);
		$names = array();
		foreach( $result as $row ) {
			$names[] = $row->rc_user_text;
		}
		$whitelist = array_flip( self::getWhitelist() );
		return array_filter( $names,
			function( $u ) use ( $whitelist ) {
				return !isset( $whitelist[ $u ] );
			}
		);
	}

	static function getBannableIP( $user ) {
		$dbr = wfGetDB( DB_SLAVE );
		$ip = array();
		if( is_array( $user ) ) {
			foreach( $user as $u ) {
				if ( $u ) {
					$ip = array_merge( $ip, self::getBannableIP( User::newFromName( $u ) ) );
				}
			}
		} elseif ( is_object( $user ) ) {
			$result = $dbr->select(
				'recentchanges',
				array( 'DISTINCT rc_ip' ),
				array( 'rc_user_text' => $user->getName() ),
				__METHOD__,
				array( 'ORDER BY' => 'rc_ip ASC' )
			);
			foreach( $result as $row ) {
				$ip[] = $row->rc_ip;
			}
		} else {
			$ip[] = $user;
		}
		$whitelist = array_flip( self::getWhitelist() );
		return array_filter( $ip,
			function( $u ) use ( $whitelist ) {
				return !isset( $whitelist[ $u ] );
			}
		);
	}

	static function getBannablePages( $user ) {
		$dbr = wfGetDB( DB_SLAVE );
		$result = null;
		if( $user ) {
			$result = $dbr->select(
				'recentchanges',
				array( 'rc_namespace', 'rc_title', 'rc_timestamp', 'COUNT(*) AS edits' ),
				array(
					'rc_user_text' => $user,
					'(rc_new = 1) OR (rc_log_type = \'upload\' AND rc_log_action = \'upload\')'
				),
				__METHOD__,
				array(
					'ORDER BY' => 'rc_timestamp DESC',
					'GROUP BY' => 'rc_namespace, rc_title'
				)
			);
		}
		$pages = array();
		if( $result ) {
			foreach( $result as $row ) {
				$pages[] = Title::makeTitle( $row->rc_namespace, $row->rc_title );
			}
		}

		return $pages;
	}

	static function banIPs( $ips, $banningUser, $sp = null ) {
		$ret = array();
		foreach( (array)$ips as $ip ) {
			if( !Block::newFromTarget( $ip ) ) {
				$blk = new Block(
					array(
						'address'       => $ip,
						'by'            => $banningUser->getID(),
						'reason'        => wfMessage( 'blockandnuke-message' )->text(),
						'expiry'        => wfGetDB( DB_SLAVE )->getInfinity(),
						'createAccount' => true,
						'blockEmail'    => true
					)
				);
				$blk->isAutoBlocking( true );
				if( $blk->insert() ) {
					$log = new LogPage('block');
					$log->addEntry(
						'block',
						Title::makeTitle( NS_USER, $ip ),
						'Blocked through Special:BlockandNuke',
						array( 'infinite', $ip,  'nocreate' )
					);
					$ret[] = $ip;
					if( $sp ) {
						$sp->getOutput()->addHTML( wfMessage( "blockandnuke-banned-ip", $ip ) );
					}
				}
			}
		}
		$ret = array_filter( $ret );
		return (bool)$ret;
	}

	static function banUser( $user, $banningUser, $spammer, $um ) {
		$ret = null;
		if ( !is_object( $user ) ) {
			/* Skip this one */
		} elseif ( $user->getID() != 0 && $um ) {
			$ret = $um->merge( $user, $spammer, "block", $banningUser );
		} else {
			if( !Block::newFromTarget( $user->getName() ) ) {
				$blk = new Block(
					array(
						'address'       => $user->getName(),
						'user'          => $user->getID(),
						'by'            => $banningUser->getID(),
						'reason'        => wfMessage( 'blockandnuke-message' )->text(),
						'expiry'        => wfGetDB( DB_SLAVE )->getInfinity(),
						'createAccount' => true,
						'blockEmail'    => true
					)
				);
				$blk->isAutoBlocking( true );
				if($ret = $blk->insert()) {
					$log = new LogPage('block');
					$log->addEntry(
						'block',
						Title::makeTitle( NS_USER, $user->getName() ),
						'Blocked through Special:BlockandNuke',
						array( 'infinite', $user->getName(),  'nocreate' )
					);
				}
			}
		}
		return $ret;
	}

	static function blockUser($user, $user_id, $banningUser, $spammer, $um) {
		$ret = array();
		for($c = 0; $c < max( count($user), count($user_id) ); $c++ ){
			if( isset( $user[$c] ) ) {
				$thisUserObj = User::newFromName( $user[$c] );
			} elseif( isset( $user_id[$c] ) ) {
				$thisUserObj = User::newFromId( $user_id[$c] );
			}
			$ret[] = self::banUser( $thisUserObj, $banningUser, $spammer, $um );
		}
		$ret = array_filter( $ret );
		return (bool)$ret;
	}

	static function deletePage( $title, $sp = null ) {
		$ret = null;
		$file = $title->getNamespace() == NS_IMAGE ? wfLocalFile( $title ) : false;
		if ( $file ) {
			$reason= wfMessage( "blockandnuke-delete-file" )->text();
			$oldimage = null; // Must be passed by reference
			$ret = FileDeleteForm::doDelete( $title, $file, $oldimage, $reason, false );
		} else {
			$reason = wfMessage( "blockandnuke-delete-article" )->text();
			if( $title->isKnown() ) {
				$article = new Article( $title );
				$ret = $article->doDelete( $reason );
				if( $ret && $sp ) {
					$sp->getOutput()->addHTML( wfMessage( "blockandnuke-deleted-page", $title ) );
				}
			}
		}
		return $ret;
	}

	static function deletePages( $pages, $sp = null ) {
		$ret = array();
		foreach( (array)$pages as $page ) {
			$ret[] = self::deletePage( Title::newFromText( $page ), $sp );
		}
		$ret = array_filter( $ret );
		return (bool)$ret;
	}

}
