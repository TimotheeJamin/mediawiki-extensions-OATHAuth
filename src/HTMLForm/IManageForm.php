<?php

namespace MediaWiki\Extension\OATHAuth\HTMLForm;

use HTMLForm;
use Title;
use Status;

interface IManageForm {
	/**
	 * @param string $fieldname
	 * @return bool
	 */
	public function hasField( $fieldname );

	/**
	 * @param string $name
	 * @param string $value
	 * @param array $attribs
	 * @return HTMLForm
	 */
	public function addHiddenField( $name, $value, array $attribs = [] );

	/**
	 * @param Title $t
	 * @return HTMLForm
	 */
	public function setTitle( $t );

	/**
	 * @param callable $cb
	 * @return HTMLForm
	 */
	public function setSubmitCallback( $cb );

	/**
	 * @return bool|Status
	 */
	public function show();

	/**
	 * @param array $formData
	 * @return array|bool|Status|string
	 */
	public function onSubmit( array $formData );

	/**
	 * @return void
	 */
	public function onSuccess();
}