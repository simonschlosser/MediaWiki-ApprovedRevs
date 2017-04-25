<?php

class SpecialApprovedPagesQueryPage extends QueryPage {

	protected $mMode;
	protected $specialpage = 'ApprovedPages';
	protected $header_links = array(
		'approvedrevs-notlatestpages'     => '', // was 'notlatest'
		'approvedrevs-unapprovedpages'    => 'unapproved',
		'approvedrevs-approvedpages'      => 'all', // was '' (empty string)
		'approvedrevs-invalidpages' => 'invalid',
	);
	protected $other_special_page = 'ApprovedFiles';

	public function __construct( $mode ) {
		if ( $this instanceof SpecialPage ) {
			parent::__construct( $this->specialpage );
		}
		$this->mMode = $mode;
	}

	function getName() {
		return $this->specialpage;
	}

	function isExpensive() { return false; }

	function isSyndicated() { return false; }

	// When MW requires PHP 5.4 in the future, move this into a
	// trait so both special page classes are sourced from it.
	function getPageHeader() {

		// show the names of the four lists of pages, with the one
		// corresponding to the current "mode" not being linked
		$navLinks = array();
		foreach ( $this->header_links as $msg => $query_param ) {
			$navLinks[] = $this->createHeaderLink( $msg, $query_param );
		}

		$navLine = wfMessage( 'approvedrevs-view' )->text() . ' ' .
			implode(' | ', $navLinks);;
		$header = Xml::tags( 'p', null, $navLine ) . "\n";


		if ( $this->mMode == 'invalid' ) {
			$header .= Xml::tags(
				'p', array( 'style' => 'font-style:italic;' ),
				wfMessage( 'approvedrevs-invalid-description' )->parse()
			);
		}

		$out = Xml::tags(
			'div', array( 'class' => 'specialapprovedrevs-header' ), $header
		);


		return '<small>' . wfMessage( 'approvedrevs-seealso' )->text() .
			': ' . Xml::element( 'a',
				array(
					'href' => SpecialPage::getTitleFor(
						$this->other_special_page )->getLocalURL()
				),
				wfMessage( strtolower( $this->other_special_page ) )->text()
			) . '</small>' . $out;


	}

	function createHeaderLink( $msg, $query_param ) {

		$approvedPagesTitle = SpecialPage::getTitleFor( $this->getName() );

		if ( $this->mMode == $query_param ) {
			return Xml::element( 'strong',
				null,
				wfMessage( $msg )->text()
			);
		} else {
			$show = ( $query_param == '' )
				? array() : array( 'show' => $query_param );
			return Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( $show ) ),
				wfMessage( $msg )->text()
			);
		}

	}

	/**
	 * Set parameters for standard navigation links.
	 */
	function linkParameters() {
		$params = array();

		if ( $this->mMode == 'unapproved' ) {
			$params['show'] = 'unapproved';
		} elseif ( $this->mMode == 'all' ) {
			$params['show'] = 'all';
		} elseif ( $this->mMode == 'invalid' ) { // all approved pages
			$params['show'] = 'invalid';
		}
		// else use default "notlatest"

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

		$tables = array(
			'ar' => 'approved_revs',
			'p' => 'page',
			'pp' => 'page_props',
		);

		$fields = array(
			// required for all all
			'p.page_id AS id',

			// not required for "unapproved", but won't hurt anything
			'ar.rev_id AS rev_id',

			// required for all
			'p.page_latest AS latest_id',
		);

		$join_conds = array(
			'p' => array(
				'JOIN', 'ar.page_id=p.page_id'
			),
			'pp' => array(
				'LEFT OUTER JOIN', 'ar.page_id=pp_page'
			),
		);

		$bannedNSCheck = '(p.page_namespace NOT IN ('
			. implode( ',' , ApprovedRevs::$bannedNamespaceIds )
			. '))';

		#
		#	ALLPAGES: all approved pages
		#	also includes $this->mMode == 'invalid', see formatResult()
		#
		if ( $this->mMode == 'all' ) {

			$conds = $bannedNSCheck; // get everything from approved_revs table
			// keep default:
			// $conds = "$namespacesString " .
			//       "(pp_propname = 'approvedrevs' AND pp_value = 'y')";

		#
		#	UNAPPROVED
		#
		} elseif ( $this->mMode == 'unapproved' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['p'] = array(
				'RIGHT OUTER JOIN', 'ar.page_id=p.page_id' // override
			);
			$join_conds['c'] = array( 'LEFT OUTER JOIN', 'p.page_id=cl_from' );

			list( $ns, $cat, $pg ) =
				ApprovedRevs::getApprovabilityStringsForDB();
			$conds  = ( $ns === '' )  ? '' : "(p.page_namespace IN ($ns)) OR ";
			$conds .= ( $cat === '' ) ? '' : "(c.cl_to IN ($cat)) OR ";
			$conds .= ( $pg === '' )  ? '' : "(p.page_id IN ($pg)) OR ";
			$conds .= "(pp_propname = 'approvedrevs' AND pp_value = 'y')";
			$conds  = "ar.page_id IS NULL AND ($conds) AND $bannedNSCheck";

		#
		#	INVALID PERMISSIONS
		#
		} else if ( $this->mMode == 'invalid' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['p'] = array(
				'LEFT OUTER JOIN', 'ar.page_id=p.page_id' // override
			);
			$join_conds['c'] = array( 'LEFT OUTER JOIN', 'p.page_id=cl_from' );

			list( $ns, $cat, $pg ) =
				ApprovedRevs::getApprovabilityStringsForDB();
			$conds = "";
			$conds .= ( $ns === '' )
				? '' : "(p.page_namespace NOT IN ($ns)) AND ";
			$conds .= ( $cat === '' )
				? ''
				: "(p.page_id NOT IN (" .
				"SELECT DISTINCT cl_from FROM categorylinks WHERE cl_to IN " .
				"($cat))) AND ";
			$conds .= ( $pg === '' )  ? '' : "(p.page_id NOT IN ($pg)) AND ";
			$conds .= "(pp_propname IS NULL OR " .
				"NOT (pp_propname = 'approvedrevs' AND pp_value = 'y'))";

			$options = array( 'DISTINCT' => true );

		#
		#	NOTLATEST
		#
		} else {

			// gets pages in approved_revs table that
			//   (a) are not the latest rev
			//   (b) satisfy ApprovedRevs::$permissions
			// $tables['c'] = 'categorylinks';
			// $join_conds['c'] = array( 'LEFT OUTER JOIN',
			//                           'p.page_id=cl_from' );
			// $conds = "p.page_latest != ar.rev_id AND ($conds)";
			$conds = "p.page_latest != ar.rev_id AND $bannedNSCheck";

		}

		$return = array(
			'tables' => $tables,
			'fields' => $fields,
			'join_conds' => $join_conds,
			'conds' => $conds,
			'options' => array( 'DISTINCT' ),
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

		$pageLink = Linker::link( $title );

		if ( $this->mMode == 'unapproved' ) {

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
		} elseif ( $this->mMode == 'invalid' ) {
			return $pageLink;

		// main mode (pages with an approved revision)
		} elseif ( $this->mMode == 'all' ) {
			global $wgUser, $wgOut, $wgLang;

			$additionalInfo = Xml::element( 'span',
				array (
					'class' => $result->rev_id == $result->latest_id
					? 'approvedRevIsLatest' : 'approvedRevNotLatest'
				),
				wfMessage(
					'approvedrevs-revisionnumber', $result->rev_id
				)->text()
			);

			// Get data on the most recent approval from the
			// 'approval' log, and display it if it's there.
			$loglist = new LogEventsList( $skin, $wgOut );
			$pager = new LogPager(
				$loglist, 'approval', '', $title->getText()
			);
			$pager->mLimit = 1;
			$pager->doQuery();
			$row = $pager->mResult->fetchObject();

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

		# Not latest
		} else {
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

		}
	}

}
