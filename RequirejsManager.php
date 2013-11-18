<?php

/**
 * Class RequirejsManager
 *
 * TODO: too many params in one class!
 */
class RequirejsManager extends CApplicationComponent {

	/**
	 * @var null|string Site base url
	 */
	protected $_siteBaseUrl = null;

	/**
	 * @var string Path to js application inside project, relatively to {@link _baseUrl}
	 */
	protected $_appBaseUrl = 'js/app';

	/**
	 * @var string Path to requirejs lib file
	 */
	protected $_libUrl = 'core/libs/require.js';

	/**
	 * @var string Path to basic requirejs configuration file
	 */
	protected $_baseConfigFileName = 'main.js';

	/**
	 * @var string Name of result config file, created dynamically
	 */
	protected $_configFileName = 'main_dynamic.js';

	/**
	 * @var string Id of requirejs's script tag
	 */
	protected $_scriptId = 'require-init';

	/**
	 * @var bool Whether to add 'async' attribute to requirejs script tag
	 * Set to true only if you never call 'require' in view files, and all your js is loaded through single entry point.
	 */
	protected $_async = false;

	/**
	 * @var array Additional params to be passed to initial requirejs config (through 'data-params' attribute of requirejs script tag)
	 */
	protected $_params = array();

	/**
	 * @var callable Callback which returns additional, dynamic params.
	 * Result will be merged with {@link _params} array.
	 * Overlapping keys will be overridden (static params has priority - to allow to override them through local config).
	 *
	 * Callback takes no arguments
	 */
	protected $_paramsCallback = null;

	/**
	 * @var string
	 */
	protected $_devDirName = 'dev';

	/**
	 * TODO: not completed functionality
	 * @var string
	 */
	protected $_buildDirName = 'build';

	/**
	 * TODO: not completed functionality
	 * @var bool
	 */
	protected $_useBuild = false;

	/**
	 * @var string
	 */
	protected $_appModuleName = 'app';

	/**
	 * @var bool Whether to require main app module at the end of main config file
	 */
	protected $_autoRunApp = false;

	/**
	 * @var bool Whether to add timestamp to all modules url
	 */
	protected $_debug = false;

	/**
	 * @var array
	 */
	private $_packages = array();

	/**
	 * @var array
	 */
	private $_paths = array();

	/**
	 * @var array
	 */
	private $_deps = array();

	/**
	 * @var array
	 */
	private $_shims = array();

	/**
	 * @var array
	 */
	private $_onReady = array(
		'encoded' => array(),
		'raw' => array(),
	);

	/**
	 * @var string App screen to be loaded
	 */
	protected $_defaultAppScreen = 'default';

	/**
	 * @var callable Must return string which identifies name of screen to be loaded.
	 * If callback returns null, default value will be used
	 *
	 * Callback takes no arguments
	 */
	protected $_defineAppScreenCallback = null;

	// register:

	/**
	 * @param string $name Module name, under which it will be available for 'require' calls
	 *
	 * @param string $path Path to module file.
	 * Remember, that path is NOT relative to requirejs 'baseUrl' property in following cases:
	 * - path ends with '.js';
	 * - path begins from url scheme;
	 * - path begins from '/';
	 * So, you can register files from web-accessible dir (like 'js') using standard 'baseUrl' property (as it begins with slash).
	 *
	 * @param bool $publish Whether to publish source file to assets directory.
	 * By default is true, as it is supposed that source file belongs to some widget from protected dir.
	 * Set to false, if you register some file from web-accessible dir.
	 *
	 * @param bool $init Whether to add registered module to top-level 'deps' config, so it will be loaded automatically when requirejs will be ready.
	 *
	 * @return string
	 */
	public function registerPath($name, $path, $publish = true, $init = false) {
		if ($publish) {
			$path = $this->publish($path);
		}
		$path = $this->resolveModuleName($path);
		$this->_paths[$name] = $path;
		if ($init) {
			$this->registerModuleInitScript($name);
		}
		return $path;
	}

	/**
	 * @param string|array $nameOrConfig
	 * @param bool $publish
	 * @return string
	 */
	public function registerPackage($nameOrConfig, $publish = true) {
		if (is_array($nameOrConfig)) {
			$path = &$nameOrConfig['location'];
		} else {
			$path = &$nameOrConfig;
		}

		if ($publish) {
			$path = $this->publish($path);
		}

		$path = $this->resolveModuleName($path);

		$this->_packages[] = $nameOrConfig;
		return $path;
	}

	/**
	 * @param string $moduleName
	 * @return $this
	 */
	public function registerModuleInitScript($moduleName) {
		$this->_deps[] = $moduleName;
		return $this;
	}

	/**
	 * @param $name
	 * @param array $config
	 * @return string
	 */
	public function registerShim($name, array $config) {
		$this->_shims[$name] = $config;
		return $this;
	}

	/**
	 * @param string $script
	 * @param bool $encode
	 * @return $this
	 */
	public function registerOnReadyScript($script, $encode = true) {
		/*
		 * If ajax, it means that page is already loaded and require.js is completely initialized -
		 * so we can inject 'require' calls directly in page, and they will be executed immediately.
		 *
		 * Otherwise, on page loading, require.js can be not yet configured and then dependencies will fail -
		 * so initial scripts must be injected to 'callback' require.js's function.
		 */
		if (Yii::app()->getRequest()->getIsAjaxRequest()) {
			Yii::app()->getClientScript()->registerScript(
				__CLASS__ . '-onready-' . time(),
				$encode ? CJavaScript::encode($script) : $script,
				CClientScript::POS_END
			);
		} else {
			$this->_onReady[$encode ? 'raw' : 'encoded'][] = $script;
		}
		return $this;
	}

	/**
	 * Always cut '.js' extension, to compose valid module name - but leading slash or url schema, if it exists,
	 * will remain unchanged, so absolute module path (not relative to 'baseUrl' option) still can be registered
	 *
	 * @param string $name
	 * @return string
	 */
	protected function resolveModuleName($name) {
		return dirname($name) . '/' . basename($name, '.js');
	}

	// main require.js script tag :

	/**
	 * @param bool $return
	 * @return bool|string
	 */
	public function renderAppScriptTag($return = false) {
		$html = CHtml::tag('script', $this->composeAppScriptTagAttributes(), ''); // content !== false - to force render '</script>' closing tag

		if ($return) {
			return $html;
		} else {
			echo $html;
			return true;
		}
	}

	/**
	 * @return array
	 */
	protected function composeAppScriptTagAttributes() {
		$baseUrl = "{$this->getSiteBaseUrl()}/{$this->getAppBaseUrl()}";
		$dirName = $this->getUseBuild() ? $this->getBuildDirName() : $this->getDevDirName();


		$configFileUrl = $this->getUseBuild()
			? "{$baseUrl}/{$this->getBuildDirName()}/{$this->getConfigFileName()}"
			: $this->publishMainConfigFile();

		$attributes = array(
			'type' => 'text/javascript',
			'id' => $this->getScriptId(),
			'src' => "{$baseUrl}/{$dirName}/{$this->getLibUrl()}",
			'data-main' => $configFileUrl,
		);

		if ($this->getAsync()) {
			$attributes['async'] = 'async';
		}

		if (YII_DEBUG) {
			$attributes['data-main'] .= '?bust=' . time();
		}

		return $attributes;
	}

	// compose main config file dynamically:

	/**
	 * TODO: all expressions and functions will be lost!
	 * @return mixed
	 */
	protected function loadBaseConfigFile() {
		$filePath = "{$this->getAppBaseUrl()}/{$this->getDevDirName()}/{$this->getBaseConfigFileName()}";
		$raw = file_get_contents($filePath);
		preg_match('/\(({.*})\)/ms', $raw, $matches);
		$json = CJSON::decode($matches[1]);
		return $json;
	}

	/**
	 * @return array
	 */
	protected function composeDynamicParams() {
		return is_callable($this->getParamsCallback())
			? CMap::mergeArray(
				call_user_func($this->getParamsCallback()),
				$this->getParams()
			)
			: $this->getParams();
	}

	/**
	 * @param array $params
	 * @return array
	 */
	protected function composeAppSpecificParams(array $params) {
		// override
		return $params;
	}

	/**
	 * @return string
	 */
	protected function composeMainConfigFileContent() {
		$params = array();
		$baseConfigFileName = $this->getBaseConfigFileName();
		if ($baseConfigFileName !== false) {
			$params = $this->loadBaseConfigFile();
		}

		$dynamicParams = $this->composeDynamicParams();
		$dynamicParams = $this->composeAppSpecificParams($dynamicParams);

		$params = CMap::mergeArray(
			$params,
			array(
				'paths' => $this->_paths,
				'packages' => $this->_packages,
				'deps' => $this->_deps,
				'shim' => $this->_shims,
			),
			$dynamicParams
		);

		if ($this->getDebug()) {
			// TODO: complete composing urlArgs. Arrays support?
			$bust = 'bust=' . time();
			$params['urlArgs'] .= empty($params['urlArgs']) ? $bust : "&{$bust}";
		}

		$hasRawScripts = $this->_onReady['raw'] !== array();
		$hasEncodedScripts = $this->_onReady['encoded'] !== array();

		$encodedScriptsPlaceholder = $hasEncodedScripts ? '<<<encoded_scripts>>>' : '';

		if ($hasRawScripts || $hasEncodedScripts) {
			$rawScripts = implode('', $this->_onReady['raw']);
			$params['callback'] = "js:function() {
				{$rawScripts};{$encodedScriptsPlaceholder}
			 }";
		}

		$js = CJavaScript::encode($params);

		if ($hasEncodedScripts) {
			$js = strtr($js, array(
				$encodedScriptsPlaceholder => implode('', $this->_onReady['encoded']),
			));
		}

		$fileContent = "require.config({$js});";
		if ($this->getAutoRunApp()) {
			$fileContent .= "require(['{$this->getAppModuleName()}']);";
		}

		return $fileContent;
	}

	/**
	 * @return string
	 */
	protected function publishMainConfigFile() {
		$fileContent = $this->composeMainConfigFileContent();
		$fileName = $this->getConfigFileName();
		$tmpPath = Yii::getPathOfAlias('application.runtime') . '/' . $fileName;
		file_put_contents($tmpPath, $fileContent);
		$url = $this->publish($tmpPath);
		unlink($tmpPath);
		return $url;
	}

	// publish:

	/**
	 * @param string $path
	 * @return string
	 */
	protected function publish($path) {
		return $this->getAssetManager()->publish($path, false, -1, true);
	}

	/**
	 * @return CAssetManager
	 */
	protected function getAssetManager() {
		return Yii::app()->getAssetManager();
	}

	// multiple screens :

	/**
	 * @return string
	 */
	protected function composeAppScreenName() {
		$screen = null;
		if (is_callable($this->getDefineAppScreenCallback())) {
			$screen = call_user_func($this->getDefineAppScreenCallback());
		}
		if ($screen === null) {
			$screen = $this->getDefaultAppScreen();
		}
		return $screen;
	}

	// Get / Set :

	/**
	 * @param string $configUrl
	 */
	public function setBaseConfigFileName($configUrl) {
		$this->_baseConfigFileName = trim($configUrl, '/');
	}

	/**
	 * @return string
	 */
	public function getBaseConfigFileName() {
		return $this->_baseConfigFileName;
	}

	/**
	 * @param string $libUrl
	 */
	public function setLibUrl($libUrl) {
		$this->_libUrl = trim($libUrl, '/');
	}

	/**
	 * @return string
	 */
	public function getLibUrl() {
		return $this->_libUrl;
	}

	/**
	 * @param string $scriptId
	 */
	public function setScriptId($scriptId) {
		$this->_scriptId = $scriptId;
	}

	/**
	 * @return string
	 */
	public function getScriptId() {
		return $this->_scriptId;
	}

	/**
	 * @param boolean $async
	 */
	public function setAsync($async) {
		$this->_async = $async;
	}

	/**
	 * @return boolean
	 */
	public function getAsync() {
		return $this->_async;
	}

	/**
	 * @param string $baseUrl
	 */
	public function setAppBaseUrl($baseUrl) {
		$this->_appBaseUrl = $baseUrl;
	}

	/**
	 * @return string
	 */
	public function getAppBaseUrl() {
		return $this->_appBaseUrl;
	}

	/**
	 * @param callable $paramsCallback
	 * @throws CException
	 */
	public function setParamsCallback($paramsCallback) {
		if (!is_callable($paramsCallback)) {
			throw new CException(__METHOD__ . ': "paramsCallback" must be a callable');
		}
		$this->_paramsCallback = $paramsCallback;
	}

	/**
	 * @return callable
	 */
	public function getParamsCallback() {
		return $this->_paramsCallback;
	}

	/**
	 * @param array $params
	 */
	public function setParams(array $params) {
		$this->_params = $params;
	}

	/**
	 * @return array
	 */
	public function getParams() {
		return $this->_params;
	}

	/**
	 * @param string $buildDirName
	 */
	public function setBuildDirName($buildDirName) {
		$this->_buildDirName = $buildDirName;
	}

	/**
	 * @return string
	 */
	public function getBuildDirName() {
		return $this->_buildDirName;
	}

	/**
	 * @param string $devDirName
	 */
	public function setDevDirName($devDirName) {
		$this->_devDirName = $devDirName;
	}

	/**
	 * @return string
	 */
	public function getDevDirName() {
		return $this->_devDirName;
	}

	/**
	 * @param boolean $useBuild
	 */
	public function setUseBuild($useBuild) {
		$this->_useBuild = $useBuild;
	}

	/**
	 * @return boolean
	 */
	public function getUseBuild() {
		return $this->_useBuild;
	}

	/**
	 * @param string $configFileName
	 */
	public function setConfigFileName($configFileName) {
		$this->_configFileName = $configFileName;
	}

	/**
	 * @return string
	 */
	public function getConfigFileName() {
		return $this->_configFileName;
	}

	/**
	 * @param null|string $baseUrl
	 */
	public function setSiteBaseUrl($baseUrl) {
		$this->_siteBaseUrl = $baseUrl;
	}

	/**
	 * @return null|string
	 */
	public function getSiteBaseUrl() {
		$baseUrl = $this->_siteBaseUrl;
		if ($baseUrl === null) {
			$baseUrl = Yii::app()->getRequest()->getBaseUrl(true);
		}
		return $baseUrl;
	}

	/**
	 * @param string $appModuleName
	 */
	public function setAppModuleName($appModuleName) {
		$this->_appModuleName = $appModuleName;
	}

	/**
	 * @return string
	 */
	public function getAppModuleName() {
		return $this->_appModuleName;
	}

	/**
	 * @param boolean $debug
	 */
	public function setDebug($debug) {
		$this->_debug = $debug;
	}

	/**
	 * @return boolean
	 */
	public function getDebug() {
		return $this->_debug;
	}

	/**
	 * @param boolean $autoRunApp
	 */
	public function setAutoRunApp($autoRunApp) {
		$this->_autoRunApp = $autoRunApp;
	}

	/**
	 * @return boolean
	 */
	public function getAutoRunApp() {
		return $this->_autoRunApp;
	}

	/**
	 * @param callable $defineAppScreenCallback
	 * @throws CException
	 */
	public function setDefineAppScreenCallback($defineAppScreenCallback) {
		if (!is_callable($defineAppScreenCallback)) {
			throw new CException(__METHOD__ . ': "defineAppScreenCallback" must be a callable');
		}
		$this->_defineAppScreenCallback = $defineAppScreenCallback;
	}

	/**
	 * @return callable
	 */
	public function getDefineAppScreenCallback() {
		return $this->_defineAppScreenCallback;
	}

	/**
	 * @param string $defaultAppScreen
	 */
	public function setDefaultAppScreen($defaultAppScreen) {
		$this->_defaultAppScreen = $defaultAppScreen;
	}

	/**
	 * @return string
	 */
	public function getDefaultAppScreen() {
		return $this->_defaultAppScreen;
	}
}