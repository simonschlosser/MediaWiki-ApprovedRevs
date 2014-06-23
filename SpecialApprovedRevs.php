<?php

/**
 * Special page that displays various lists of pages that either do or do
 * not have an approved revision.
 *
 * @author Yaron Koren
 */
class SpecialApprovedRevs extends SpecialPage {

	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct( 'ApprovedRevs' );
	}

	function execute( $query ) {
		global $wgRequest;

		ApprovedRevs::addCSS();
		$this->setHeaders();
		list( $limit, $offset ) = wfCheckLimits();

		$mode = $wgRequest->getVal( 'show' );

		$fileactions = array('allfiles','notlatestfiles','unapprovedfiles','grandfatheredfiles');
		if ( in_array($mode,$fileactions) )
			$rep = new SpecialApprovedRevsFilesPage( $mode );
		else
			$rep = new SpecialApprovedRevsPage( $mode );

		if ( method_exists( $rep, 'execute' ) ) {
			return $rep->execute( $query );
		} else {
			return $rep->doQuery( $offset, $limit );
		}
	}

}

class SpecialApprovedRevsPage extends QueryPage {

	protected $mMode;

	public function __construct( $mode ) {
		if ( $this instanceof SpecialPage ) {
			parent::__construct( 'ApprovedRevs' );
		}
		$this->mMode = $mode;
	}

	function getName() {
		return 'ApprovedRevs';
	}

	function isExpensive() { return false; }

	function isSyndicated() { return false; }

	function getPageHeader() {
		// show the names of the three lists of pages, with the one
		// corresponding to the current "mode" not being linked

		$navLine = wfMsg( 'approvedrevs-view' ) . ' ';
		
		$links_messages = array( // pages
			'approvedrevs-approvedpages'      => '',
			'approvedrevs-notlatestpages'     => 'notlatest',
			'approvedrevs-unapprovedpages'    => 'unapproved',
			'approvedrevs-grandfatheredpages' => 'grandfathered',
		);
		
		$navLinks = array();
		foreach($links_messages as $msg => $query_param) {
			$navLinks[] = $this->createHeaderLink($msg, $query_param);
		}
		$navLine .= implode(' | ', $navLinks);
		
		$navLine .= '<br />' . wfMsg( 'approvedrevs-viewfiles' ) . ' ';
		
		$links_messages = array( // files
			'approvedrevs-approvedfiles'      => 'allfiles',
			'approvedrevs-notlatestfiles'     => 'notlatestfiles',
			'approvedrevs-unapprovedfiles'    => 'unapprovedfiles',
			'approvedrevs-grandfatheredfiles' => 'grandfatheredfiles',
		);
		$navLinks = array();
		foreach($links_messages as $msg => $query_param) {
			$navLinks[] = $this->createHeaderLink($msg, $query_param);
		}
		$navLine .= implode(' | ', $navLinks);

		$out = Xml::tags( 'p', null, $navLine ) . "\n";
		if ( $this->mMode == 'grandfathered' || $this->mMode == 'grandfatheredfiles' )
			return $out . Xml::tags( 
				'p', array('style'=>'font-style:italic;'), wfMessage('approvedrevs-grandfathered-description')->parse() );
		else
			return $out;
	}

	function createHeaderLink($msg, $query_param) {
	
		$approvedPagesTitle = SpecialPage::getTitleFor( 'ApprovedRevs' );

		if ( $this->mMode == $query_param ) {
			return Xml::element( 'strong',
				null,
				wfMsg( $msg )
			);
		} else {
			$show = ($query_param == '') ? array() : array( 'show' => $query_param );
			return Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( $show ) ),
				wfMsg( $msg )
			);
		}

	}
	
	/**
	 * Set parameters for standard navigation links.
	 */
	function linkParameters() {
		$params = array();

		if ( $this->mMode == 'notlatest' ) {
			$params['show'] = 'notlatest';
		} elseif ( $this->mMode == 'unapproved' ) {
			$params['show'] = 'unapproved';
		} else { // all approved pages
		}

		return $params;
	}

	function getPageFooter() {
	}

	public static function getNsConditionPart( $ns ) {
		return 'p.page_namespace = ' . $ns;
	}

	/**
	 * (non-PHPdoc)
	 * @see QueryPage::getSQL()
	 */
	function getQueryInfo() {
		global $egApprovedRevsNamespaces;
				
		$tables = array(
			'ar' => 'approved_revs',
			'p' => 'page',
			'pp' => 'page_props',
		);

		$fields = array(
			'p.page_id AS id', // required for all all
			'ar.rev_id AS rev_id', // not required for "unapproved", but won't hurt anything
			'p.page_latest AS latest_id', // required for all
		);

		$join_conds = array(
			'p' => array(
				'JOIN', 'ar.page_id=p.page_id'
			),
			'pp' => array(
				'LEFT OUTER JOIN', 'ar.page_id=pp_page'
			),
		);
		
		$bannedNS = '(p.page_namespace NOT IN (' 
			. implode( ',' , ApprovedRevs::getBannedNamespaceIDs() )
			. '))';

				
		#
		#	NOTLATEST
		#
		if ( $this->mMode == 'notlatest' ) {

			// gets pages in approved_revs table that 
			//   (a) are not the latest rev
			//   (b) satisfy MediaWiki:approvedrevs-permissions
			// $tables['c'] = 'categorylinks';
			// $join_conds['c'] = array( 'LEFT OUTER JOIN', 'p.page_id=cl_from' );
			// $conds = "p.page_latest != ar.rev_id AND ($conds)";  
			$conds = "p.page_latest != ar.rev_id AND $bannedNS"; // gets everything in the approved_revs table that is not latest rev
		
		
		#
		#	UNAPPROVED
		#
		} elseif ( $this->mMode == 'unapproved' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['p'] = array( 'RIGHT OUTER JOIN', 'ar.page_id=p.page_id' );	// override	
			$join_conds['c'] = array( 'LEFT OUTER JOIN', 'p.page_id=cl_from' );
			
			list( $ns, $cat, $pg ) = ApprovedRevs::getPermissionsStringsForDB();
			$conds  = ($ns === false)  ? '' : "(p.page_namespace IN ($ns)) OR ";
			$conds .= ($cat === false) ? '' : "(c.cl_to IN ($cat)) OR ";
			$conds .= ($pg === false)  ? '' : "(p.page_id IN ($pg)) OR ";
			$conds .= "(pp_propname = 'approvedrevs' AND pp_value = 'y')";
			$conds  = "ar.page_id IS NULL AND ($conds) AND $bannedNS";		

		#
		#	GRANDFATHERED
		#
		} else if ( $this->mMode == 'grandfathered' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['p'] = array( 'LEFT OUTER JOIN', 'ar.page_id=p.page_id' );	// override	
			$join_conds['c'] = array( 'LEFT OUTER JOIN', 'p.page_id=cl_from' );
			
			list( $ns, $cat, $pg ) = ApprovedRevs::getPermissionsStringsForDB();
			$conds = "";
			$conds .= ($ns === false)  ? '' : "(p.page_namespace NOT IN ($ns)) AND ";
			$conds .= ($cat === false) ? '' : "(p.page_id NOT IN (SELECT DISTINCT cl_from FROM categorylinks WHERE cl_to IN ($cat))) AND ";
			$conds .= ($pg === false)  ? '' : "(p.page_id NOT IN ($pg)) AND ";
			$conds .= "(pp_propname IS NULL OR NOT (pp_propname = 'approvedrevs' AND pp_value = 'y'))";	
		
			$options = array( 'DISTINCT' => true );
		
		#
		#	all approved pages, also includes $this->mMode == 'grandfathered', see formatResult()
		#
		} else { 

			$conds = $bannedNS; // get everything from approved_revs table
			// keep default: $conds = "$namespacesString (pp_propname = 'approvedrevs' AND pp_value = 'y')";
		}

		$return = array(
			'tables' => $tables,
			'fields' => $fields,
			'join_conds' => $join_conds,
			'conds' => $conds,
			'options' => array('DISTINCT'),
		);
		
		return $return;
	}
	
	function getOrder() {
		return ' ORDER BY p.page_namespace, p.page_title ASC';
	}

	function getOrderFields() {
		return array( 'p.page_namespace', 'p.page_title' );
	}

	function sortDescending() {
		return false;
	}

	function formatResult( $skin, $result ) {
		$title = Title::newFromId( $result->id );

		// FIXME: not sure this is required with v1.0+, need to verify
		if( !ApprovedRevs::pageIsApprovable( $title ) ) {
			return false;
		}
		
		$pageLink = Linker::link( $title );

		if ( $this->mMode == 'unapproved' || $this->mMode == 'grandfathered' ) {

			global $egApprovedRevsShowApproveLatest;

			$line = $pageLink;
			if ( $egApprovedRevsShowApproveLatest &&
				ApprovedRevs::userCanApprove( $title ) ) {
				$line .= ' (' . Xml::element( 'a',
					array( 'href' => $title->getLocalUrl(
						array(
							'action' => 'approve',
							'oldid' => $result->latest_id
						)
					) ),
					wfMessage( 'approvedrevs-approvelatest' )->text()
				) . ')';
			}

			return $line;
		} elseif ( $this->mMode == 'notlatest' ) {
			$diffLink = Xml::element( 'a',
				array( 'href' => $title->getLocalUrl(
					array(
						'diff' => $result->latest_id,
						'oldid' => $result->rev_id
					)
				) ),
				wfMessage( 'approvedrevs-difffromlatest' )->text()
			);

			return "$pageLink ($diffLink)";
		} else { // main mode (pages with an approved revision)
			global $wgUser, $wgOut, $wgLang;

			$additionalInfo = Xml::element( 'span',
				array (
					'class' => $result->rev_id == $result->latest_id ? 'approvedRevIsLatest' : 'approvedRevNotLatest'
				),
				wfMessage( 'approvedrevs-revisionnumber', $result->rev_id )->text()
			);

			// Get data on the most recent approval from the
			// 'approval' log, and display it if it's there.
			$loglist = new LogEventsList( $wgOut->getSkin(), $wgOut );
			$pager = new LogPager( $loglist, 'approval', '', $title->getText() );
			$pager->mLimit = 1;
			$pager->doQuery();
			$row = $pager->mResult->fetchObject();

			if ( !empty( $row ) ) {
				$timestamp = $wgLang->timeanddate( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$date = $wgLang->date( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$time = $wgLang->time( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$userLink = Linker::userLink( $row->log_user, $row->user_name );
				$additionalInfo .= ', ' . wfMessage(
					'approvedrevs-approvedby',
					$userLink,
					$timestamp,
					$row->user_name,
					$date,
					$time
				)->text();
			}

			return "$pageLink ($additionalInfo)";
		}
	}

}

class SpecialApprovedRevsFilesPage extends SpecialApprovedRevsPage {

	static $repo = null;

	#
	#	FILE QUERY
	#
	function getQueryInfo() {
		global $egApprovedRevsNamespaces;
		
		$tables = array(
			'ar' => 'approved_revs_files',
			'im' => 'image',
			'p' => 'page',
		);

		$fields = array(
			'im.img_name AS title', // required for ???
			'ar.approved_sha1 AS approved_sha1', // required for ???
			'ar.approved_timestamp AS approved_ts', // required for ???
			'im.img_sha1 AS latest_sha1',
			'im.img_timestamp AS latest_ts',
			'p.page_id AS id',
			//'c.cl_to as category', 
		);
		
		$join_conds = array(
			'im' => array( 'LEFT OUTER JOIN', 'ar.file_title=im.img_name' ),
			'p'  => array( 'LEFT OUTER JOIN', 'im.img_name=p.page_title' ),
		);
		
		#
		#	NOTLATEST
		#
		if ( $this->mMode == 'notlatestfiles' ) {
			
			// Name/Title both exist, sha1's don't match OR timestamps don't match
			$conds = "p.page_namespace=" . NS_FILE
				." AND (ar.approved_sha1!=im.img_sha1 OR ar.approved_timestamp!=im.img_timestamp)";
		
		#
		#	UNAPPROVED
		#
		} elseif ( $this->mMode == 'unapprovedfiles' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['c'] = array( 'LEFT OUTER JOIN', 'p.page_id=c.cl_from' );

			$join_conds['im'] = array( 'RIGHT OUTER JOIN', 'ar.file_title=im.img_name' );
			
			$perms = ApprovedRevs::getPermissions();
			if ( in_array("File", $perms['Namespaces']) ) {
				// if all files approvable no additional conditions required 
				// besides NS_FILE requirement below
				$conds = '';
			}
			else {
				list( $ns, $cat, $pg ) = ApprovedRevs::getPermissionsStringsForDB();
				$conds = array();
				if ($cat !== false) $conds[] = "(c.cl_to IN ($cat))";
				if ($pg  !== false) $conds[] = "(p.page_id IN ($pg))";
				$conds = ' AND (' . implode(' OR ', $conds) . ')';
			}
			
			$conds = "ar.file_title IS NULL AND p.page_namespace=". NS_FILE . $conds;
		
		#
		#	GRANDFATHERED
		#
		} else if ( $this->mMode == 'grandfatheredfiles' ) {
			
			$tables['c'] = 'categorylinks';
			$join_conds['c'] = array( 'LEFT OUTER JOIN', 'p.page_id=c.cl_from' );
			$join_conds['im'] = array( 'LEFT OUTER JOIN', 'ar.file_title=im.img_name' );
			
			$perms = ApprovedRevs::getPermissions();
			if ( in_array("File", $perms['Namespaces']) ) {
				// if all files approvable should break out of this...since none can be 
				// grandfathered if they're all approvable
				$conds = ' AND p.page_namespace=1 AND p.page_namespace=2'; // impossible condition, hack	
			}
			else {
				list( $ns, $cat, $pg ) = ApprovedRevs::getPermissionsStringsForDB();
				$conds = array();
				// WAS: if ($cat !== false) $conds[] = "(c.cl_to NOT IN ($cat) OR c.cl_to IS NULL)";
				if ($cat !== false) 
					$conds[] = "p.page_id NOT IN (SELECT DISTINCT cl_from FROM categorylinks WHERE cl_to IN ($cat))";
				if ($pg  !== false) $conds[] = "(p.page_id NOT IN ($pg))";
				$conds = ' AND (' . implode(' AND ', $conds) . ')';
			}
			
			$conds = "p.page_namespace=". NS_FILE . $conds;
			
		#
		#	all approved pages, also includes $this->mMode == 'grandfathered', see formatResult()
		#
		} else { 
			$conds = "p.page_namespace=". NS_FILE; // get everything from approved_revs table
		}

		return array(
			'tables' => $tables,
			'fields' => $fields,
			'join_conds' => $join_conds,
			'conds' => $conds,
			'options' => array( 'DISTINCT' ),
		);
				
	}

	function formatResult( $skin, $result ) {
	
		$title = Title::makeTitle( NS_FILE, $result->title );
				
		if ( ! self::$repo )
			self::$repo = RepoGroup::singleton();
					
		$pageLink = Linker::link( $title );
		
		
		#
		#	Unapproved Files and Grandfathered Files
		#
		if ( $this->mMode == 'unapprovedfiles' || $this->mMode == 'grandfatheredfiles' ) {
			global $egApprovedRevsShowApproveLatest;
			
			$nsApproved = ApprovedRevs::titleInNamespacePermissions($title);
			$cats = ApprovedRevs::getTitleApprovableCategories($title);
			$catsApproved = ApprovedRevs::titleInCategoryPermissions($title);
			$pgApproved = ApprovedRevs::titleInPagePermissions($title);
			$magicApproved = ApprovedRevs::pageHasMagicWord($title);

			
			if ( $this->mMode == 'grandfathered' 
				&& ($nsApproved || $catsApproved || $pgApproved || $magicApproved) )
			{
				// if showing grandfathered pages only, don't show pages that have real approvability
				return '';  
			}
			
			if ( $egApprovedRevsShowApproveLatest && ApprovedRevs::userCanApprove( $title ) ) {
				$approveLink = ' (' . Xml::element(
					'a',
					array(
						'href' => $title->getLocalUrl(
							array(
								'action' => 'approvefile',
								'ts' => $result->latest_ts,
								'sha1' => $result->latest_sha1
							)
						)
					),
					wfMsg( 'approvedrevs-approve' )
				) . ')';
			}
			else $approveLink = '';
			
			return "$pageLink$approveLink";
		
		#
		# Not Latest Files:
		# [[My File.jpg]] (revision 2ba82h7f approved; revision 2ba82h7f latest)
		} elseif ( $this->mMode == 'notlatestfiles' ) {
		
			$approved_file = self::$repo->findFileFromKey(
				$result->approved_sha1, 
				array( 'time' => $result->approved_ts ) 
			);
			$latest_file = self::$repo->findFileFromKey(
				$result->latest_sha1, 
				array( 'time' => $result->latest_ts ) 
			);
			
			$approvedLink = Xml::element( 'a',
				array( 'href' => $approved_file->getUrl() ),
				wfMsg( 'approvedrevs-approvedfile' )
			);
			$latestLink = Xml::element( 'a',
				array( 'href' => $latest_file->getUrl() ),
				wfMsg( 'approvedrevs-latestfile' )
			);
			
			return "$pageLink ($approvedLink | $latestLink)";
			
		#
		#	All Files with an approved revision
		#
		} else { // main mode (pages with an approved revision)
			global $wgUser, $wgOut, $wgLang;
			
			$additionalInfo = Xml::element( 'span',
				array (
					'class' => 
						($result->approved_sha1 == $result->latest_sha1 && $result->approved_ts == $result->latest_ts) ? 'approvedRevIsLatest' : 'approvedRevNotLatest'
				),
				wfMsg( 'approvedrevs-revisionnumber', substr($result->approved_sha1, 0, 8) )
			);

			// Get data on the most recent approval from the
			// 'approval' log, and display it if it's there.
			$sk = $wgUser->getSkin();
			$loglist = new LogEventsList( $sk, $wgOut );
			$pager = new LogPager( $loglist, 'approval', '', $title );
			$pager->mLimit = 1;
			$pager->doQuery();

			$result = $pager->getResult();
			$row = $result->fetchObject();


			if ( !empty( $row ) ) {
				$timestamp = $wgLang->timeanddate( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$date = $wgLang->date( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$time = $wgLang->time( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$userLink = $sk->userLink( $row->log_user, $row->user_name );
				$additionalInfo .= ', ' . wfMessage(
					'approvedrevs-approvedby',
					$userLink,
					$timestamp,
					$row->user_name,
					$date,
					$time
				)->text();
			}
			
			return "$pageLink ($additionalInfo)";
		}
	}
}
