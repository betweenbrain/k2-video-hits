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
		$db   = JFactory::getDbo();
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
					' SET params=' . $db->Quote($params) .
					' WHERE element = ' . $db->Quote('k2_video_hits') .
					' AND folder = ' . $db->Quote('system') .
					' AND enabled >= 1' .
					' AND type =' . $db->Quote('plugin') .
					' AND state >= 0';
				$db->setQuery($query);
				$db->query();
			} else {
				// Retrieve saved parameters from database
				$query = ' SELECT params' .
					' FROM #__plugins' .
					' WHERE element = ' . $db->Quote('k2_video_hits') . '';
				$db->setQuery($query);
				$params = $db->loadResult();
				// Check if last_run parameter has been recorded before.
				if (preg_match('/last_run=/', $params)) {
					// If it has been, update it.
					$params = preg_replace('/last_run=([0-9]*)/', 'last_run=' . $now, $params);
				} else {
					// Add last_run parameter to database if it has not been recorded before.
					// TODO: Currently adding last_run to beginning of param string due to extra "\n" when using $params .=
					$params = 'last_run=' . $now . "\n" . $params;
				}
				// Update plugin parameters in database
				$query = 'UPDATE #__plugins' .
					' SET params=' . $db->Quote($params) .
					' WHERE element = ' . $db->Quote('k2_video_hits') .
					' AND folder = ' . $db->Quote('system') .
					' AND published >= 1';
				$db->setQuery($query);
				$db->query();
			}

			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Function to retrieve YouTube video data
	 *
	 * @param $item
	 * @return mixed
	 */
	private function getYoutubeVideoData($item, $videoid = NULL) {
		// Match part of the embed code to extract the YouTube Video ID
		if (!$videoid) {
			preg_match('/\/embed\/([a-zA-Z0-9-?]*)"/', $item['video'], $match);
			$videoid = $match[1];
		}
		// Retrieve data about this video from YouTube
		$json = file_get_contents('https://gdata.youtube.com/feeds/api/videos/' . $videoId . '?v=2&alt=json');
		// Decode the JSON results
		$results = json_decode($json, TRUE);
		// Build the videoData array from the data from YouTube
		$videoData['views'] = $results['entry']['yt$statistics']['viewCount'];

		return $videoData;
	}

	/**
	 * Function to retrieve Brightcove video data
	 *
	 * @param $item
	 * @return mixed
	 */
	private function getBrightcoveVideoData($item, $videoid = NULL) {
		// Define Brightcove API token to access video data from our account
		$brightcovetoken = htmlspecialchars($this->params->get('brightcovetoken'));
		// Match part of the embed code to extract the Brightcove Video ID
		if (!$videoid) {
			preg_match('/@videoPlayer" value="([0-9]*)"/', $item['video'], $match);
			$videoid = $match[1];
		}
		// Retrieve data about this video from Brightcove
		$json = file_get_contents('http://api.brightcove.com/services/library?command=find_video_by_id&video_id=' . $videoId . '&video_fields=name,shortDescription,longDescription,publishedDate,lastModifiedDate,videoStillURL,length,playsTotal&token=' . $brightcovetoken);
		// Decode the JSON results
		$results = json_decode($json, TRUE);
		// Build the videoData array from the data from Brightcove

		$videoData['views'] = $results['playsTotal'];

		return $videoData;
	}

	/**
	 * Function to return video data based on enabled parameters and detected video provider type
	 *
	 * @param $item
	 * @return mixed
	 */
	private function getVideoData($item) {
		$providerfield = htmlspecialchars($this->params->get('providerfield'));
		$videoid       = htmlspecialchars($this->params->get('videoid'));
		$brightcove    = $this->params->get('brightcove');
		$youtube       = $this->params->get('youtube');

		// If Brightcove is enabled and in the Media source field embed code
		if (($brightcove) && (strcasecmp($providerfield, "brightcove") || strstr($item['video'], 'brightcove'))) {
			$videoData = $this->getBrightcoveVideoData($item, $videoid);
		} // If YouTube is enabled and in the Media source field embed code
		elseif (($youtube) && (strcasecmp($providerfield, "youtube") || strstr($item['video'], 'youtube'))) {
			$videoData = $this->getYoutubeVideoData($item, $videoid);
		} // In case the above conditions are not met, set videoData as NULL so we can bail out of updating the database
		else {
			$videoData = NULL;
		}

		return $videoData;
	}

	private function updateK2($videoData, $item) {
		if ($videoData) {
			// Reference the global database object
			$db = JFactory::getDbo();
			// Update K2 with the video data from video provider.
			$query = 'UPDATE #__k2_items' .
				' SET hits = ' . $videoData['views'] .
				' WHERE id = ' . $item['id'];

			$db->setQuery($query);
			$db->query();
		}
	}

	function onAfterRoute() {
		// Disable error reporting due to K2 issue, see http://code.google.com/p/getk2/issues/detail?id=431
		// Notice: Undefined property: JObject::$url in /components/com_k2/models/item.php on line 498
		ini_set("display_errors", 0);

		$app = JFactory::getApplication();
		// User defined set of K2 categories and exclusions to process
		$k2categories = htmlspecialchars($this->params->get('k2category'));
		$exclusions   = htmlspecialchars($this->params->get('exclusions'));

		// Display error message in back-end if K2 category parameter isn't defined
		if ($app->isAdmin() && !$k2categories) {
			// Add a message to the admin message queue
			$app->enqueueMessage(JText::_('A K2 category has not been set for the k2 video hits plugin.'), 'error');
		}

		// Convert comma separated list into array while removing extra spaces
		$k2categories = explode(',', preg_replace('/\s/', '', $k2categories));
		$exclusions   = explode(',', preg_replace('/\s/', '', $exclusions));

		if ($app->isSite() && $k2categories && $this->runPseudoCron()) {
			// JSON of all K2 items
			$json = file_get_contents(JURI::root() . '/index.php?option=com_k2&view=itemlist&layout=category&format=json');
			// Decode the results as an associative array
			$results = json_decode($json, TRUE);
			// Get the items array
			$items = $results['items'];

			foreach ($items as $item) {
				// Check that the item belongs to a category that we want to process and isn't excluded
				if ((in_array($item['category']['id'], $k2categories)) && (!in_array($item['id'], $exclusions))) {

					// Elevate each K2 item extra field name and value for easier access via parameter
					foreach ($item['extra_fields'] as $fields) {
						$name        = $fields['name'];
						$value       = $fields['value'];
						$item[$name] = $value;
					}

					// Retrieve video data from the provider
					$videoData = $this->getVideoData($item);
					// Update K2 with the data retrieved from the provider
					$this->updateK2($videoData, $item);
				}
			}
		}

		return FALSE;
	}
}
