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
		'fields' => array(),
		'server' => array(
			'scheme' => 'https',
			'host' => 'localhost',
			'persistent' => false,
			'port' => 8080
		)
	);

/**
 * Setup
 *
 * @param object AppModel
 * @param array $config
 */
	public function setup(Model $model, $config = array()) {
		$settings = array_merge($this->_defaults, $config);
		$this->settings[$model->alias] = $settings;

		$namespace = '/' . strtolower(Inflector::pluralize($model->alias));
		$model->websocket = new WebSocket(array_merge($settings['server'], array('namespace' => $namespace, 'silent' => true)));
	}

/**
 * After save callback
 */
	function afterSave(Model $model, $created, $options = array()) {
		$settings = $this->settings[$model->alias];
		$object = $model->data[$model->alias];

		// Filter behavior configuration fields
		if(!empty($settings['fields'])) {
			$settings['fields'] = array_merge(array('id'), $settings['fields']);
			$object = array_intersect_key($object, array_flip($settings['fields']));
		}

		// Filter save() action field whitelist
		if(!empty($options['fieldList'])) {
			$object = array_intersect_key($object, array_flip($options['fieldList']));
		}

		$object['id'] = $model->id;
		if(!empty($object) && count($object) > 1 && !empty($model->websocket)) {

			try {
				if(!$model->websocket->connect()) return false;
			} catch(Exception $e) {
				return false;
			}

			if($created) {
				$success = $model->websocket->emit('create', array('notify' => false, 'response' => $object));
			} else {
				$success = $model->websocket->emit('edit', array('notify' => false, 'response' => $object));
			}

			//$model->websocket->disconnect();

		}

	}

	public function cleanup(Model $model) {
		if($model->websocket && $model->websocket->connected) {
			$model->websocket->disconnect();
		}
	}

}
