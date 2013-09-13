<?php

/**
 * Main class for the Approved Revs extension.
 *
 * @file
 * @ingroup Extensions
 *
 * @author Yaron Koren
 */
class ApprovedRevs {

	// Static arrays to prevent querying the database more than necessary.
	static $mApprovedContentForPage = array();
	static $mApprovedRevIDForPage = array();
	static $mUserCanApprove = null;
	static $permissions = null;
	static $mUserGroups = null;
	static $james_test = null; // because jamesmontalvo3 doesn't know a better way to test things...
	
	/**
	 * Gets the approved revision ID for this page, or null if there isn't
	 * one.
	 */
	public static function getApprovedRevID( $title ) {
		$pageID = $title->getArticleID();
		if ( array_key_exists( $pageID, self::$mApprovedRevIDForPage ) ) {
			return self::$mApprovedRevIDForPage[$pageID];
		}

		if ( ! self::pageIsApprovable( $title ) ) {
			return null;
		}

		$dbr = wfGetDB( DB_SLAVE );
		$revID = $dbr->selectField( 'approved_revs', 'rev_id', array( 'page_id' => $pageID ) );
		self::$mApprovedRevIDForPage[$pageID] = $revID;
		return $revID;
	}

	/**
	 * Returns whether or not this page has a revision ID.
	 */
	public static function hasApprovedRevision( $title ) {
		$revision_id = self::getApprovedRevID( $title );
		return ( ! empty( $revision_id ) );
	}

	/**
	 * Returns the contents of the specified wiki page, at either the
	 * specified revision (if there is one) or the latest revision
	 * (otherwise).
	 */
	public static function getPageText( $title, $revisionID = null ) {
		if ( method_exists( 'Revision', 'getContent' ) ) {
			// MW >= 1.21
			$revision = Revision::newFromTitle( $title, $revisionID );
			return $revision->getContent()->getNativeData();
		} else {
			$article = new Article( $title, $revisionID );
			return $article->getContent();
		}
	}

	/**
	 * Returns the content of the approved revision of this page, or null
	 * if there isn't one.
	 */
	public static function getApprovedContent( $title ) {
		$pageID = $title->getArticleID();
		if ( array_key_exists( $pageID, self::$mApprovedContentForPage ) ) {
			return self::$mApprovedContentForPage[$pageID];
		}

		$revisionID = self::getApprovedRevID( $title );
		if ( empty( $revisionID ) ) {
			return null;
		}
		$text = self::getPageText( $title, $revisionID );
		self::$mApprovedContentForPage[$pageID] = $text;
		return $text;
	}

	/**
	 * Helper function - returns whether the user is currently requesting
	 * a page via the simple URL for it - not specfying a version number,
	 * not editing the page, etc.
	 */
	public static function isDefaultPageRequest() {
		global $wgRequest;
		if ( $wgRequest->getCheck( 'oldid' ) ) {
			return false;
		}
		// check if it's an action other than viewing
		global $wgRequest; // TODO: why is this here a second time?
		if ( $wgRequest->getCheck( 'action' ) &&
			$wgRequest->getVal( 'action' ) != 'view' &&
			$wgRequest->getVal( 'action' ) != 'purge' &&
			$wgRequest->getVal( 'action' ) != 'render' ) {
				return false;
		}
		return true;
	}

	/**
	 * Returns whether this page can be approved - either because it's in
	 * a supported namespace, or because it's been specially marked as
	 * approvable. Also stores the boolean answer as a field in the page
	 * object, to speed up processing if it's called more than once.
	 */
	public static function pageIsApprovable( Title $title ) {
		// if this function was already called for this page, the
		// value should have been stored as a field in the $title object
		if ( isset( $title->isApprovable ) ) {
			return $title->isApprovable;
		}

		if ( !$title->exists() ) {
			$title->isApprovable = false;
			return $title->isApprovable;			
		}


		// Jamesmontalvo3 suggesting removal of this. approved rev namespaces no handled in
		// approvedrevs-permissions
		
		// check the namespace
		// global $egApprovedRevsNamespaces;
		// if ( in_array( $title->getNamespace(), $egApprovedRevsNamespaces ) ) {
			// $title->isApprovable = true;
			// return $title->isApprovable;
		// }

		$perms = self::getPermissions();

		if ( in_array( self::getNamespaceName( $title ), $perms['Namespaces'] ) )
			return $title->isApprovable = true;
		if ( count( array_intersect( self::getCategoryList( $title ), $perms['Categories'] ) ) > 0 )
			return $title->isApprovable = true;
		if ( in_array( $title->getText(), $perms['Pages'] ) )
			return $title->isApprovable = true;
		

		
		// Jamesmontalvo3 question/discussion:
		// Regarding below: not sure if we should keep this. It seems cleaner to say you can add 
		// the approval-requirement on a case-by-case basis via the approvedrevs-permissions page
		// than to allow any user to throw __APPROVEDREVS__ onto a page. Also, with the new 
		// implementation only people with "All Pages" permissions (probably just sysops in most 
		// cases) will be able to approve this. 

		// it's not in an included namespace, so check for the page
		// property - for some reason, calling the standard
		// getProperty() function doesn't work, so we just do a DB
		// query on the page_props table
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'page_props', 'COUNT(*)',
			array(
				'pp_page' => $title->getArticleID(),
				'pp_propname' => 'approvedrevs',
				'pp_value' => 'y'
			)
		);
		$row = $dbr->fetchRow( $res );
		$isApprovable = ( $row[0] == '1' );
		$title->isApprovable = $isApprovable;
		return $isApprovable;
	}

	public static function userCanApprove ( $title ) {

		// $mUserCanApprove is a static variable used for "caching" the result
		// of this function, so the logic only has to be executed once.
		if ( isset(self::$mUserCanApprove) )
			return self::$mUserCanApprove;

		// other static methods within ApprovedRevs class will require access to $title
		// self::$mwTitleObj = $title;
		// Jamesmontalvo3: After reading through more of the code I think this may break in some cases...
		// TODO: rework w/o self::$mwTitleObj if required - rework may be complete...leaving this here until I get the extension working again
		
		
		$page_ns     = self::getNamespaceName( $title );
		$page_cats   = self::getCategoryList( $title );
		$page_actual = $title->getText();

		$permissions = self::getPermissions();

		if ( self::checkIfUserInPerms($permissions['All Pages']) )
			return self::$mUserCanApprove;

		foreach ($permissions['Namespace Permissions'] as $ns => $perms)
			if ($ns == $page_ns)
				self::checkIfUserInPerms( $perms );

		foreach ($permissions['Category Permissions'] as $cat => $perms) {
			$cat = (  $inclusive = ($cat[0]==='+')  ) ? substr($cat, 1) : $cat;
			if ( in_array($cat, $page_cats) )
				self::checkIfUserInPerms( $perms, $inclusive );
		}
		
		foreach ($permissions['Page Permissions'] as $pg => $perms) {
			$pg = (  $inclusive = ($pg[0]==='+')  ) ? substr($pg, 1) : $pg;
			if ($pg == $page_actual)
				self::checkIfUserInPerms( $perms, $inclusive );
		}
		
		return self::$mUserCanApprove;
	}

	public static function saveApprovedRevIDInDB( $title, $rev_id ) {
		$dbr = wfGetDB( DB_MASTER );
		$page_id = $title->getArticleID();
		$old_rev_id = $dbr->selectField( 'approved_revs', 'rev_id', array( 'page_id' => $page_id ) );
		if ( $old_rev_id ) {
			$dbr->update( 'approved_revs', array( 'rev_id' => $rev_id ), array( 'page_id' => $page_id ) );
		} else {
			$dbr->insert( 'approved_revs', array( 'page_id' => $page_id, 'rev_id' => $rev_id ) );
		}
		// Update "cache" in memory
		self::$mApprovedRevIDForPage[$page_id] = $rev_id;
	}

	static function setPageSearchText( $title, $text ) {
		DeferredUpdates::addUpdate( new SearchUpdate( $title->getArticleID(), $title->getText(), $text ) );
	}

	/**
	 * Sets a certain revision as the approved one for this page in the
	 * approved_revs DB table; calls a "links update" on this revision
	 * so that category information can be stored correctly, as well as
	 * info for extensions such as Semantic MediaWiki; and logs the action.
	 */
	public static function setApprovedRevID( $title, $rev_id, $is_latest = false ) {
		self::saveApprovedRevIDInDB( $title, $rev_id );
		$parser = new Parser();

		// If the revision being approved is definitely the latest
		// one, there's no need to call the parser on it.
		if ( !$is_latest ) {
			$parser->setTitle( $title );
			$text = self::getPageText( $title, $rev_id );
			$options = new ParserOptions();
			$parser->parse( $text, $title, $options, true, true, $rev_id );
			$u = new LinksUpdate( $title, $parser->getOutput() );
			$u->doUpdate();
			self::setPageSearchText( $title, $text );
		}

		$log = new LogPage( 'approval' );
		$rev_url = $title->getFullURL( array( 'old_id' => $rev_id ) );
		$rev_link = Xml::element(
			'a',
			array( 'href' => $rev_url ),
			$rev_id
		);
		$logParams = array( $rev_link );
		$log->addEntry(
			'approve',
			$title,
			'',
			$logParams
		);

		wfRunHooks( 'ApprovedRevsRevisionApproved', array( $parser, $title, $rev_id ) );
	}

	public static function deleteRevisionApproval( $title ) {
		$dbr = wfGetDB( DB_MASTER );
		$page_id = $title->getArticleID();
		$dbr->delete( 'approved_revs', array( 'page_id' => $page_id ) );
	}

	/**
	 * Unsets the approved revision for this page in the approved_revs DB
	 * table; calls a "links update" on this page so that category
	 * information can be stored correctly, as well as info for
	 * extensions such as Semantic MediaWiki; and logs the action.
	 */
	public static function unsetApproval( $title ) {
		global $egApprovedRevsBlankIfUnapproved;

		self::deleteRevisionApproval( $title );

		$parser = new Parser();
		$parser->setTitle( $title );
		if ( $egApprovedRevsBlankIfUnapproved ) {
			$text = '';
		} else {
			$text = self::getPageText( $title );
		}
		$options = new ParserOptions();
		$parser->parse( $text, $title, $options );
		$u = new LinksUpdate( $title, $parser->getOutput() );
		$u->doUpdate();
		self::setPageSearchText( $title, $text );

		$log = new LogPage( 'approval' );
		$log->addEntry(
			'unapprove',
			$title,
			''
		);

		wfRunHooks( 'ApprovedRevsRevisionUnapproved', array( $parser, $title ) );
	}

	public static function addCSS() {
		global $wgOut, $egApprovedRevsScriptPath;
		$link = array(
			'rel' => 'stylesheet',
			'type' => 'text/css',
			'media' => "screen",
			'href' => "$egApprovedRevsScriptPath/ApprovedRevs.css"
		);
		$wgOut->addLink( $link );
	}
	
	
	/**
	 *  All methods below are totally new from jamesmontalvo3
	 **/
		
		
	// pull INI-file content from approvedrevs-permissions
	public static function getPermissions () {

		if ( self::$permissions )
			return self::$permissions;
			
		preg_match_all(
			'/<syntaxhighlight lang="INI">(.*?)<\/syntaxhighlight>/si', 
			wfMessage( 'approvedrevs-permissions' )->text(), 
			$matches);
				
		self::$permissions = parse_ini_string( $matches[1][0], true );
				
		// create arrays of N/C/P's for quickly checking if page is approvable
		self::$permissions['Namespaces'] = array();
		self::$permissions['Categories'] = array();
		self::$permissions['Pages']      = array();
		
		foreach(self::$permissions['Namespace Permissions'] as $ns => $perms)
			array_push(self::$permissions['Namespaces'], $ns);
		
		foreach(self::$permissions['Category Permissions'] as $cat => $perms)
			array_push(self::$permissions['Categories'], ($cat[0]=='+')?substr($cat,1):$cat );
		
		foreach(self::$permissions['Page Permissions'] as $pg => $perms)
			array_push(self::$permissions['Pages'], ($pg[0]=='+')?substr($pg,1):$pg );
			
		return self::$permissions;

	}

	public static function checkIfUserInPerms( $perms, $inclusive=false ) {
		global $wgUser;
		
		// if this isn't going to overwrite other permissions, and other permissions say the user
		// can approve, no need to check further
		if ( $inclusive == true && self::$mUserCanApprove == true)
			return true;
			
		// is like: array("User:John", "User:Jen", "Group:sysop", "Self", "Creator", "Property:Reviewer")
		$perms = explode(',', $perms);
		
		foreach($perms as $perm) {
		
			// $perm[0] == perm type, $perm[1] == perm value (if applicable)
			$perm = explode(':', $perm);
			
			switch ( trim($perm[0]) ) {
				case "User":
					if ( trim($perm[1]) == $wgUser->getName() )
						return self::$mUserCanApprove = true;
					break;
				case "Group":
					if ( in_array( trim($perm[1]), $wgUser->getGroups() ) )
						return self::$mUserCanApprove = true;
					break;
				case "Self":
					if ( self::usernameIsBasePageName() )
						return self::$mUserCanApprove = true;
					break;
				case "Creator":
					if ( self::isPageCreator() )
						return self::$mUserCanApprove = true;
					break;
				case "Property":
					die("Not yet implemented");
					break;
				default:
					die("OH NO!");
			}
		}
		
		// if $inclusive==true, the fact that this call to checkIfUserInPerms() didn't find a match does
		// not mean that that the user is denied. Instead return the unmodified value of  
		// self::$mUserCanApprove, which could be either true or false depending on previous passes
		// through checkIfUserInPerms()
		if ($inclusive)
			return self::$mUserCanApprove;

		// if $inclusive==false, the previous value of $mUserCanApprove is irrelevant. return false 
		// since no matches were found here (still could be overridden by later passes)
		else
			return self::$mUserCanApprove = false;
		
	}
	
	// returns true if $wgUser was the user who created the page
	public static function isPageCreator () {
		global $wgUser, $wgTitle;
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow(
			array( 'revision', 'page' ),
			'revision.rev_user_text',
			array( 'page.page_title' => $wgTitle->getDBkey() ),
			null,
			array( 'ORDER BY' => 'revision.rev_id ASC' ),
			array( 'revision' => array( 'JOIN', 'revision.rev_page = page.page_id' ) )
			// TODO: add a "LIMIT" restriction to not pull excessive amounts of data from DB?
		);
		return $row->rev_user_text == $wgUser->getName();
	}

	// Determines if username is the base pagename, e.g. if user is User:Jamesmontalvo3 then this 
	// returns true for pages named Jamesmontalvo3, Jamesmontalvo3/My Subpage, User:Jamesmontalvo3,
	// User:Jamesmontalvo3/Subpage
	// This is for use with the "Self" keyword in approvedrevs-permissions. NOTE THAT THIS WORKS 
	// OUTSIDE OF THE User NAMESPACE! This was not initially intended, but seems legitimate.
	public static function usernameIsBasePageName () {
		global $wgUser, $wgTitle;

		// explode on slash to just get the first part (if it is a subpage)
		// as far as I know usernames cannot have slashes in them, so this should be okay
		$title_parts = explode('/', $wgTitle->getText()); // no array dereference in PHP < 5.4 :-(
		return $title_parts[0] == $wgUser->getName();
	}

	public static function getNamespaceName ( $title ) {

		$page_ns = MWNamespace::getCanonicalNamespaces();
		$page_ns = $page_ns[ $title->getNamespace() ]; // NS text
		if ($page_ns == "")
			$page_ns = "Main";
		return $page_ns;

	}

	public static function getCategoryList ( $title ) {
		$catTree = $title->getParentCategoryTree();
		return array_unique( self::getCategoryListHelper($catTree) );		
	}
	
	public static function getCategoryListHelper ( $catTree ) {

		$out = array();
		foreach($catTree as $cat => $parentCats) {
			$cat_parts = explode(':', $cat); // best var name ever!
			$out[] = str_replace('_', ' ', $cat_parts[1]); // TODO: anything besided _ need to be replaced?
			if ( count($parentCats) > 0 )
				array_merge($out, self::getCategoryListHelper( $parentCats ));
		}
		return $out;
	
	}
	
}
