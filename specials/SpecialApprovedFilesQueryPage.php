<?php

class SpecialApprovedFilesQueryPage extends SpecialApprovedPagesQueryPage {

	static $repo = null;
	protected $specialpage = 'ApprovedFiles';
	protected $header_links = array( // files
		// was 'notlatestfiles', but is now default
		'approvedrevs-notlatestfiles'     => '',
		'approvedrevs-unapprovedfiles'    => 'unapproved',
		'approvedrevs-approvedfiles'      => 'allapproved',
		'approvedrevs-invalid-files' => 'invalid',
	);
	protected $other_special_page = 'ApprovedPages';

	#
	#	FILE QUERY
	#
	function getQueryInfo() {

		$tables = array(
			'ar' => 'approved_revs_files',
			'im' => 'image',
			'p' => 'page',
		);

		$fields = array(
			'im.img_name AS title',
			'ar.approved_sha1 AS approved_sha1',
			'ar.approved_timestamp AS approved_ts',
			'im.img_sha1 AS latest_sha1',
			'im.img_timestamp AS latest_ts',
		);

		$conds = array();

		$join_conds = array(
			'im' => array( 'LEFT OUTER JOIN', 'ar.file_title=im.img_name' ),
			'p'  => array( 'LEFT OUTER JOIN', 'im.img_name=p.page_title' ),
		);

		#
		#	ALLFILES: list all approved pages
		#   also includes $this->mMode == 'invalid', see formatResult()
		#
		if ( $this->mMode == 'allapproved' ) {

			// get everything from approved_revs table
			$conds['p.page_namespace'] = NS_FILE;

		#
		#	UNAPPROVED
		#
		} elseif ( $this->mMode == 'unapproved' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['c'] = array(
				'LEFT OUTER JOIN', 'p.page_id=c.cl_from'
			);

			$join_conds['im'] = array(
				'RIGHT OUTER JOIN', 'ar.file_title=im.img_name'
			);

			$perms = ApprovedRevs::getPermissions();

			// if all files are not approvable then need to find files matching
			// page and category permissions
			if (
				!in_array(
					NS_FILE, array_keys( $perms['Namespace Permissions'] )
				)
			) {
				list( $ns, $cat, $pg ) =
					ApprovedRevs::getApprovabilityStringsForDB();

				$pageCatConditions = array();
				if ( $cat !== '' ) {
					$pageCatConditions[] = "c.cl_to IN ($cat)";
				}
				if ( $pg  !== '' ) {
					$pageCatConditions[] = "p.page_id IN ($pg)";
				}

				// if there were any page or category conditions, add to $conds
				if ( count( $pageCatConditions ) > 0 ) {
					$conds[] = '(' .
						implode( ' OR ', $pageCatConditions ) .
						')';
				}

			}

			$conds['ar.file_title'] = null;
			$conds['p.page_namespace'] = NS_FILE;

		#
		#	INVALID PERMISSIONS
		#
		} elseif ( $this->mMode == 'invalid' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['c'] = array(
				'LEFT OUTER JOIN', 'p.page_id=c.cl_from'
			);
			$join_conds['im'] = array(
				'LEFT OUTER JOIN', 'ar.file_title=im.img_name'
			);

			$perms = ApprovedRevs::getPermissions();
			if (
				in_array( NS_FILE,
					array_keys( $perms['Namespace Permissions'] ) )
			) {
				// if all files approvable should break out of
				// this...since none can be invalid if they're all
				// approvable using this impossible condition, hack
				$conds[] = 'p.page_namespace=1 AND p.page_namespace=2';
			}
			else {
				list( $ns, $cat, $pg ) =
					ApprovedRevs::getApprovabilityStringsForDB();

				if ( $cat !== '' ) {
					$conds[] = "p.page_id NOT IN " .
						"(SELECT DISTINCT cl_from FROM ".
						"categorylinks WHERE cl_to IN ($cat))";
				}
				if ( $pg  !== '' ) {
					$conds[] = "p.page_id NOT IN ($pg)";
				}

			}

			$conds['p.page_namespace'] = NS_FILE;

		#
		#	NOTLATEST
		#
		} else {

			// Name/Title both exist, sha1's don't match OR timestamps
			// don't match
			$conds['p.page_namespace'] = NS_FILE;
			$conds[] = "(ar.approved_sha1!=im.img_sha1 OR ".
				"ar.approved_timestamp!=im.img_timestamp)";

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

		if ( ! self::$repo ) {
			self::$repo = RepoGroup::singleton();
		}

		$pageLink = Linker::link( $title );


		#
		#	Unapproved Files
		#
		if ( $this->mMode == 'unapproved' ) {
			global $egApprovedRevsShowApproveLatest;

			if (
				$egApprovedRevsShowApproveLatest &&
				ApprovedRevs::userCanApprove( $title )
			) {
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
					wfMessage( 'approvedrevs-approve' )->text()
				) . ')';
			}
			else {
				$approveLink = '';
			}

			return "$pageLink$approveLink";

		#
		#   Invalid Files
		#
		} elseif ( $this->mMode == 'invalid' ) {

			if ( ! ApprovedRevs::fileIsApprovable( $title ) ) {
				// if showing invalid files only, don't show files
				// that have real approvability
				return '';
			}

			return $pageLink;

		#
		#	All Files with an approved revision
		#
			// main mode (pages with an approved revision)
		} elseif ( $this->mMode == 'allapproved' ) {
			global $wgUser, $wgOut, $wgLang;

			$additionalInfo = Xml::element( 'span',
				array (
					'class' =>
						( $result->approved_sha1 == $result->latest_sha1
							&& $result->approved_ts == $result->latest_ts
						) ? 'approvedRevIsLatest' : 'approvedRevNotLatest'
				),
				wfMessage( 'approvedrevs-revisionnumber',
					substr( $result->approved_sha1, 0, 8 ) )->parse()
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
				$timestamp = $wgLang->timeanddate(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
				$date = $wgLang->date(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
				$time = $wgLang->time(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
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

		#
		# Not Latest Files:
		# [[My File.jpg]] (revision 2ba82e7f approved; revision 6ac914dc latest)
		} else {

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
				wfMessage( 'approvedrevs-approvedfile' )->text()
			);
			$latestLink = Xml::element( 'a',
				array( 'href' => $latest_file->getUrl() ),
				wfMessage( 'approvedrevs-latestfile' )->text()
			);

			return "$pageLink ($approvedLink | $latestLink)";
		}

	}

	/**
	 * Set parameters for standard navigation links.
	 */
	function linkParameters() {
		$params = array();

		if ( $this->mMode == 'unapproved' ) {
			$params['show'] = 'unapproved';
		} elseif ( $this->mMode == 'allapproved' ) {
			$params['show'] = 'allapproved';
		} elseif ( $this->mMode == 'invalid' ) { // all approved pages
			$params['show'] = 'invalid';
		}
		// else use default "notlatest"

		return $params;
	}

}
