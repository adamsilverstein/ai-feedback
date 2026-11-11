/**
 * Extends the default @wordpress/scripts config with the analytics admin page.
 *
 * Setting entry replaces wp-scripts' auto-discovery; the plugin has no
 * block.json files, so build/index.js is unchanged.
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve(__dirname, 'src/index.js'),
		analytics: path.resolve(__dirname, 'src/analytics/index.js'),
	},
};
