<?php

/**
 * Functions for the Approved Revs extension called by hooks in the MediaWiki
 * code.
 *
 * @file
 * @ingroup Extensions
 *
 * @author Yaron Koren
 * @author Jeroen De Dauw
 */
class ApprovedRevsHooks {

	static $debugCount = 0;
	static $mNoSaveOccurring = false;

	static public function userRevsApprovedAutomatically( $title ) {
		global $egApprovedRevsAutomaticApprovals;
		return ( ApprovedRevs::userCanApprove( $title ) && $egApprovedRevsAutomaticApprovals );
	}

	/**
	 * "noindex" and "nofollow" meta-tags are added to every revision page,
	 * so that search engines won't index them - remove those if this is
	 * the approved revision.
	 * There doesn't seem to be an ideal MediaWiki hook to use for this
	 * function - it currently uses 'PersonalUrls', which works.
	 */
	static public function removeRobotsTag( &$personal_urls, &$title ) {
		if ( ! ApprovedRevs::isDefaultPageRequest() ) {
			return true;
		}

		$revisionID = ApprovedRevs::getApprovedRevID( $title );
		if ( ! empty( $revisionID ) ) {
			global $wgOut;
			$wgOut->setRobotPolicy( 'index,follow' );
		}
		return true;
	}

	/**
	 * Call LinksUpdate on the text of this page's approved revision,
	 * if there is one.
	 */
	static public function updateLinksAfterEdit( &$page, &$editInfo, $changed ) {
		$title = $page->getTitle();
		if ( ! ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}
		// If this user's revisions get approved automatically,
		// exit now, because this will be the approved
		// revision anyway.
		if ( self::userRevsApprovedAutomatically( $title ) ) {
			return true;
		}
		$text = '';
		$approvedText = ApprovedRevs::getApprovedContent( $title );
		if ( !is_null( $approvedText ) ) {
			$text = $approvedText;
		}
		// If there's no approved revision, and 'blank if
		// unapproved' is set to true, set the text to blank.
		if ( is_null( $approvedText ) ) {
			global $egApprovedRevsBlankIfUnapproved;
			if ( $egApprovedRevsBlankIfUnapproved ) {
				$text = '';
			} else {
				// If it's an unapproved page and there's no
				// page blanking, exit here.
				return true;
			}
		}

		$editInfo = $page->prepareTextForEdit( $text );
		$u = new LinksUpdate( $page->mTitle, $editInfo->output );
		$u->doUpdate();

		return true;
	}

	/**
	 * If the user saving this page has approval power, and automatic
	 * approvals are enabled, and the page is approvable, and either
	 * (a) this page already has an approved revision, or (b) unapproved
	 * pages are shown as blank on this wiki, automatically set this
	 * latest revision to be the approved one - don't bother logging
	 * the approval, though; the log is reserved for manual approvals.
	 */
	static public function setLatestAsApproved( &$article , &$user, $text,
		$summary, $flags, $unused1, $unused2, &$flags, $revision,
		&$status, $baseRevId ) {

		if ( is_null( $revision ) ) {
			return true;
		}

		$title = $article->getTitle();
		if ( ! self::userRevsApprovedAutomatically( $title ) ) {
			return true;
		}

		if ( !ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}

		global $egApprovedRevsBlankIfUnapproved;
		if ( !$egApprovedRevsBlankIfUnapproved ) {
			$approvedRevID = ApprovedRevs::getApprovedRevID( $title );
			if ( empty( $approvedRevID ) ) {
				return true;
			}
		}

		// Save approval without logging.
		ApprovedRevs::saveApprovedRevIDInDB( $title, $revision->getID() );
		return true;
	}

	/**
	 * Set the text that's stored for the page for standard searches.
	 */
	static public function setSearchText( &$article , &$user, $text,
		$summary, $flags, $unused1, $unused2, &$flags, $revision,
		&$status, $baseRevId ) {

		if ( is_null( $revision ) ) {
			return true;
		}

		$title = $article->getTitle();
		if ( !ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}

		$revisionID = ApprovedRevs::getApprovedRevID( $title );
		if ( is_null( $revisionID ) ) {
			return true;
		}

		// We only need to modify the search text if the approved
		// revision is not the latest one.
		if ( $revisionID != $article->getLatest() ) {
			$approvedArticle = new Article( $title, $revisionID );
			$approvedText = $approvedArticle->getContent();
			ApprovedRevs::setPageSearchText( $title, $approvedText );
		}

		return true;
	}

	/**
	 * Sets the correct page revision to display the "text snippet" for
	 * a search result.
	 */
	public static function setSearchRevisionID( $title, &$id ) {
		$revisionID = ApprovedRevs::getApprovedRevID( $title );
		if ( !is_null( $revisionID ) ) {
			$id = $revisionID;
		}
		return true;
	}

	/**
	 * Return the approved revision of the page, if there is one, and if
	 * the page is simply being viewed, and if no specific revision has
	 * been requested.
	 */
	static function showApprovedRevision( &$title, &$article ) {
		if ( ! ApprovedRevs::isDefaultPageRequest() ) {
			return true;
		}

		$revisionID = ApprovedRevs::getApprovedRevID( $title );
		if ( ! empty( $revisionID ) ) {
			$article = new Article( $title, $revisionID );
			// This call (whichever it is) is necessary because it
			// causes $article->mRevision to get initialized,
			// which in turn allows "edit section" links to show
			// up if the approved revision is also the latest.
			if ( method_exists( $article, 'getRevisionFetched' ) ) {
				// MW 1.19+
				$article->getRevisionFetched();
			} else {
				// MW 1.18
				$article->fetchContent();
			}
		}
		return true;
	}

	public static function showBlankIfUnapproved( &$article, &$content ) {
		global $egApprovedRevsBlankIfUnapproved;
		if ( ! $egApprovedRevsBlankIfUnapproved ) {
			return true;
		}

		$title = $article->getTitle();
		if ( ! ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}

		$revisionID = ApprovedRevs::getApprovedRevID( $title );
		if ( !empty( $revisionID ) ) {
			return true;
		}

		// Disable the cache for every page, if users aren't meant
		// to see pages with no approved revision, and this page
		// has no approved revision. This looks extreme - but
		// there doesn't seem to be any other way to distinguish
		// between a user looking at the main view of page, and a
		// user specifically looking at the latest revision of the
		// page (which we don't want to show as blank.)
		global $wgEnableParserCache;
		$wgEnableParserCache = false;

		if ( ! ApprovedRevs::isDefaultPageRequest() ) {
			return true;
		}

		ApprovedRevs::addCSS();

		$content = '';

		return true;
	}

	/**
	 * Called for MW 1.21+.
	 */
	public static function showBlankIfUnapproved2( &$article, &$contentObject ) {
		return self::showBlankIfUnapproved( $article, $contentObject->mText );
	}

	/**
	 * If user is viewing the page via its main URL, and what they're
	 * seeing is the approved revision of the page, remove the standard
	 * subtitle shown for all non-latest revisions, and replace it with
	 * either nothing or a message explaining the situation, depending
	 * on the user's rights.
	 */
	static function setSubtitle( &$article, &$revisionID ) {
		$title = $article->getTitle();
		if ( ! ApprovedRevs::hasApprovedRevision( $title ) ) {
			return true;
		}

		global $wgRequest;
		if ( $wgRequest->getCheck( 'oldid' ) ) {
			// If the user is looking at the latest revision,
			// disable caching, to avoid the wiki getting the
			// contents from the cache, and thus getting the
			// approved contents instead.
			if ( $revisionID == $article->getLatest() ) {
				global $wgEnableParserCache;
				$wgEnableParserCache = false;
			}
			return true;
		}

		if ( ! $title->userCan( 'viewlinktolatest' ) ) {
			return false;
		}

		ApprovedRevs::addCSS();
		if ( $revisionID == $article->getLatest() ) {
			$text = Xml::element(
				'span',
				array( 'class' => 'approvedAndLatestMsg' ),
				wfMsg( 'approvedrevs-approvedandlatest' )
			);
		} else {
			$text = wfMsgHtml( 'approvedrevs-notlatest' );

			global $wgUser;
			$sk = $wgUser->getSkin();
			$text .= ' ' . $sk->link(
				$title,
				wfMsgHtml( 'approvedrevs-viewlatestrev' ),
				array(),
				array( 'oldid' => $article->getLatest() ),
				array( 'known', 'noclasses' )
			);

			$text = Xml::tags(
				'span',
				array( 'class' => 'notLatestMsg' ),
				$text
			);
		}

		global $wgOut;
		if ( $wgOut->getSubtitle() != '' ) {
			$wgOut->appendSubtitle( '<br />' . $text );
		} else {
			$wgOut->setSubtitle( $text );
		}

		return false;
	}

	/**
	 * Add a warning to the top of the 'edit' page if the approved
	 * revision is not the same as the latest one, so that users don't
	 * get confused, since they'll be seeing the latest one.
	 */
	public static function addWarningToEditPage( &$editPage ) {
		// only show the warning if it's not an old revision
		global $wgRequest;
		if ( $wgRequest->getCheck( 'oldid' ) ) {
			return true;
		}
		$title = $editPage->getArticle()->getTitle();
		$approvedRevID = ApprovedRevs::getApprovedRevID( $title );
		$latestRevID = $title->getLatestRevID();
		if ( ! empty( $approvedRevID ) && $approvedRevID != $latestRevID ) {
			ApprovedRevs::addCSS();
			global $wgOut;
			$wgOut->addHTML( '<p class="approvedRevsEditWarning">' . wfMsg( 'approvedrevs-editwarning' ) . "</p>\n" );
		}
		return true;
	}

	/**
	 * Same as addWarningToEditPage(), but for the Semantic Foms
	 * 'edit with form' tab.
	 */
	public static function addWarningToSFForm( &$pageName, &$preFormHTML ) {
		// The title could be obtained via $pageName in theory - the
		// problem is that, pre-SF 2.0.2, that variable wasn't set
		// correctly.
		global $wgTitle;
		// TODO: $wgTitle was deprecated in MW 1.19. Use another method of getting to it
		$approvedRevID = ApprovedRevs::getApprovedRevID( $wgTitle );
		$latestRevID = $wgTitle->getLatestRevID();
		if ( ! empty( $approvedRevID ) && $approvedRevID != $latestRevID ) {
			ApprovedRevs::addCSS();
			$preFormHTML .= Xml::element ( 'p',
				array( 'style' => 'font-weight: bold' ),
				wfMsg( 'approvedrevs-editwarning' ) ) . "\n";
		}
		return true;
	}

	/**
	 * If user is looking at a revision through a main 'view' URL (no
	 * revision specified), have the 'edit' tab link to the basic
	 * 'action=edit' URL (i.e., the latest revision), no matter which
	 * revision they're actually on.
	 */
	static function changeEditLink( $skin, &$contentActions ) {
		global $wgRequest;
		if ( $wgRequest->getCheck( 'oldid' ) ) {
			return true;
		}

		$title = $skin->getTitle();
		if ( ApprovedRevs::hasApprovedRevision( $title ) ) {
			// the URL is the same regardless of whether the tab
			// is 'edit' or 'view source', but the "action" is
			// different
			if ( array_key_exists( 'edit', $contentActions ) ) {
				$contentActions['edit']['href'] = $title->getLocalUrl( array( 'action' => 'edit' ) );
			}
			if ( array_key_exists( 'viewsource', $contentActions ) ) {
				$contentActions['viewsource']['href'] = $title->getLocalUrl( array( 'action' => 'edit' ) );
			}
		}
		return true;
	}

	/**
	 * Same as changedEditLink(), but only for the Vector skin (and
	 * related skins).
	 */
	static function changeEditLinkVector( &$skin, &$links ) {
		// the old '$content_actions' array is thankfully just a
		// sub-array of this one
		self::changeEditLink( $skin, $links['views'] );
		return true;
	}

	/**
	 * Store the approved revision ID, if any, directly in the object
	 * for this article - this is called so that a query to the database
	 * can be made just once for every view of a history page, instead
	 * of for every row.
	 */
	static function storeApprovedRevisionForHistoryPage( &$article ) {
		// A bug in some versions of MW 1.19 causes $article to be null.
		if ( is_null( $article ) ) {
			return true;
		}
		// This will be null if there's no ID.
		$approvedRevID = ApprovedRevs::getApprovedRevID( $article->getTitle() );
		$article->getTitle()->approvedRevID = $approvedRevID;

		// seems a bit hackish to put this here, but I'm doing it for now
		// TODO: put this somewhere legitimate, or somehow legitmize this location
		ApprovedRevs::addCSS();

		return true;
	}

	/**
	 * If the user is allowed to make revision approvals, add either an
	 * 'approve' or 'unapprove' link to the end of this row in the page
	 * history, depending on whether or not this is already the approved
	 * revision. If it's the approved revision also add on a "star"
	 * icon, regardless of the user.
	 */
	static function addApprovalLink( $historyPage, &$row , &$s, &$classes ) {
		$title = $historyPage->getTitle();
		if ( ! ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}

		$article = $historyPage->getArticle();
		// use the rev ID field in the $article object, which was
		// stored earlier
		$approvedRevID = $title->approvedRevID;
		if ( $row->rev_id == $approvedRevID ) {
			if ( is_array( $classes ) )
				$classes[] = "approved-revision";
			else
				$classes = array("approved-revision");
			$s = wfMsg( 'approvedrevs-historylabel' ) . '<br />' .  $s;
		}
		if ( ApprovedRevs::userCanApprove( $title ) ) {
			if ( $row->rev_id == $approvedRevID ) {
				$url = $title->getLocalUrl(
					array( 'action' => 'unapprove' )
				);
				$msg = wfMsg( 'approvedrevs-unapprove' );
			} else {
				$url = $title->getLocalUrl(
					array( 'action' => 'approve', 'oldid' => $row->rev_id )
				);
				$msg = wfMsg( 'approvedrevs-approve' );
			}
			$s .= ' (' . Xml::element(
				'a',
				array( 'href' => $url ),
				$msg
			) . ')';
		}
		return true;
	}

	/**
	 * Handle the 'approve' action, defined for ApprovedRevs -
	 * mark the revision as approved, log it, and show a message to
	 * the user.
	 */
	static function setAsApproved( $action, $article ) {
		// Return "true" if the call failed (meaning, pass on handling
		// of the hook to others), and "false" otherwise.
		if ( $action != 'approve' ) {
			return true;
		}
		$title = $article->getTitle();
		if ( ! ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}
		if ( ! ApprovedRevs::userCanApprove( $title ) ) {
			return true;
		}
		global $wgRequest;
		if ( ! $wgRequest->getCheck( 'oldid' ) ) {
			return true;
		}
		$revisionID = $wgRequest->getVal( 'oldid' );
		ApprovedRevs::setApprovedRevID( $title, $revisionID );

		global $wgOut;
		$wgOut->addHTML( "\t\t" . Xml::element(
			'div',
			array( 'class' => 'successbox' ),
			wfMsg( 'approvedrevs-approvesuccess' )
		) . "\n" );
		$wgOut->addHTML( "\t\t" . Xml::element(
			'p',
			array( 'style' => 'clear: both' )
		) . "\n" );

		// show the revision, instead of the history page
		$article->doPurge();
		$article->view();

		return false;
	}

	/**
	 * Handle the 'unapprove' action, defined for ApprovedRevs -
	 * unset the previously-approved revision, log the change, and show
	 * a message to the user.
	 */
	static function unsetAsApproved( $action, $article ) {
		// return "true" if the call failed (meaning, pass on handling
		// of the hook to others), and "false" otherwise
		if ( $action != 'unapprove' ) {
			return true;
		}
		$title = $article->getTitle();
		if ( ! ApprovedRevs::userCanApprove( $title ) ) {
			return true;
		}

		ApprovedRevs::unsetApproval( $title );

		// the message depends on whether the page should display
		// a blank right now or not
		global $egApprovedRevsBlankIfUnapproved;
		if ( $egApprovedRevsBlankIfUnapproved ) {
			$successMsg = wfMsg( 'approvedrevs-unapprovesuccess2' );
		} else {
			$successMsg = wfMsg( 'approvedrevs-unapprovesuccess' );
		}

		global $wgOut;
		$wgOut->addHTML( "\t\t" . Xml::element(
			'div',
			array( 'class' => 'successbox' ),
			$successMsg
		) . "\n" );
		$wgOut->addHTML( "\t\t" . Xml::element(
			'p',
			array( 'style' => 'clear: both' )
		) . "\n" );

		// show the revision, instead of the history page
		$article->doPurge();
		$article->view();

		return false;
	}

	/**
	 * Use the approved revision, if it exists, for templates and other
	 * transcluded pages.
	 */
	static function setTranscludedPageRev( $parser, $title, &$skip, &$id ) {
		$revisionID = ApprovedRevs::getApprovedRevID( $title );
		if ( ! empty( $revisionID ) ) {
			$id = $revisionID;
		}
		return true;
	}

	/**
	 * Delete the approval record in the database if the page itself is
	 * deleted.
	 */
	static function deleteRevisionApproval( &$article, &$user, $reason, $id ) {
		ApprovedRevs::deleteRevisionApproval( $article->getTitle() );
		return true;
	}

	/**
	 * Register magic-word variable IDs
	 */
	static function addMagicWordVariableIDs( &$magicWordVariableIDs ) {
		$magicWordVariableIDs[] = 'MAG_APPROVEDREVS';
		return true;
	}

	/**
	 * Set values in the page_props table based on the presence of the
	 * 'APPROVEDREVS' magic word in a page
	 */
	static function handleMagicWords( &$parser, &$text ) {
		$mw_hide = MagicWord::get( 'MAG_APPROVEDREVS' );
		if ( $mw_hide->matchAndRemove( $text ) ) {
			$parser->mOutput->setProperty( 'approvedrevs', 'y' );
		}
		return true;
	}

	/**
	 * Add a link to 'Special:ApprovedPages' to the the page
	 * 'Special:AdminLinks', defined by the Admin Links extension.
	 */
	static function addToAdminLinks( &$admin_links_tree ) {
		$general_section = $admin_links_tree->getSection( wfMsg( 'adminlinks_general' ) );
		$extensions_row = $general_section->getRow( 'extensions' );
		if ( is_null( $extensions_row ) ) {
			$extensions_row = new ALRow( 'extensions' );
			$general_section->addRow( $extensions_row );
		}
		$extensions_row->addItem( ALItem::newFromSpecialPage( 'ApprovedRevs' ) );
		return true;
	}

	public static function describeDBSchema( $updater = null ) {
		$dir = dirname( __FILE__ );

		// DB updates
		// For now, there's just a single SQL file for all DB types.
		if ( $updater === null ) {
			global $wgExtNewTables, $wgDBtype;
			//if ( $wgDBtype == 'mysql' ) {
				$wgExtNewTables[] = array( 'approved_revs', "$dir/ApprovedRevs.sql" );
				$wgExtNewTables[] = array( 'approved_revs_files', "$dir/ApprovedRevs_Files.sql" );
			//}
		} else {
			//if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addTable', 'approved_revs', "$dir/ApprovedRevs.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', 'approved_revs_files', "$dir/ApprovedRevs_Files.sql", true ) );
			//}
		}
		return true;
	}

	/**
	 * Display a message to the user if (a) "blank if unapproved" is set,
	 * (b) the page is approvable, and (c) either the page has no
	 * approved revision, or the user is looking at a revision that's
	 * not the latest - the displayed message depends on which of those
	 * cases it is.
	 * @TODO - this should probably get split up into two methods.
	 *
	 * @since 0.5.6
	 *
	 * @param Article &$article
	 * @param boolean $outputDone
	 * @param boolean $useParserCache
	 *
	 * @return true
	 */
	public static function setArticleHeader( Article &$article, &$outputDone, &$useParserCache ) {
		global $wgOut, $wgRequest, $egApprovedRevsBlankIfUnapproved;

		// For now, we only set the header if "blank if unapproved"
		// is set.
		if ( ! $egApprovedRevsBlankIfUnapproved ) {
			return true;
		}

		$title = $article->getTitle();
		if ( ! ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}

		// If there's an approved revision for this page, and the
		// user is looking at it - either by simply going to the page,
		// or by looking at the revision that happens to be approved -
		// don't display anything.
		$approvedRevID = ApprovedRevs::getApprovedRevID( $title );
		if ( ! empty( $approvedRevID ) &&
			( ! $wgRequest->getCheck( 'oldid' ) ||
			$wgRequest->getInt( 'oldid' ) == $approvedRevID ) ) {
			return true;
		}

		// Disable caching, so that if it's a specific ID being shown
		// that happens to be the latest, it doesn't show a blank page.
		$useParserCache = false;
		$wgOut->addHTML( '<span style="margin-left: 10.75px">' );

		// If the user is looking at a specific revision, show an
		// "approve this revision" message - otherwise, it means
		// there's no approved revision (we would have exited out if
		// there were), so show a message explaining why the page is
		// blank, with a link to the latest revision.
		if ( $wgRequest->getCheck( 'oldid' ) ) {
			if ( ApprovedRevs::userCanApprove( $title ) ) {
				// @TODO - why is this message being shown
				// at all? Aren't the "approve this revision"
				// links in the history page always good
				// enough?
				$wgOut->addHTML( Xml::tags( 'span', array( 'id' => 'contentSub2' ),
					Xml::element( 'a',
					array( 'href' => $title->getLocalUrl(
						array(
							'action' => 'approve',
							'oldid' => $wgRequest->getInt( 'oldid' )
						)
					) ),
					wfMsg( 'approvedrevs-approvethisrev' )
				) ) );
			}
		} else {
			$wgOut->appendSubtitle(
				htmlspecialchars( wfMsg( 'approvedrevs-blankpageshown' ) ) . '&#160;' .
				Xml::element( 'a',
					array( 'href' => $title->getLocalUrl(
						array(
							'oldid' => $article->getRevIdFetched()
						)
					) ),
					wfMsg( 'approvedrevs-viewlatestrev' )
				)
			);
		}

		$wgOut->addHTML( '</span>' );

		return true;
	}

	/**
	 * If this page has no approved revision, display a header message
	 * stating that, if the setting to display this message is activated.
	 */
	public static function displayNotApprovedHeader( Article &$article, &$outputDone, &$useParserCache ) {
		global $egApprovedRevsShowNotApprovedMessage;
		if ( !$egApprovedRevsShowNotApprovedMessage) {
			return true;
		}
 
		$title = $article->getTitle();
		if ( ! ApprovedRevs::hasApprovedRevision( $title ) ) {
			$text = wfMessage( 'approvedrevs-noapprovedrevision' )->text();
			global $wgOut;
			if ( $wgOut->getSubtitle() != '' ) {
				$wgOut->appendSubtitle( '<br />' . $text );
			} else {
				$wgOut->setSubtitle( $text );
			}
		}
 
		return true;
	}


	/**
	 *  On image pages (pages in NS_FILE), modify each line in the file history (file history, not 
	 *  history of wikitext on file page). Add "approved-revision" class to the appropriate row. For
	 *  users with approve permissions on this page add "approve" and "unapprove" links as required.
	 *
	 *  FIXME: to keep consistent with normal history page, add this somehow:
	 *  $s = '&#9733; Approved Revision<br />' . $s;
	 **/
	public static function onImagePageFileHistoryLine ( $hist, $file, &$s, &$rowClass ){

		$file_title = $file->getTitle();

		if ( ! ApprovedRevs::mediaIsApprovable( $file_title ) ) { // FIXME: pageIsApprovable needs to return true if approved rev exists
			return true;
		}
		
		$row_timestamp = $file->getTimestamp();
		$row_sha1 = $file->getSha1();

		list( $approvedRev_ts, $approvedRev_sha1 ) = ApprovedRevs::GetApprovedFileInfo( $file->getTitle() );

		ApprovedRevs::addCSS();

		// apply class to row of approved revision
		// Note: both here and below in the "userCanApprove" section, if the timestamp condition is
		// removed then all rows with the same sha1 as the approved rev will be given the class
		// "approved-revision", and highlighted. Only the actual approved rev will be given the 
		// message approvedrevs-historylabel, though. 
		if ( $row_sha1 == $approvedRev_sha1 && $row_timestamp == $approvedRev_ts ) {
			if ( $rowClass )
				$rowClass .= ' ';
			$rowClass .= "approved-revision";
			
			$pattern = "/<td[^>]+filehistory-selected+[^>]+>/";
			$replace = "$0" . wfMsg( 'approvedrevs-historylabel' ) . "<br />";
			$s = preg_replace($pattern, $replace, $s);
		}
		
		if ( ApprovedRevs::userCanApprove( $file_title ) ) {
			if ( $row_sha1 == $approvedRev_sha1 && $row_timestamp == $approvedRev_ts ) {
				$url = $file_title->getLocalUrl(
					array( 'action' => 'unapprovefile' )
				);
				$msg = wfMsg( 'approvedrevs-unapprove' );
			} else {
				$url = $file_title->getLocalUrl(
					array( 'action' => 'approvefile', 'ts' => $row_timestamp, 'sha1' => $row_sha1 )
				);
				$msg = wfMsg( 'approvedrevs-approve' );
			}
			$s .= '<td>' . Xml::element(
				'a',
				array( 'href' => $url ),
				$msg
			) . '</td>';
		}
		return true;

	}
		
	/**
	 *  Called on BeforeParserFetchFileAndTitle hook
	 *  Changes links and thumbnails of files to point to the approved revision in all cases except
	 *  the primary file on file pages (e.g. the big image in the top left on File:My File.png). To
	 *  modify that image see self::onImagePageFindFile()
	 *  
	 *  FIXME: this doesn't appear to be working for redirected files
	 **/
	public static function ModifyFileLinks ( $parser, Title $file_title, &$options, &$query ) {

		self::$debugCount++;
		if ( $file_title->getNamespace() == NS_MEDIA ) {
			$file_title = Title::makeTitle( NS_FILE, $file_title->getDBkey() );
			$file_title->resetArticleId( $file_title->getArticleID() ); // avoid extra queries
			
			// Media link redirects don't get caught by the normal redirect check, so this
			// extra check is required
			$temp = WikiPage::newFromID( $file_title->getArticleID() );
			if ( $temp && $temp->getRedirectTarget() ) {
				$file_title = $temp->getTitle();
			}
			unset($temp);
		}
		
		if ( $file_title->isRedirect() ) {
			$page = WikiPage::newFromID( $file_title->getArticleID() );
			$file_title = $page->getRedirectTarget();
			$file_title->resetArticleId( $file_title->getArticleID() ); // avoid extra queries
		}

		# Tell Parser what file version to use
		list( $approvedRev_ts, $approvedRev_sha1 ) = ApprovedRevs::GetApprovedFileInfo( $file_title );
		
		// no valid approved timestamp or sha1, so don't modify image or image link
		if ( (! $approvedRev_ts) || (! $approvedRev_sha1) ) {
			return true;
		}
		
		$options['time'] = wfTimestampOrNull( TS_MW, $approvedRev_ts );
		$options['sha1'] = $approvedRev_sha1;
		
		// $options['broken'] = true; // breaks the link? was in FlaggedRevs...why would we want to do this?

		# Stabilize the file link: this was from FlaggedRevs, and I'm not really sure what it does
		if ( $query != '' ) {
			$query .= '&';
		}
		$query .= "filetimestamp=" . urlencode( wfTimestamp( TS_MW, $approvedRev_ts ) );

		return true;
	}
	
	/**
	 *  Applicable on image pages only, this changes the primary image on the page
	 *  from the most recent to the approved revision.
	 **/
	public static function onImagePageFindFile ( $imagePage, &$normalFile, &$displayFile ) {
		
		// if ($normalFile)
			// $normalFile->purgeCache();
		// if ($displayFile)
			// $displayFile->purgeCache();
	
		list( $approvedRev_ts, $approvedRev_sha1 ) = ApprovedRevs::GetApprovedFileInfo( $imagePage->getFile()->getTitle() );
		if ( (! $approvedRev_ts) || (! $approvedRev_sha1) )
			return true;
		
		$title = $imagePage->getTitle();
		
		$displayFile = wfFindFile( $title, array( 'time' => $approvedRev_ts ) );
		# If none found, try current
		if ( !$displayFile ) {
			wfDebug( __METHOD__ . ": {$title->getPrefixedDBkey()}: $approvedRev_ts not found, using current\n" );
			$displayFile = wfFindFile( $title );
			# If none found, use a valid local placeholder
			if ( !$displayFile ) {
				$displayFile = wfLocalFile( $title ); // fallback to current
			}
			$normalFile = $displayFile;
		# If found, set $normalFile
		} else {
			wfDebug( __METHOD__ . ": {$title->getPrefixedDBkey()}: using timestamp $approvedRev_ts\n" );
			$normalFile = wfFindFile( $title );
		}

		return true;
	}


	/**
	 * Handle the 'approvefile' action, defined for ApprovedRevs -
	 * mark the revision as approved, log it, and show a message to
	 * the user.
	 */
	public static function setFileAsApproved( $action, $article ) {
		// Return "true" if the call failed (meaning, pass on handling
		// of the hook to others), and "false" otherwise.
		if ( $action != 'approvefile' ) {
			return true;
		}
		$title = $article->getTitle();
		if ( ! ApprovedRevs::mediaIsApprovable( $title ) ) {
			return true;
		}
		if ( ! ApprovedRevs::userCanApprove( $title ) ) {
			return true;
		}
		global $wgRequest;
		if ( (! $wgRequest->getCheck( 'ts' )) || (! $wgRequest->getCheck( 'sha1' )) ) {
			die('check query string'); return true;
		}
		$revisionID = $wgRequest->getVal( 'ts' );
		ApprovedRevs::SetApprovedFileInDB( 
			$title, $wgRequest->getVal( 'ts' ), $wgRequest->getVal( 'sha1' ) );

		global $wgOut;
		$wgOut->addHTML( "\t\t" . Xml::element(
			'div',
			array( 'class' => 'successbox' ),
			wfMsg( 'approvedrevs-approvesuccess' )
		) . "\n" );
		$wgOut->addHTML( "\t\t" . Xml::element(
			'p',
			array( 'style' => 'clear: both' )
		) . "\n" );

		// show the revision, instead of the history page
		$article->doPurge();
		$article->view();

		return false;
	}

	/**
	 * Handle the 'unapprovefile' action, defined for ApprovedRevs -
	 * unset the previously-approved revision, log the change, and show
	 * a message to the user.
	 */
	public static function unsetFileAsApproved( $action, $article ) {
		// return "true" if the call failed (meaning, pass on handling
		// of the hook to others), and "false" otherwise
		if ( $action != 'unapprovefile' ) {
			return true;
		}
		$title = $article->getTitle();
		if ( ! ApprovedRevs::userCanApprove( $title ) ) {
			return true;
		}

		ApprovedRevs::UnsetApprovedFileInDB( $title );

		// the message depends on whether the page should display
		// a blank right now or not
		global $egApprovedRevsBlankIfUnapproved;
		if ( $egApprovedRevsBlankIfUnapproved ) {
			$successMsg = wfMsg( 'approvedrevs-unapprovesuccess2' );
		} else {
			$successMsg = wfMsg( 'approvedrevs-unapprovesuccess' );
		}

		global $wgOut;
		$wgOut->addHTML( "\t\t" . Xml::element(
			'div',
			array( 'class' => 'successbox' ),
			$successMsg
		) . "\n" );
		$wgOut->addHTML( "\t\t" . Xml::element(
			'p',
			array( 'style' => 'clear: both' )
		) . "\n" );

		// show the revision, instead of the history page
		$article->doPurge();
		$article->view();

		return false;
	}

	/**
	 *	If a file is deleted, check if the sha1 (and timestamp?) exist in the 
	 *  approved_revs_files table, and delete that row accordingly. A deleted
	 *  version of a file should not be the approved version!
	 *
	 *	FIXME: The code below is wrong. Do what it says above.
	 *  FIXME: The hook for this file has not been added to ApprovedRevs.php
	 *  FIXME: Create ApprovedRevs::UnsetApprovedFileBySha1 or something like that
	 *     Compare UnsetApprovedFileInDB() and UnsetApprovedFile()
	 **/
	public static function onFileDeleteComplete ( File $file, $oldimage, $article, $user, $reason ) {
	
		$dbr = wfGetDB( DB_SLAVE );
		// check if this file has an approved revision
		$approved_file = $dbr->selectRow(
			'approved_revs_files',
			array('approved_timestamp','approved_sha1'),
			array( 'file_title' => $file->getTitle()->getDBkey() )
		);
		
		// If an approved revision exists, loop through all files in history.
		// Since this hook happens AFTER deletion (there is no hook before deletion), check to see
		// if the sha1 of the approved revision is NOT in the history. If it is not in the history,
		// then it has no business being in the approved_revs_files table, and should be deleted.
		if ( $approved_file ) {
			
			$revs = array();
			$approved_exists = false;
			
			$hist = $file->getHistory();
			foreach($hist as $OldLocalFile) {
				// need to check both sha1 and timestamp, since reverted files can have the same
				// sha1, but different timestamps
				if ( $OldLocalFile->getTimestamp() == $approved_file->approved_timestamp
					&& $OldLocalFile->getSha1() == $approved_file->approved_sha1 )
				{
					$approved_exists = true;
				}
				
			}

			if ( ! $approved_exists )
				ApprovedRevs::UnsetApprovedFileInDB( $file->getTitle() );
			
		}
		
		return true;
	}

	
	/*NOT SURE IF THIS IS NECESSARY FOR ANYTHING...
	$wgHooks['ImageOpenShowImageInlineBefore'][] = 'ApprovedRevsHooks::onImageOpenShowImageInlineBefore';
	public static function onImageOpenShowImageInlineBefore ( &$imagePage, &$out ) {

		list( $approvedRev_ts, $approvedRev_sha1 ) = ApprovedRevs::GetApprovedFileInfo( $imagePage->getFile() );
		$hist = $imagePage->getFile()->getHistory();
		
		// there's probably a faster way to do this than to loop through all existing files, but
		// I couldn't find it. Seems like you should be able to do something like
		// $imagePage->getFile()->getFromHash( $sha1 ) or something
		// --User:Jamesmontalvo3 2013OCT18
		foreach($hist as $file) { 
			if ( $file->getSha1()==$approvedRev_sha1 && $file->getTimestamp()==$approvedRev_ts ) {
				$imagePage->setFile( $file );
				return true;
			}
		}
		
		// if no approved rev found, return true and stick with the most recent
		return true;
	}
	*/
	
}
