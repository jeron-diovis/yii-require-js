<?php

Yii::import('qs-local.web.requirejs.RequirejsManager');

/**
 * Class RequirejsManagerMultiScreen
 *
 * This manager defines require.js configuration file parameters, specific for my own js application
 */
class RequirejsManagerMultiScreen extends RequirejsManager {

	/**
	 * @var string Url to be prepended to all ajax requests urls.
	 * Default to app home url (including script name, if available)
	 */
	protected $_apiBaseUrl = null;

	/**
	 * @param array $params
	 * @return array
	 */
	protected function composeAppSpecificParams(array $params) {
		$params['map']['*']['screen'] = $this->composeAppScreenName();
		$params['baseUrl'] = "{$this->getSiteBaseUrl()}/{$this->getAppBaseUrl()}/{$this->getDevDirName()}";
		$params['config']['utils']['baseUrl'] = $this->getApiBaseUrl();
		return $params;
	}

	/**
	 * @overridden
	 * @return string
	 */
	protected function composeAppScreenName() {
		$screen = parent::composeAppScreenName();
		return "screens/{$screen}";
	}

	// Get / Set :

	/**
	 * @param string $apiBaseUrl
	 */
	public function setApiBaseUrl($apiBaseUrl) {
		$this->_apiBaseUrl = $apiBaseUrl;
	}

	/**
	 * @return string
	 */
	public function getApiBaseUrl() {
		$url = $this->_apiBaseUrl;
		if ($url === null) {
			// use getHomeUrl, to automatically include/exclude script name, depending on UrlManager config
			$url = Yii::app()->getRequest()->getHostInfo() . Yii::app()->getHomeUrl();
		}
		return $url;
	}
}