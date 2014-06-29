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
	static $mApprovedFileInfo = array();
	static $mUserCanApprove = null;
	static $permissions = null;
	static $mUserGroups = null;
	static $james_test = null; // because jamesmontalvo3 doesn't know a better way to test things...
	static $banned_NS_names = array(
		"File", "MediaWiki", "Category"
	);
	static $banned_NS_IDs = false; // requires initialization
	
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

		return self::getApprovedRevIDfromDB( $pageID );
	}

	public static function getApprovedRevIDfromDB ( $pageID ) {

		$dbr = wfGetDB( DB_SLAVE );
		$revID = $dbr->selectField( 'approved_revs', 'rev_id', array( 'page_id' => $pageID ) );
		return self::$mApprovedRevIDForPage[$pageID] = $revID;

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
	public static function pageIsApprovable( Title $title, $is_media=false ) {
		// if this function was already called for this page, the
		// value should have been stored as a field in the $title object
		if ( isset( $title->isApprovable ) && ! $is_media ) { // media needs to bypass this...else isApprovable=false set by File: page
			return $title->isApprovable;
		}

		if ( !$title->exists() ) {
			return $title->isApprovable = false;			
		}


		// Allow custom setting of whether the page is approvable.
		// Jamesmontalvo3: What uses this hook?
		if ( !wfRunHooks( 'ApprovedRevsPageIsApprovable', array( $title, &$isApprovable ) ) ) {
			$title->isApprovable = $isApprovable;
			return $title->isApprovable;
		}

		// START OF MY SECTION

		// File pages NOT approvable, but the uploaded files themselves are
		if ( $title->getNamespace() == NS_FILE && ! $is_media ) {
			return $title->isApprovable = false;
		}

		$perms = self::getPermissions();
		if ( self::titleInNamespacePermissions( $title ) )
			return $title->isApprovable = true;
		if ( self::titleInCategoryPermissions( $title ) )
			return $title->isApprovable = true;
		if ( self::titleInPagePermissions( $title ) )
			return $title->isApprovable = true;

		// FIXME: Jamesmontalvo3 question/discussion:
		// Regarding below: not sure if we should keep this. It seems cleaner to say you can add 
		// the approval-requirement on a case-by-case basis via the approvedrevs-permissions page
		// than to allow any user to throw __APPROVEDREVS__ onto a page. Also, with the new 
		// implementation only people with "All Pages" permissions (probably just sysops in most 
		// cases) will be able to approve this. Rather than adding __APPROVEDREVS__ instead add
		// [[Category:Pages with Approved Revisions]] (or similar category) which can more finely 
		// limit who can have permissions
		// --------------------------------------------------------------------
		// it doesn't satisfy mediawiki:approvedrevs-permissions, so check 
		// for the page property - for some reason, calling the standard
		// getProperty() function doesn't work, so we just do a DB
		// query on the page_props table
		if ( ( ! $is_media ) && self::pageHasMagicWord( $title ) )
			return $title->isApprovable = true;
		
		// if a page already has an approval, it must be approvable in order to be able to 
		// view/modify approvals. Though this wasn't the case on previous versions of ApprovedRevs,
		// it is necessary now since which pages can be approved can change much more easily
		if ( $is_media ) {
			list($ts,$sha1) = self::GetApprovedFileInfo( $title ); // if title in approved_revs_files table
			if ($ts !== false) {
				// only approvable because it already has an approved rev, not because it is in 
				// message "approvedrev-permissions" 
				$title->isGrandfatheredApprovable = true;
				return $title->isApprovable = true;
			}
		}
		// if title in approved_revs table
		else if ( self::getApprovedRevIDfromDB( $title->getArticleID() ) ) {
			$title->isGrandfatheredApprovable = true;
			return $title->isApprovable = true;
		}


	}
	
	public static function mediaIsApprovable ( Title $title ) {
		return self::pageIsApprovable( $title, true ); // use pageIsApprovable() with files allowed
	}

	public static function titleInNamespacePermissions ( $title ) {
		$perms = self::getPermissions();

		if ( in_array( self::getNamespaceName( $title ), $perms['Namespaces'] ) )
			return true;
		else 
			return false;
	}
	
	public static function titleInCategoryPermissions ( $title ) {
		$perms = self::getPermissions();

		if ( count( self::getTitleApprovableCategories($title) ) > 0 )
			return true;
		else
			return false;
	}	
	
	public static function titleInPagePermissions ( $title ) {
		$perms = self::getPermissions();
		
		if ( in_array( $title->getText(), $perms['Pages'] ) )
			return true;
		elseif ( in_array( $title->getNsText().':'.$title->getText(), $perms['Pages'] ) )
			return true;
		else
			return false;
	}
	
	// check if page has __APPROVEDREVS__
	public static function pageHasMagicWord ( $title ) {
	
		// END OF MY SECTION

		// It's not in an included namespace, so check for the page
		// property - for some reason, calling the standard
		// getProperty() function doesn't work, so we just do a DB
		// query on the page_props table.
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'page_props', 'COUNT(*)',
			array(
				'pp_page' => $title->getArticleID(),
				'pp_propname' => 'approvedrevs',
				'pp_value' => 'y'
			)
		);
		$row = $dbr->fetchRow( $res );
		if ( $row[0] == '1' )
			return true;
		else
			return false;
	}
	
	public static function getTitleApprovableCategories ( $title ) {
		$perms = self::getPermissions();
		return array_intersect( self::getCategoryList( $title ), $perms['Categories'] );
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
		if ($page_ns != 'Main')
			$page_actual = $page_ns . ':' . $page_actual;
				
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
		
		if ( self::usernameIsBasePageName() )
			self::$mUserCanApprove = true;

		
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
		global $wgOut;
		$wgOut->addModuleStyles( 'ext.ApprovedRevs' );
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
			
		// Is like: ["User:John", "User:Jen", "Group:sysop", "Self", "Creator", "Property:Reviewer"]
		// Thought about strtolower-ing all of this, but "Property:Prop Name" needs to maintain
		// character case.
		$perms = explode( ',' , $perms );
		
		$userGroups = array_map('strtolower',$wgUser->getGroups());
		
		foreach($perms as $perm) {
		
			// $perm[0] == perm type, $perm[1] == perm value (if applicable)
			$perm = explode(':', $perm);
			
			switch ( strtolower(trim($perm[0])) ) {
				case "user":
					if ( strtolower(trim($perm[1])) === strtolower($wgUser->getName()) )
						return self::$mUserCanApprove = true;
					break;
				case "group":
					if ( in_array( strtolower(trim($perm[1])), $userGroups ) )
						return self::$mUserCanApprove = true;
					break;
				case "creator":
					if ( self::isPageCreator() )
						return self::$mUserCanApprove = true;
					break;
				case "property":
					if ( self::smwPropertyEqualsCurrentUser( $perm[1] ) )
						return self::$mUserCanApprove = true;
					break;
				case "": // skip lines w/o perms (i.e. lines like "Main = ")
					break;
				default:
					throw new MWException(__METHOD__ 
						. '(): invalid permissions type');
			}
		}
				
		// if $inclusive==true, the fact that this call to checkIfUserInPerms() didn't find a match does
		// not mean that that the user is denied. Instead return the unmodified value of  
		// self::$mUserCanApprove, which could be either true or false depending on previous passes
		// through checkIfUserInPerms()
		if ($inclusive)
			// FIXME: isn't this unnecessary? Will always return false? If was true and $inclusive
			// wouldn't it have been caught at beginning of function?
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
	// returns true for pages named User:Jamesmontalvo3, User:Jamesmontalvo3/Subpage, etc
	// This is for use with the "Self" keyword in approvedrevs-permissions.
	public static function usernameIsBasePageName () {
		global $wgUser, $wgTitle;

		if ($wgTitle->getNamespace() == NS_USER || $wgTitle->getNamespace() == NS_USER_TALK) {
		
			// explode on slash to just get the first part (if it is a subpage)
			// as far as I know usernames cannot have slashes in them, so this should be okay
			$title_parts = explode('/', $wgTitle->getText()); // no array dereference in PHP < 5.4 :-(
			
			// sticking with case-sensitive here. So username "James Montalvo" won't have "Self" rights
			// on page "User:James montalvo". I think that's the right move
			return $title_parts[0] == $wgUser->getName();
		
		}
		return false;
	}
	
	public static function getNamespaceIDfromName ( $nsName ) {
		if ($nsName == "Main")
			$nsName = "";
			
		$page_ns = MWNamespace::getCanonicalNamespaces();
		foreach($page_ns as $id => $name) {
			if ($nsName == $name)
				return $id;
		}
		return false; // invalid name, nonexistant namespace
		
		
		$page_ns = $page_ns[ $nsName ]; // NS text
		if ($page_ns == "")
			$page_ns = "Main";
		return $page_ns;

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

	public static function smwPropertyEqualsCurrentUser ( $userProperty ) {
		global $wgTitle, $wgUser;
				
		if ( ! class_exists('SMWHooks') ) // if semantic not installed
			die('Semantic MediaWiki must be installed to use the ApprovedRevs "Property" definition.');
		else {	
			$valueDis = smwfGetStore()->getPropertyValues( 
				new SMWDIWikiPage( $wgTitle->getDBkey(), $wgTitle->getNamespace(), '' ),
				new SMWDIProperty( SMWPropertyValue::makeUserProperty( $userProperty )->getDBkey() ) );   // trim($userProperty)
			
			foreach ($valueDis as $valueDI) {
				if ( ! $valueDI instanceof SMWDIWikiPage )
					throw new Exception('ApprovedRevs "Property" permissions must use Semantic MediaWiki properties of type "Page"');
				if ( $valueDI->getTitle()->getText() == $wgUser->getUserPage()->getText() )
					return true;
			}
		}
		return false;
	}
	
	
	
	public static function SetApprovedFileInDB ( $title, $timestamp, $sha1 ) {

		$dbr = wfGetDB( DB_MASTER );
		$file_title = $title->getDBkey();
		$old_file_title = $dbr->selectField( 'approved_revs_files', 'file_title', array( 'file_title' => $file_title ) );
		if ( $old_file_title ) {
			$dbr->update( 'approved_revs_files', 
				array( 'approved_timestamp' => $timestamp, 'approved_sha1' => $sha1 ), // update fields
				array( 'file_title' => $file_title )
			);
		} else {
			$dbr->insert( 'approved_revs_files',
				array( 'file_title' => $file_title, 'approved_timestamp' => $timestamp, 'approved_sha1' => $sha1 ) 
			);
		}
		// Update "cache" in memory
		self::$mApprovedFileInfo[$file_title] = array( $timestamp, $sha1 );

		$log = new LogPage( 'approval' );
				
		$imagepage = ImagePage::newFromID( $title->getArticleID() );
		$display_file_url = $imagepage->getDisplayedFile()->getFullURL();
		// $url = $title->getDisplayedFile()->getFullURL(); // link to the imagepage, or directly to the approved file?
		
		// $url = $file_obj->getFullURL();
		$rev_link = Xml::element(
			'a',
			array( 'href' => $display_file_url, 'title' => 'unique identifier: ' . $sha1 ),
			substr($sha1, 0, 8) // show first 6 characters of sha1
		);
		$logParams = array( $rev_link );
		$log->addEntry(
			'approve',
			$title,
			'',
			$logParams
		);

		// Run this hook like for 'approve', create new hook, or do nothing?
		// wfRunHooks( 'ApprovedRevsRevisionApproved', array( $parser, $title, $rev_id ) );

	}

	public static function UnsetApprovedFileInDB ( $title ) {
		
		$file_title = $title->getDBkey();
		
		$dbr = wfGetDB( DB_MASTER );
		$dbr->delete( 'approved_revs_files', array( 'file_title' => $file_title ) );
		// the unapprove page method had LinksUpdate and Parser objects here, but the page text has
		// not changed at all with a file approval, so I don't think those are necessary.

		$log = new LogPage( 'approval' );
		$log->addEntry(
			'unapprove',
			$title,
			''
		);

		// Run this hook like for 'unapprove', create new hook, or do nothing?
		// wfRunHooks( 'ApprovedRevsRevisionUnapproved', array( $parser, $title ) );

	}

	/**
	 *  Pulls from DB table approved_revs_files which revision of a file, if any
	 *  besides most recent, should be used as the approved revision.
	 **/
	public static function GetApprovedFileInfo ( $file_title ) {

		if ( isset(self::$mApprovedFileInfo[ $file_title->getDBkey() ]) )
			return self::$mApprovedFileInfo[ $file_title->getDBkey() ];
	
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow(
			'approved_revs_files', // select from table
			array('approved_timestamp', 'approved_sha1'), 
			array( 'file_title' => $file_title->getDBkey() )
		);
		if ( $row )
			$return = array( $row->approved_timestamp, $row->approved_sha1 );
		else
			$return = array( false, false );

		self::$mApprovedFileInfo[ $file_title->getDBkey() ] = $return;
		return $return;
		
	}

	public static function getPermissionsStringsForDB () {

		$perms = self::getPermissions();
		$nsIDs = array();

		foreach($perms['Namespaces'] as $ns) {
			if ( strtolower($ns) == 'main' )
				$ns = '';
			$nsIDs[] = MWNamespace::getCanonicalIndex( strtolower($ns) );
		}

		// using array of categories from $perms, create array of category
		// names in the same form as categorylinks.cl_to
		// FIXME: is mysql_real_escape_string the way to go? of does MW have something built in?
		$catCols = array();
		foreach($perms['Categories'] as $cat) {
			$catObj = Category::newFromName( $cat );
			$catCols[] = "'" . mysql_real_escape_string($catObj->getName()) . "'";
		}

		$pgIDs = array();
		foreach($perms['Pages'] as $pg) {
			$title = Title::newFromText( $pg );
			$pgIDs[] = $title->getArticleID();
		}
		
		return array( 
			count($nsIDs) > 0   ? implode(',', $nsIDs)   : false,
			count($catCols) > 0 ? implode(',', $catCols) : false,
			count($pgIDs) > 0   ? implode(',', $pgIDs)   : false,
		);
		
	}
	
	public static function getBannedNamespaceIDs () {
		
		if ( self::$banned_NS_IDs !== false )
			return self::$banned_NS_IDs;
		
		self::$banned_NS_IDs = array();
		foreach(self::$banned_NS_names as $ns_name) {
			self::$banned_NS_IDs[] = self::getNamespaceIDfromName($ns_name);
		}
		
		return self::$banned_NS_IDs;
	}
	
}
