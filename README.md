K2 Video Hits Plugin
=================

A Joomla! 1.5/2.5 proof of concept pseudo-cron plugin to synchronize video view data from external providers with K2 item hits. 

The plugin runs as a pseudo-cron job, fired every X number of minutes, or after, when your site has traffic.

When executed, the plugin will loop through your K2 items and attempt to detect the video provider by inspecting the Media source field. When a provider is matched, the plugin then extracts the video ID from the embed code, contacts the provider and retrieves views recorded by the provider, and then updates the corresponding K2 item hits to reflect popularity as reported by the provider.

Current vieo providers include:
* YouTube
* Brightcove (MAPI token required)

Pseduo-cron[https://github.com/betweenbrain/pseudocron-plugin] functionality based on the FeedGator pseudo-cron plugin by Matt Faulds of Trafalgar Design (UK) Ltd.