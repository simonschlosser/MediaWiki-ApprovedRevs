<?php

/**
 * Special page that displays various lists of pages that either do or do
 * not have an approved revision.
 *
 * @author Yaron Koren
 */
class SpecialApprovedPages extends SpecialPage {

	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct( 'ApprovedPages' );
	}

	function execute( $query ) {

		ApprovedRevs::addCSS();
		$this->setHeaders();

		$rep = new SpecialApprovedPagesQueryPage( $this->getRequest()->getVal( 'show' ) );

		return $rep->execute( $query );

	}

}