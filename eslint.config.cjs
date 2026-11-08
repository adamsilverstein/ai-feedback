const wpScriptsConfig = require('@wordpress/scripts/config/eslint.config.cjs');

module.exports = [
	...wpScriptsConfig,
	{
		rules: {
			'jsdoc/no-undefined-types': [
				'error',
				{ definedTypes: ['JSX', 'Element'] },
			],
		},
	},
	{
		files: ['tests/e2e/**/*.js'],
		rules: {
			'import/no-extraneous-dependencies': 'off',
			// Playwright fixtures call `use()`, which is not a React hook.
			'react-hooks/rules-of-hooks': 'off',
		},
	},
];
