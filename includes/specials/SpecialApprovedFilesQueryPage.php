<?php

class SpecialApprovedFilesQueryPage extends SpecialApprovedPagesQueryPage {

	static $repo = null;
	public $page_title_message = 'approvedrevs-specialapprovedfiles';

	public function __construct ($mode) {
		parent::__construct($mode);

		$this->header_links = array( // files
			'approvedrevs-notlatestfiles'     => '', // was 'notlatestfiles', but is now default
			'approvedrevs-unapprovedfiles'    => 'unapproved',
			'approvedrevs-approvedfiles'      => 'allapproved',
			'approvedrevs-grandfatheredfiles' => 'grandfathered',
		);
		$this->other_special_page = 'ApprovedPages';
	}

	function getName() {
		return 'ApprovedFiles';
	}


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
		#	ALLFILES: list all approved pages
		#   also includes $this->mMode == 'grandfathered', see formatResult()
		#
		if ( $this->mMode == 'all' ) {

			$conds = "p.page_namespace=". NS_FILE; // get everything from approved_revs table
		
		#
		#	UNAPPROVED
		#
		} elseif ( $this->mMode == 'unapproved' ) {

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
		} else if ( $this->mMode == 'grandfathered' ) {
			
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
		#	NOTLATEST
		#
		} else { 
			
			// Name/Title both exist, sha1's don't match OR timestamps don't match
			$conds = "p.page_namespace=" . NS_FILE
				." AND (ar.approved_sha1!=im.img_sha1 OR ar.approved_timestamp!=im.img_timestamp)";

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
