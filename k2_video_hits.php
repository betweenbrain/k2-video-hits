<?php defined('_JEXEC') or die;
/**
 * File       k2_video_hits.php
 * Created    1/11/13 1:52 PM
 * Author     Matt Thomas
 * Website    http://betweenbrain.com
 * Email      matt@betweenbrain.com
 * Support    https://github.com/betweenbrain/
 * Copyright  Copyright (C) 2013 betweenbrain llc. All Rights Reserved.
 * License    GNU GPL v3 or later
 */

jimport('joomla.plugin.plugin');
jimport('joomla.html.parameter');

class plgSystemk2_video_hits extends JPlugin {

	/**
	 * Hardcoded minimum of 5 minutes
	 *
	 * @var
	 */
	protected $interval = 300;

	/**
	 * An associative array to contain data about each video
	 *
	 * @param $videoData
	 * @return array
	 */
	protected $videoData = NULL;

	function plgSystemk2_video_hits(&$subject, $params) {
		parent::__construct($subject, $params);
		$this->db     = JFactory::getDBO();
		$this->plugin =& JPluginHelper::getPlugin('system', 'k2_video_hits');
		$this->params = new JParameter($this->plugin->params);
		// Convert input into minutes
		$this->interval = (int) ($this->params->get('interval', 5) * 60);
		// Correct value if value is under the minimum
		if ($this->interval < 300) {
			$this->interval = 300;
		}
	}

	/**
	 * Function to set last run for pseudo-cron execution
	 *
	 * @internal param $now
	 * @return bool
	 */
	private function runPseudoCron() {
		// Reference the global database object
		$now  = JFactory::getDate();
		$now  = $now->toUnix();
		$last = $this->params->get('last_run');
		$diff = $now - $last;

		if ($diff > $this->interval) {

			$version = new JVersion();
			define('J_VERSION', $version->getShortVersion());
			jimport('joomla.registry.format');
			// Set pseudo-cron last execution time as a parameter, value of now
			$this->params->set('last_run', $now);

			if (J_VERSION >= 1.6) {
				$handler = JRegistryFormat::getInstance('json');
				$params  = new JObject();
				$params->set('interval', $this->params->get('interval', 5));
				$params->set('last_run', $now);
				$params = $handler->objectToString($params, array());
				// Update plugin parameters in databaseSpelling
				$query = 'UPDATE #__extensions' .
					' SET params=' . $this->db->Quote($params) .
					' WHERE element = ' . $this->db->Quote('k2_video_hits') .
					' AND folder = ' . $this->db->Quote('system') .
					' AND enabled >= 1' .
					' AND type =' . $this->db->Quote('plugin') .
					' AND state >= 0';
				$this->db->setQuery($query);
				$this->db->query();
			} else {
				// Retrieve saved parameters from database
				$query = ' SELECT params' .
					' FROM #__plugins' .
					' WHERE element = ' . $this->db->Quote('k2_video_hits') . '';
				$this->db->setQuery($query);
				$params = $this->db->loadResult();
				// Check if last_run parameter has been recorded before.
				if (preg_match('/last_run=/', $params)) {
					// If it has been, update it.
					$params = preg_replace('/last_run=([0-9]*)/', 'last_run=' . $now, $params);
				} else {
					// Add last_run parameter to database if it has not been recorded before.
					$params = 'last_run=' . $now . "\n" . $params;
				}
				// Update plugin parameters in database
				$query = 'UPDATE #__plugins' .
					' SET params=' . $this->db->Quote($params) .
					' WHERE element = ' . $this->db->Quote('k2_video_hits') .
					' AND folder = ' . $this->db->Quote('system') .
					' AND published >= 1';
				$this->db->setQuery($query);
				$this->db->query();
			}

			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Function to return video data based on enabled parameters and detected video provider type
	 *
	 * @param $item
	 * @return mixed
	 */
	private function getVideoData($item) {
		$brightcove    = $this->params->get('brightcove');
		$providerfield = htmlspecialchars($this->params->get('providerfield'));
		$videoIdField  = htmlspecialchars($this->params->get('videoidfield'));
		$youtube       = $this->params->get('youtube');
		$videoData     = NULL;

		// If Brightcove is enabled and in the K2 provider extra field or Media source field embed code
		if ($brightcove && ((strtolower($item[$providerfield]) === 'brightcove') || strstr($item['video'], 'brightcove'))) {
			$brightcovetoken = htmlspecialchars($this->params->get('brightcovetoken'));
			if ($item[$videoIdField]) {
				$videoId = $item[$videoIdField];
			} else {
				preg_match('/@videoPlayer" value="([0-9]*)"/', $item['video'], $match);
				$videoId = $match[1];
			}
			$json               = file_get_contents('http://api.brightcove.com/services/library?command=find_video_by_id&video_id=' . $videoId . '&video_fields=playsTotal&token=' . $brightcovetoken);
			$results            = json_decode($json, TRUE);
			$videoData['views'] = $results['playsTotal'];
		}

		// If YouTube is enabled and in the Media source field embed code
		if ($youtube && ((strtolower($item[$providerfield]) === 'youtube') || strstr($item['video'], 'youtube'))) {
			if ($item[$videoIdField]) {
				$videoId = $item[$videoIdField];
			} else {
				preg_match('/\/embed\/([a-zA-Z0-9-?]*)"/', $item['video'], $match);
				$videoId = $match[1];
			}
			$json               = file_get_contents('https://gdata.youtube.com/feeds/api/videos/' . $videoId . '?v=2&alt=json');
			$results            = json_decode($json, TRUE);
			$videoData['views'] = $results['entry']['yt$statistics']['viewCount'];
		}

		return $videoData;
	}

	private function updateK2($videoData, $item) {
		if ($videoData) {
			$query = 'UPDATE #__k2_items' .
				' SET hits = ' . $videoData['views'] .
				' WHERE id = ' . $item['id'];

			$this->db->setQuery($query);
			$this->db->query();
		}
	}

	function onAfterRoute() {
		$app          = JFactory::getApplication();
		$k2categories = htmlspecialchars($this->params->get('k2category'));
		$exclusions   = htmlspecialchars($this->params->get('exclusions'));

		if ($app->isAdmin() && !$k2categories) {
			$app->enqueueMessage(JText::_('A K2 category has not been set for the k2 video hits plugin.'), 'error');
		}

		// Convert comma separated list into array while removing extra spaces
		$k2categories = explode(',', preg_replace('/\s/', '', $k2categories));
		$exclusions   = explode(',', preg_replace('/\s/', '', $exclusions));

		if ($app->isSite() && $k2categories && $this->runPseudoCron()) {
			$query = "SELECT id,extra_fields,plugins FROM #__k2_items WHERE catid IN (" . implode(',', $k2categories) . ") AND published = 1 AND checked_out = 0";
			$this->db->setQuery($query);
			$items = $this->db->loadAssocList();

			foreach ($items as $item) {
				// Check that the item isn't excluded
				if (!in_array($item['id'], $exclusions)) {

					// Elevate each K2 item extra field name and value for easier access via parameter
					if (isset($item['extra_fields'])) {
						foreach ($item['extra_fields'] as $fields) {
							$name        = $fields['name'];
							$value       = $fields['value'];
							$item[$name] = $value;
						}
					}

					// Elevate plugins data to $item object
					$pluginData = parse_ini_string($item['plugins'], FALSE, INI_SCANNER_RAW);
					foreach ($pluginData as $key => $value) {
						$item[$key] = $value;
					}
					unset($item['plugins']);

					// Retrieve video data from the provider
					$videoData = $this->getVideoData($item);

					// Update K2 with the data retrieved from the provider
					if ($videoData) {
						$this->updateK2($videoData, $item);
					}
				}
			}
		}

		return FALSE;
	}
}
