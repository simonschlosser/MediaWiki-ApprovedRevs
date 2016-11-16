<?php

use MediaWiki\MediaWikiServices;

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

	static $mNoSaveOccurring = false;

	static public function userRevsApprovedAutomatically( $title ) {
		global $egApprovedRevsAutomaticApprovals;
		return (
			ApprovedRevs::userCanApprove( $title ) &&
			$egApprovedRevsAutomaticApprovals
		);
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
	static public function updateLinksAfterEdit(
		&$page, &$editInfo, $changed
	) {
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
	static public function setLatestAsApproved( $article, $user, $content,
		$summary, $isMinor, $isWatch, $section, $flags, $revision,
		$status, $baseRevId ) {

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
	static public function setSearchText( $article, $user, $content,
		$summary, $isMinor, $isWatch, $section, $flags, $revision,
		$status, $baseRevId ) {

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
			$approvedPage = WikiPage::factory( $title );
			$approvedText = $approvedPage->getContent()->getNativeData();
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

	 	if ( ! ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}

		global $egApprovedRevsBlankIfUnapproved;
		$revisionID = ApprovedRevs::getApprovedRevID( $title );

		// Starting in around MW 1.24, blanking of unapproved pages
		// seems to no longer work unless code like this is called -
		// possibly because the cache needs to be disabled. There
		// may be a better way to accomplish that than this, but this
		// works, and it doesn't seem to have a noticeable negative
		// impact, so we'll go with it for now, at least.
		if ( ! empty( $revisionID ) || $egApprovedRevsBlankIfUnapproved ) {
			$article = new Article( $title, $revisionID );
			// This call is necessary because it
			// causes $article->mRevision to get initialized,
			// which in turn allows "edit section" links to show
			// up if the approved revision is also the latest.
			$article->getRevisionFetched();
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

		// Set the content to blank.
		// There's possibly a bug in MW 1.28, where the second argument
		// (called from the hook 'ArticleAfterFetchContentObject') is
		// sometimes (or always?) a string, instead of a Content object.
		// We'll just get around it here with a check. (In theory, $content
		// could also be null, so this check is a good idea anyway.)
		if ( is_object( $content ) ) {
			$content->mText = '';
		} else {
			$content = '';
		}

		return true;
	}

	/**
	 * Sets the subtitle when viewing old revisions of a page.
	 * This function's code is mostly copied from Article::setOldSubtitle(),
	 * and it is meant to serve as a replacement for that function, using
	 * the 'DisplayOldSubtitle' hook.
	 * This display has the following differences from the standard one:
	 * - It includes a link to the approved revision, which goes to the
	 * default page.
	 * - It includes a "diff" link alongside it.
	 * - The "Latest revision" link points to the correct revision ID,
	 * instead of to the default page (unless the latest revision is also
	 * the approved one).
	 *
	 * @author Eli Handel
	 */
	static function setOldSubtitle( $article, $revisionID ) {

		$title = $article->getTitle(); # Added for ApprovedRevs - and removed hook

		$unhide = $article->getContext()->getRequest()->getInt( 'unhide' ) == 1;

		// Cascade unhide param in links for easy deletion browsing.
		$extraParams = array();
		if ( $unhide ) {
			$extraParams['unhide'] = 1;
		}

		if (
			$article->mRevision &&
			$article->mRevision->getId() === $revisionID
		) {
			$revision = $article->mRevision;
		} else {
			$revision = Revision::newFromId( $revisionID );
		}

		$timestamp = $revision->getTimestamp();

		$latestID = $article->getLatest(); // Modified for Approved Revs
		$current = ( $revisionID == $latestID );
		$approvedID = ApprovedRevs::getApprovedRevID( $title );
		$language = $article->getContext()->getLanguage();
		$user = $article->getContext()->getUser();

		$td = $language->userTimeAndDate( $timestamp, $user );
		$tddate = $language->userDate( $timestamp, $user );
		$tdtime = $language->userTime( $timestamp, $user );

		// Show the user links if they're allowed to see them.
		// If hidden, then show them only if requested...
		$userlinks = Linker::revUserTools( $revision, !$unhide );

		$infomsg = (
			$current &&
			!wfMessage( 'revision-info-current' )->isDisabled()
		) ? 'revision-info-current' : 'revision-info';

		$outputPage = $article->getContext()->getOutput();
		$outputPage->addSubtitle( "<div id=\"mw-{$infomsg}\">" .
			wfMessage( $infomsg, $td )->rawParams( $userlinks )->
			params(
				$revision->getID(),
				$tddate,
				$tdtime,
				$revision->getUser()
			)->parse() . "</div>" );

		// Created for Approved Revs
		$latestLinkParams = array();
		if ( $latestID != $approvedID ) {
			$latestLinkParams['oldid'] = $latestID;
		}
		if ( function_exists( 'MediaWiki\MediaWikiServices::getLinkRenderer' ) ) {
			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		} else {
			$linkRenderer = null;
		}
		$lnk = $current
			? wfMessage( 'currentrevisionlink' )->escaped()
			: ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'currentrevisionlink' )->text(),
				array(),
				$latestLinkParams + $extraParams
			);
		$curdiff = $current
			? wfMessage( 'diff' )->escaped()
			: ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'diff' )->text(),
				array(),
				array(
					'diff' => 'cur',
					'oldid' => $revisionID
				) + $extraParams
			);
		$prev = $title->getPreviousRevisionID( $revisionID );
		$prevlink = $prev
			? ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'previousrevision' )->text(),
				array(),
				array(
					'direction' => 'prev',
					'oldid' => $revisionID
				) + $extraParams
			)
			: wfMessage( 'previousrevision' )->escaped();
		$prevdiff = $prev
			? ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'diff' )->text(),
				array(),
				array(
					'diff' => 'prev',
					'oldid' => $revisionID
				) + $extraParams
			)
			: wfMessage( 'diff' )->escaped();
		$nextlink = $current
			? wfMessage( 'nextrevision' )->escaped()
			: ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'nextrevision' )->text(),
				array(),
				array(
					'direction' => 'next',
					'oldid' => $revisionID
				) + $extraParams
			);
		$nextdiff = $current
			? wfMessage( 'diff' )->escaped()
			: ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'diff' )->text(),
				array(),
				array(
					'diff' => 'next',
					'oldid' => $revisionID
				) + $extraParams
			);

		// Added for Approved Revs
		$approved = ( $approvedID != null && $revisionID == $approvedID );
		$approvedlink = $approved
			? wfMessage( 'approvedrevs-approvedrevision' )->escaped()
			: ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'approvedrevs-approvedrevision' )->text(),
				array(),
				$extraParams
			);
		$approveddiff = $approved
			? wfMessage( 'diff' )->escaped()
			: ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'diff' )->text(),
				array(),
				array(
					'diff' => $approvedID,
					'oldid' => $revisionID
				) + $extraParams
			);

		$cdel = Linker::getRevDeleteLink( $user, $revision, $title );
		if ( $cdel !== '' ) {
			$cdel .= ' ';
		}

		// Modified for ApprovedRevs
		$outputPage->addSubtitle( "<div id=\"mw-revision-nav\">" . $cdel .
			wfMessage( 'approvedrevs-revision-nav' )->rawParams(
				$prevdiff, $prevlink, $approvedlink, $approveddiff,
				$lnk, $curdiff, $nextlink, $nextdiff
			)->escaped() . "</div>" );
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
			self::setOldSubtitle( $article, $revisionID );
			// Don't show default Article::setOldSubtitle().
			return false;
		}

		if ( ! $title->userCan( 'viewlinktolatest' ) ) {
			return false;
		}

		ApprovedRevs::addCSS();
		if ( $revisionID == $article->getLatest() ) {
			$text = Xml::element(
				'span',
				array( 'class' => 'approvedAndLatestMsg' ),
				wfMessage( 'approvedrevs-approvedandlatest' )->text()
			);
		} else {
			$text = wfMessage( 'approvedrevs-notlatest' )->parse();

			if ( function_exists( 'MediaWiki\MediaWikiServices::getLinkRenderer' ) ) {
				$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
			} else {
				$linkRenderer = null;
			}
			$text .= ' ' . ApprovedRevs::makeLink(
				$linkRenderer,
				$title,
				wfMessage( 'approvedrevs-viewlatestrev' )->parse(),
				array(),
				array( 'oldid' => $article->getLatest() )
			);

			$text = Xml::tags(
				'span',
				array( 'class' => 'notLatestMsg' ),
				$text
			);
		}

		global $wgOut;
		if ( $wgOut->getSubtitle() != '' ) {
			$wgOut->addSubtitle( '<br />' . $text );
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
			// A lengthy way to avoid not calling $wgOut...
			// hopefully this is better!
			$editPage->getArticle()->getContext()->getOutput()->
				wrapWikiMsg(
					"<p class=\"approvedRevsEditWarning\">$1</p>\n",
					'approvedrevs-editwarning'
				);
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
		$approvedRevID = ApprovedRevs::getApprovedRevID( $wgTitle );
		$latestRevID = $wgTitle->getLatestRevID();
		if ( ! empty( $approvedRevID ) && $approvedRevID != $latestRevID ) {
			ApprovedRevs::addCSS();
			$preFormHTML .= Xml::element ( 'p',
				array( 'style' => 'font-weight: bold' ),
				wfMessage( 'approvedrevs-editwarning' )->text() ) . "\n";
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
				$contentActions['edit']['href'] =
					$title->getLocalUrl( array( 'action' => 'edit' ) );
			}
			if ( array_key_exists( 'viewsource', $contentActions ) ) {
				$contentActions['viewsource']['href'] =
					$title->getLocalUrl( array( 'action' => 'edit' ) );
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
		// This will be null if there's no ID.
		$approvedRevID = ApprovedRevs::getApprovedRevID( $article->getTitle() );
		$article->getTitle()->approvedRevID = $approvedRevID;

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

		// if revision is the page's approved revision
		if ( $row->rev_id == $approvedRevID ) {
			if ( is_array( $classes ) ) {
				$classes[] = "approved-revision";
			}
			else {
				$classes = array( "approved-revision" );
			}

			global $egApprovedRevsHistoryHeader;
			if ( $egApprovedRevsHistoryHeader === true ) {
				$s = wfMessage( 'approvedrevs-historylabel' )->text() .
					'<br />' .  $s;
			}
			else {
				$s .= ' ' . wfMessage( 'approvedrevs-historylabel' )->text();
			}
		}

		// if user can approve the page
		if ( ApprovedRevs::userCanApprove( $title ) ) {
			if ( $row->rev_id == $approvedRevID ) {
				$url = $title->getLocalUrl(
					array( 'action' => 'unapprove' )
				);
				$msg = wfMessage( 'approvedrevs-unapprove' )->text();
			} else {
				$url = $title->getLocalUrl(
					array( 'action' => 'approve', 'oldid' => $row->rev_id )
				);
				$msg = wfMessage( 'approvedrevs-approve' )->text();
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
			wfMessage( 'approvedrevs-approvesuccess' )->text()
		) . "\n" );
		$wgOut->addHTML( "\t\t" . Xml::element(
			'p',
			array( 'style' => 'clear: both' )
		) . "\n" );

		// doPurge() causes semantic data to not be set when using SMW 1.9.0
		// due to a bug in SMW. This was fixed in SMW 1.9.1. Approved Revs
		// accounted for this bug in some earlier versions (see commits
		// e80ac09f and c5370dd4), but doing so caused cache issues: the
		// history page would not show updated approvals without a hard
		// refresh. *** Approved Revs now DOES NOT support SMW 1.9.0 ***
		// This is also required when using Extension:Cargo
		$article->doPurge();

		// Show the revision, instead of the history page.
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
			$successMsg = wfMessage( 'approvedrevs-unapprovesuccess2' )->text();
		} else {
			$successMsg = wfMessage( 'approvedrevs-unapprovesuccess' )->text();
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
		$general_section = $admin_links_tree->getSection(
			wfMessage( 'adminlinks_general' )->text()
		);
		$extensions_row = $general_section->getRow( 'extensions' );
		if ( is_null( $extensions_row ) ) {
			$extensions_row = new ALRow( 'extensions' );
			$general_section->addRow( $extensions_row );
		}
		$extensions_row->addItem(
			ALItem::newFromSpecialPage( 'ApprovedRevs' )
		);
		$extensions_row->addItem(
			ALItem::newFromSpecialPage( 'ApprovedFiles' )
		);
		return true;
	}

	public static function describeDBSchema( $updater = null ) {
		$dir = __DIR__ . "/maintenance";

		// DB updates
		// For now, there's just a single SQL file for all DB types.
		if ( $updater === null ) {
			global $wgExtNewTables, $wgDBtype;
			// if ( $wgDBtype == 'mysql' ) {
				$wgExtNewTables[] = array(
					'approved_revs', "$dir/ApprovedRevs.sql"
				);
				$wgExtNewTables[] = array(
					'approved_revs_files', "$dir/ApprovedRevs_Files.sql"
				);
			// }
		} else {
			// if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array(
						'addTable', 'approved_revs', "$dir/ApprovedRevs.sql",
						true
					) );
				$updater->addExtensionUpdate( array(
						'addTable', 'approved_revs_files',
						"$dir/ApprovedRevs_Files.sql", true
					) );
			// }
		}
		return true;
	}

	/**
	 * Display a message to the user if (a) "blank if unapproved" is set,
	 * (b) the page is approvable, (c) the user has 'viewlinktolatest'
	 * permission, and (d) either the page has no approved revision, or
	 * the user is looking at a revision that's not the latest - the
	 * displayed message depends on which of those cases it is.
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
	public static function setArticleHeader(
		Article &$article, &$outputDone, &$useParserCache
	) {
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

		// If the user isn't supposed to see these kinds of
		// messages, exit.
		if ( ! $title->userCan( 'viewlinktolatest' ) ) {
			return false;
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
				$wgOut->addHTML( Xml::tags( 'span', array(
							'id' => 'contentSub2'
						),
						Xml::element( 'a',
							array( 'href' => $title->getLocalUrl(
									array(
										'action' => 'approve',
										'oldid' => $wgRequest->getInt( 'oldid' )
									)
								) ),
							wfMessage( 'approvedrevs-approvethisrev' )->text()
						) ) );
			}
		} else {
			$wgOut->addSubtitle(
				htmlspecialchars(
					wfMessage( 'approvedrevs-blankpageshown' )->text()
				) . '&#160;' .
				Xml::element( 'a',
					array( 'href' => $title->getLocalUrl(
						array(
							'oldid' => $article->getRevIdFetched()
						)
					) ),
					wfMessage( 'approvedrevs-viewlatestrev' )->text()
				)
			);
		}

		$wgOut->addHTML( '</span>' );

		return true;
	}

	/**
	 * If this page is approvable, but has no approved revision, display
	 * a header message stating that, if the setting to display this
	 * message is activated.
	 */
	public static function displayNotApprovedHeader(
		Article &$article, &$outputDone, &$useParserCache
	) {
		global $egApprovedRevsShowNotApprovedMessage;
		if ( !$egApprovedRevsShowNotApprovedMessage) {
			return true;
		}

		$title = $article->getTitle();
		if ( ! ApprovedRevs::pageIsApprovable( $title ) ) {
			return true;
		}

		if ( ! ApprovedRevs::hasApprovedRevision( $title ) ) {
			$text = wfMessage( 'approvedrevs-noapprovedrevision' )->text();
			global $wgOut;
			if ( $wgOut->getSubtitle() != '' ) {
				$wgOut->addSubtitle( '<br />' . $text );
			} else {
				$wgOut->setSubtitle( $text );
			}
		}

		return true;
	}

	/**
	 * Add a class to the <body> tag indicating the approval status
	 * of this page, so it can be styled accordingly.
	 */
	public static function addBodyClass($out, $sk, &$bodyAttrs)
	{
		global $wgRequest;
		$title = $sk->getTitle();

		if ( ! ApprovedRevs::hasApprovedRevision( $title ) ) {
			// This page has no approved rev.
			$bodyAttrs['class'] .= " approvedRevs-noapprovedrev";
		} else {
			// The page has an approved rev - see if this is it.
			$approvedRevID = ApprovedRevs::getApprovedRevID( $title );
			if ( ! empty( $approvedRevID ) &&
				( ! $wgRequest->getCheck( 'oldid' ) ||
				$wgRequest->getInt( 'oldid' ) == $approvedRevID ) ) {
				// This is the approved rev.
				$bodyAttrs['class'] .= " approvedRevs-approved";
			} else {
				// This is not the approved rev.
				$bodyAttrs['class'] .= " approvedRevs-notapproved";
			}
		}
	}

	/**
	 *  On image pages (pages in NS_FILE), modify each line in the file history
	 *  (file history, not history of wikitext on file page). Add
	 *  "approved-revision" class to the appropriate row. For users with
	 *  approve permissions on this page add "approve" and "unapprove" links as
	 *  required.
	 **/
	public static function onImagePageFileHistoryLine (
		$hist, $file, &$s, &$rowClass
	) {

		$fileTitle = $file->getTitle();

		if ( ! ApprovedRevs::fileIsApprovable( $fileTitle ) ) {
			return true;
		}

		$rowTimestamp = $file->getTimestamp();
		$rowSha1 = $file->getSha1();

		list( $approvedRevTimestamp, $approvedRevSha1 ) =
			ApprovedRevs::getApprovedFileInfo( $file->getTitle() );

		ApprovedRevs::addCSS();

		// Apply class to row of approved revision
		// Note: both here and below in the "userCanApprove" section, if the
		// timestamp condition is removed then all rows with the same sha1 as
		// the approved rev will be given the class "approved-revision", and
		// highlighted. Only the actual approved rev will be given the message
		// approvedrevs-historylabel, though.
		if (
			$rowSha1 == $approvedRevSha1 &&
			$rowTimestamp == $approvedRevTimestamp
		) {
			if ( $rowClass ) {
				$rowClass .= ' ';
			}
			$rowClass .= "approved-revision";

			$pattern = "/<td[^>]+filehistory-selected+[^>]+>/";
			$replace = "$0" . wfMessage( 'approvedrevs-historylabel' )->text()
				. "<br />";
			$s = preg_replace( $pattern, $replace, $s );
		}

		if ( ApprovedRevs::userCanApprove( $fileTitle ) ) {
			if (
				$rowSha1 == $approvedRevSha1 &&
				$rowTimestamp == $approvedRevTimestamp
			) {
				$url = $fileTitle->getLocalUrl(
					array( 'action' => 'unapprovefile' )
				);
				$msg = wfMessage( 'approvedrevs-unapprove' )->text();
			} else {
				$url = $fileTitle->getLocalUrl(
					array(
						'action' => 'approvefile', 'ts' => $rowTimestamp,
						'sha1' => $rowSha1 )
				);
				$msg = wfMessage( 'approvedrevs-approve' )->text();
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
	 *  Called on BeforeParserFetchFileAndTitle hook Changes links and
	 *  thumbnails of files to point to the approved revision in all
	 *  cases except the primary file on file pages (e.g. the big
	 *  image in the top left on File:My File.png). To modify that
	 *  image see self::onImagePageFindFile()
	 **/
	public static function modifyFileLinks (
		$parser, Title $fileTitle, &$options, &$query
	) {
		if ( $fileTitle->getNamespace() == NS_MEDIA ) {
			$fileTitle = Title::makeTitle( NS_FILE, $fileTitle->getDBkey() );
			// avoid extra queries
			$fileTitle->resetArticleId( $fileTitle->getArticleID() );

			// Media link redirects don't get caught by the normal
			// redirect check, so this extra check is required
			$temp = WikiPage::newFromID( $file_title->getArticleID() );
			if ( $temp && $temp->getRedirectTarget() ) {
				$file_title = $temp->getTitle();
			}
			unset($temp);

		}

		if ( $fileTitle->isRedirect() ) {
			$page = WikiPage::newFromID( $fileTitle->getArticleID() );
			$fileTitle = $page->getRedirectTarget();
			// avoid extra queries
			$fileTitle->resetArticleId( $fileTitle->getArticleID() );
		}

		# Tell Parser what file version to use
		list( $approvedRevTimestamp, $approvedRevSha1 ) =
			ApprovedRevs::getApprovedFileInfo( $fileTitle );

		// no valid approved timestamp or sha1, so don't modify image
		// or image link
		if ( ( ! $approvedRevTimestamp ) || ( ! $approvedRevSha1 ) ) {
			return true;
		}

		$options['time'] = wfTimestampOrNull( TS_MW, $approvedRevTimestamp );
		$options['sha1'] = $approvedRevSha1;

		// breaks the link? was in FlaggedRevs...why would we want to do this?
		// $options['broken'] = true;

		# Stabilize the file link
		if ( $query != '' ) {
			$query .= '&';
		}
		$query .= "filetimestamp=" . urlencode(
			wfTimestamp( TS_MW, $approvedRevTimestamp )
		);

		return true;
	}

	/**
	 *  Applicable on image pages only, this changes the primary image
	 *  on the page from the most recent to the approved revision.
	 **/
	public static function onImagePageFindFile (
		$imagePage, &$normalFile, &$displayFile
	) {

		list( $approvedRevTimestamp, $approvedRevSha1 ) =
			ApprovedRevs::getApprovedFileInfo(
				$imagePage->getFile()->getTitle()
			);
		if ( ( ! $approvedRevTimestamp ) || ( ! $approvedRevSha1 ) )
			return true;

		$title = $imagePage->getTitle();

		$displayFile = wfFindFile(
			$title, array( 'time' => $approvedRevTimestamp )
		);
		# If none found, try current
		if ( !$displayFile ) {
			wfDebug( __METHOD__ . ": {$title->getPrefixedDBkey()}: " .
				"$approvedRevTimestamp not found, using current\n" );
			$displayFile = wfFindFile( $title );
			# If none found, use a valid local placeholder
			if ( !$displayFile ) {
				$displayFile = wfLocalFile( $title ); // fallback to current
			}
			$normalFile = $displayFile;
		# If found, set $normalFile
		} else {
			wfDebug( __METHOD__ . ": {$title->getPrefixedDBkey()}: " .
				"using timestamp $approvedRevTimestamp\n" );
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
		if ( ! ApprovedRevs::fileIsApprovable( $title ) ) {
			return true;
		}
		if ( ! ApprovedRevs::userCanApprove( $title ) ) {
			return true;
		}
		global $wgRequest;
		if (
			( ! $wgRequest->getCheck( 'ts' ) ) ||
			( ! $wgRequest->getCheck( 'sha1' ) )
		) {
			die( 'check query string' ); return true;
		}
		$revisionID = $wgRequest->getVal( 'ts' );
		ApprovedRevs::setApprovedFileInDB(
			$title, $wgRequest->getVal( 'ts' ), $wgRequest->getVal( 'sha1' ) );

		global $wgOut;
		$wgOut->addHTML( "\t\t" . Xml::element(
			'div',
			array( 'class' => 'successbox' ),
			wfMessage( 'approvedrevs-approvesuccess' )->text()
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

		ApprovedRevs::unsetApprovedFileInDB( $title );

		// the message depends on whether the page should display
		// a blank right now or not
		global $egApprovedRevsBlankIfUnapproved;
		if ( $egApprovedRevsBlankIfUnapproved ) {
			$successMsg = wfMessage( 'approvedrevs-unapprovesuccess2' )->text();
		} else {
			$successMsg = wfMessage( 'approvedrevs-unapprovesuccess' )->text();
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
	 **/
	public static function onFileDeleteComplete (
		File $file, $oldimage, $article, $user, $reason
	) {

		$dbr = wfGetDB( DB_SLAVE );
		// check if this file has an approved revision
		$approvedFile = $dbr->selectRow(
			'approved_revs_files',
			array( 'approved_timestamp', 'approved_sha1' ),
			array( 'file_title' => $file->getTitle()->getDBkey() )
		);

		// If an approved revision exists, loop through all files in
		// history.  Since this hook happens AFTER deletion (there is
		// no hook before deletion), check to see if the sha1 of the
		// approved revision is NOT in the history. If it is not in
		// the history, then it has no business being in the
		// approved_revs_files table, and should be deleted.
		if ( $approvedFile ) {

			$revs = array();
			$approvedExists = false;

			$hist = $file->getHistory();
			foreach ( $hist as $OldLocalFile ) {
				// need to check both sha1 and timestamp, since
				// reverted files can have the same sha1, but
				// different timestamps
				if (
					$OldLocalFile->getTimestamp() ==
					$approvedFile->approved_timestamp &&
					$OldLocalFile->getSha1() == $approvedFile->approved_sha1 )
				{
					$approvedExists = true;
				}

			}

			if ( ! $approvedExists )
				ApprovedRevs::unsetApprovedFileInDB( $file->getTitle() );

		}

		return true;
	}

	/**
	 * @param $qp array
	 * @return bool true
	 */
	public static function onwgQueryPages( &$qp ) {
		$qp['SpecialApprovedRevsPage'] = 'ApprovedRevs';
		return true;
	}
}
