<?php
/**
 * Publishable behavior class.
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2012, Magenta Creations (http://mg-crea.com)
 * @package       WebSocket.Network.Http
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

App::uses('WebSocket', 'WebSocket.Network/Http');

/**
 * MgUtils Plugin
 *
 * MgUtils PublishableBehavior Behavior
 *
 * @package mg_utils
 * @subpackage mg_utils.models.behaviors
 */
class PublishableBehavior extends ModelBehavior {

/**
 * Settings
 *
 * @var mixed
 */
	public $settings = array();

/**
 * Socket
 *
 * @var mixed
 */
	public $websocket;

/**
 * Default settings
 *
 * @var array
 */
	protected $_defaults = array(
	);

/**
 * Setup
 *
 * @param object AppModel
 * @param array $config
 */
	public function setup(&$Model, $config = array()) {
		$settings = array_merge($this->_defaults, $config);
		$this->settings[$Model->alias] = $settings;

		$namespace = '/' . strtolower(Inflector::pluralize($Model->alias));
		$this->websocket = new WebSocket(array('scheme' => 'https', 'host' => SERVER_NAME, 'persistent' => false, 'port' => 8080, 'namespace' => $namespace));
	}

/**
 * Before save callback
 */
	function afterSave(&$Model, $created, $options = array()) {
		$settings = $this->settings[$Model->alias];
		$object = $Model->data[$Model->alias];

		// Filter behavior configuration fields
		if(!empty($settings['fields'])) {
			$settings['fields'] = array_merge(array('id'), $settings['fields']);
			$object = array_intersect_key($object, array_flip($settings['fields']));
		}

		// Filter save() action field whitelist
		if(!empty($options['fieldList'])) {
			$object = array_intersect_key($object, array_flip($options['fieldList']));
		}

		$object['id'] = $Model->id;
		if(!empty($object) && count($object) > 1) {

			if(!@$this->websocket->connect()) return false;

			if($created) {
				$success = $this->websocket->emit('create', array('notify' => false, 'response' => $object));
			} else {
				$success = $this->websocket->emit('edit', array('notify' => false, 'response' => $object));
			}

			@$this->websocket->disconnect();

		}

	}

}
